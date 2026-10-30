"""Only operator-approved, digest-pinned, network-isolated GPU containers run."""
from __future__ import annotations
import hashlib
import hmac
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
from . import mesh

CATALOG = json.loads((Path(__file__).parent / 'catalog.json').read_text())


def receipt(engine):
    known = next((e for e in CATALOG['engines'] if e['id'] == engine), None)
    if known is None: raise ValueError('Unknown engine')
    root = Path(os.environ.get('STUDIO_INSTALL_ROOT', '/var/lib/openmind-studio-installs')).resolve()
    item = root / (engine + '.json')
    key = os.environ.get('STUDIO_INSTALL_KEY', '')
    if len(key) < 32 or not item.is_file() or item.is_symlink(): raise ValueError('Installation not attested')
    envelope = json.loads(item.read_text()); record = envelope['record']
    signed = json.dumps(record, sort_keys=True, separators=(',', ':')).encode()
    if not hmac.compare_digest(hmac.new(key.encode(), signed, hashlib.sha256).hexdigest(), envelope['signature']):
        raise ValueError('Invalid installation attestation')
    if record.get('revision') != known['revision'] or record.get('license_approved') is not True:
        raise ValueError('Unreviewed engine revision/license')
    if not re.fullmatch(r'[a-zA-Z0-9./:_-]+@sha256:[a-f0-9]{64}', record.get('image', '')):
        raise ValueError('GPU image must use an immutable digest')
    weights = Path(record['weights']).resolve(strict=True)
    if not weights.is_dir() or not record.get('files'): raise ValueError('Weights missing')
    for name, expected in record['files'].items():
        if not isinstance(name, str) or name.startswith('/') or '..' in name.split('/') or '\\' in name: raise ValueError('Unsafe weight path')
        path = weights / name
        if path.is_symlink() or not path.resolve(strict=True).is_relative_to(weights) or not path.is_file(): raise ValueError('Unsafe weight file')
        # Full hashing is performed when attesting. At runtime compare metadata;
        # the weights volume must be administrator-owned and read-only.
        if not isinstance(expected, dict) or path.stat().st_size != expected.get('bytes') or path.stat().st_mtime_ns != expected.get('mtime_ns'):
            raise ValueError('Weight integrity metadata changed; re-attest')
        if not re.fullmatch('[a-f0-9]{64}', expected.get('sha256', '')): raise ValueError('Weight checksum missing')
    return known, record


def hardware():
    try:
        run = subprocess.run(['nvidia-smi', '--query-gpu=memory.total', '--format=csv,noheader,nounits'], capture_output=True, text=True, timeout=5, check=True)
        sizes = [int(x.strip()) / 1024 for x in run.stdout.splitlines()]
        return {'backend': 'cuda', 'vram_gb': max(sizes), 'tested': False}
    except (OSError, ValueError, subprocess.SubprocessError):
        return {'backend': 'none', 'vram_gb': 0, 'tested': False}


def require_ready(engine, smoke=False):
    known, record = receipt(engine)
    gpu = hardware()
    if gpu['backend'] != 'cuda' or gpu['vram_gb'] < known['vram_gb']: raise ValueError('Compatible CUDA worker required')
    subprocess.run(['docker', 'image', 'inspect', record['image']], capture_output=True, timeout=10, check=True)
    if not smoke and not record.get('smoke_verified'): raise ValueError('Real generation smoke required')
    return known, record


def capabilities():
    engines = []
    for known in CATALOG['engines']:
        result = {'id': known['id'], 'revision': known['revision'], 'ready': False, 'smoke_verified': False, 'license_approved': False}
        try:
            _, record = require_ready(known['id'])
            result.update(ready=True, smoke_verified=True, license_approved=True)
        except (ValueError, KeyError, OSError, subprocess.SubprocessError):
            pass
        engines.append(result)
    return {'hardware': hardware(), 'engines': engines}


def parameters(known, params):
    schemas = {p['id']: p for p in known['parameters']}
    if set(params) - set(schemas): raise ValueError('Unknown model parameter')
    resolved = {}
    for name, spec in schemas.items():
        value = params.get(name, spec.get('default'))
        if spec['type'] == 'select':
            if value not in [o['value'] for o in spec['options']]: raise ValueError('Unsupported model option')
        elif spec['type'] in {'int', 'float'}:
            if type(value) not in (int, float) or (spec['type'] == 'int' and type(value) is not int) or not spec.get('min', value) <= value <= spec.get('max', value):
                raise ValueError('Model parameter outside approved range')
        else: raise ValueError('Unsupported model schema')
        resolved[name] = value
    return resolved


def generate(engine, image, params, smoke=False, job_id=None):
    known, record = require_ready(engine, smoke=smoke)
    settings = parameters(known, params)
    # Model runtime has no network, writable root, Docker socket or host paths.
    with tempfile.TemporaryDirectory(prefix='studio-gpu-') as directory:
        root = Path(directory); incoming = root / 'input'; outgoing = root / 'output'
        incoming.mkdir(); outgoing.mkdir()
        (incoming / 'source').write_bytes(image)
        (incoming / 'request.json').write_text(json.dumps({'engine': engine, 'params': settings}))
        name = 'openmind-studio-' + (job_id or root.name.removeprefix('studio-gpu-'))
        command = ['docker', 'run', '--rm', '--name', name, '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--pids-limit', '128', '--memory', '24g', '--cpus', '4', '--gpus', 'device=0', '--user', f'{os.getuid()}:{os.getgid()}', '--tmpfs', '/tmp:rw,noexec,nosuid,size=1g', '-v', f'{incoming}:/input:ro', '-v', f'{outgoing}:/output:rw', '-v', f'{Path(record["weights"]).resolve()}:/models:ro', record['image']]
        try:
            subprocess.run(command, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=1500)
            path = outgoing / 'mesh.glb'
            if not path.is_file() or path.is_symlink() or path.stat().st_size > mesh.MAX_BYTES: raise ValueError('Invalid generated artifact')
            result, metadata = mesh.execute(path.read_bytes(), 'glb', 'import', {})
            metadata.update(engine=engine, revision=known['revision'], weight_revision=known['weight_revision'])
            return result, metadata
        finally:
            subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)


def cleanup(job):
    if not re.fullmatch('[a-f0-9-]{36}', job): return False
    name = 'openmind-studio-' + job
    try:
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        result = subprocess.run(['docker', 'ps', '-a', '--filter', 'name=^/' + name + '$', '--format', '{{.ID}}'], capture_output=True, text=True, timeout=5)
        return result.returncode == 0 and result.stdout.strip() == ''
    except (OSError, subprocess.SubprocessError):
        return False
