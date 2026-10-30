"""Trusted per-job entrypoint. Runs only in the pinned offline Firecracker VM."""
import base64
import ctypes
import hashlib
import importlib.metadata
import json
import os
from pathlib import Path
import resource
import signal
import subprocess
import sys
import tempfile
import time

PYTHON = '/opt/research/venv/bin/python'


def nobody():
    os.setgroups([])
    os.setgid(65534)
    os.setuid(65534)
    if ctypes.CDLL(None, use_errno=True).prctl(38, 1, 0, 0, 0) != 0:
        raise RuntimeError('no_new_privileges_unavailable')
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_NPROC, (64, 64))
    resource.setrlimit(resource.RLIMIT_NOFILE, (96, 96))
    resource.setrlimit(resource.RLIMIT_FSIZE, (24000000, 24000000))
    resource.setrlimit(resource.RLIMIT_CPU, (110, 110))
    os.umask(0o077)


def run_child(args, timeout, payload=None):
    """Bound output before reading it into the trusted parent; kill child trees."""
    with tempfile.TemporaryFile() as out, tempfile.TemporaryFile() as err:
        child = subprocess.Popen(args, cwd='/work', stdin=subprocess.PIPE if payload is not None else subprocess.DEVNULL,
                                 stdout=out, stderr=err, preexec_fn=nobody, start_new_session=True,
                                 env={'PATH': '/opt/research/venv/bin:/usr/bin:/bin', 'HOME': '/tmp', 'MPLCONFIGDIR': '/tmp/matplotlib',
                                      'OMP_NUM_THREADS': '1', 'OPENBLAS_NUM_THREADS': '1'})
        if payload is not None:
            child.stdin.write(json.dumps(payload).encode())
            child.stdin.close()
        start = time.monotonic()
        try:
            while child.poll() is None:
                if time.monotonic() - start > timeout or os.fstat(out.fileno()).st_size + os.fstat(err.fileno()).st_size > 27000000:
                    os.killpg(child.pid, signal.SIGKILL)
                    child.wait()
                    raise ValueError('execution_timeout_or_output_limit')
                time.sleep(.05)
        finally:
            try:
                os.killpg(child.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            child.wait()
        out.seek(0)
        err.seek(0)
        return child.returncode, out.read(27000000), err.read(500000)


def experiment(payload):
    code = payload['code']
    if len(code) > 30000 or hashlib.sha256(code.encode()).hexdigest() != payload['code_hash']:
        raise ValueError('code_checksum')
    datasets = payload['datasets']
    if len(datasets) > 10 or sum(len(x['data']) for x in datasets) > 12000000:
        raise ValueError('input_limit')
    root = Path('/work')
    (root/'input').mkdir()
    (root/'output').mkdir(mode=0o777)
    os.chmod(root/'output', 0o777)
    inputs = []
    for item in datasets:
        name = item['name']
        if not name or Path(name).name != name or '\\' in name or name.startswith('.'):
            raise ValueError('dataset_name')
        raw = base64.b64decode(item['data'], validate=True)
        if hashlib.sha256(raw).hexdigest() != item['sha256']:
            raise ValueError('dataset_checksum')
        (root/'input'/name).write_bytes(raw)
        inputs.append({'name': name, 'sha256': item['sha256'], 'bytes': len(raw)})
    seed = payload['seed']
    if not isinstance(seed, int) or not 0 <= seed <= 2147483647:
        raise ValueError('seed')
    (root/'experiment.py').write_text(code)
    (root/'runner.py').write_text('import random,runpy,numpy as np\nrandom.seed('+str(seed)+')\nnp.random.seed('+str(seed)+')\nrunpy.run_path("/work/experiment.py",run_name="__main__")\n')
    started = time.monotonic()
    exit_code, stdout, stderr = run_child([PYTHON, '-I', '/work/runner.py'], 50)
    artifacts = []
    total = 0
    for file in (root/'output').rglob('*'):
        if file.is_symlink() or not file.is_file():
            continue
        size = file.stat().st_size
        if len(artifacts) >= 20 or size > 8000000 or total + size > 12000000:
            raise ValueError('artifact_limit')
        raw = file.read_bytes()
        total += len(raw)
        artifacts.append({'name': str(file.relative_to(root/'output')), 'data': base64.b64encode(raw).decode(), 'sha256': hashlib.sha256(raw).hexdigest()})
    return {'exit_code': exit_code, 'stdout': stdout[:500000].decode(errors='replace'), 'stderr': stderr[:500000].decode(errors='replace'),
            'artifacts': artifacts, 'elapsed_seconds': time.monotonic()-started,
            'dependencies': {name: importlib.metadata.version(name) for name in ['numpy', 'pandas', 'scipy', 'matplotlib', 'scikit-learn']},
            'provenance': {'execution_id': payload['execution_id'], 'code_hash': payload['code_hash'], 'seed': seed,
                           'bootstrap_hash': hashlib.sha256(Path(__file__).read_bytes()).hexdigest(), 'executed_at': time.time(), 'inputs': inputs}}


def main():
    source, destination = map(Path, sys.argv[1:3])
    manifest = json.loads(Path('/opt/openmind/runtime/research/runtime-manifest.json').read_text())
    if manifest['digest'] != sys.argv[3]:
        raise ValueError('runtime_identity_mismatch')
    if source.stat().st_size > 24000000:
        raise ValueError('input_limit')
    request = json.loads(source.read_text())
    os.mkdir('/work', 0o755)
    if request['operation'] == 'experiment':
        result = experiment(request['payload'])
    elif request['operation'] in ['extract', 'export']:
        code, output, _ = run_child([PYTHON, '-I', '/opt/openmind/runtime/research/cli.py'], 130, request)
        if code:
            raise ValueError('document_processing_failed')
        result = json.loads(output)
    else:
        raise ValueError('operation')
    raw = json.dumps(result, ensure_ascii=False).encode()
    if len(raw) > 28000000:
        raise ValueError('result_limit')
    destination.write_bytes(raw)


if __name__ == '__main__':
    main()
