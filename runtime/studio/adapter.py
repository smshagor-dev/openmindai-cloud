"""Entry point INSIDE an independently provisioned, licensed GPU image.
The image must contain reviewed /opt/modly and /opt/extension revisions and all
runtime dependencies. No installer or model download is run by OpenMind.
"""
import importlib.util
import json
from pathlib import Path
import sys
import threading


def main():
    sys.path.insert(0, '/opt/modly/api')
    sys.path.insert(0, '/opt/extension')
    from runtime.studio.extensions import CATALOG, parameters
    request = json.loads(Path('/input/request.json').read_text())
    known = next(e for e in CATALOG['engines'] if e['id'] == request['engine'])
    import hashlib
    source = Path('/opt/extension/generator.py')
    manifest_path = Path('/opt/extension/manifest.json')
    if hashlib.sha256(source.read_bytes()).hexdigest() != known['code_sha256'] or hashlib.sha256(manifest_path.read_bytes()).hexdigest() != known['manifest_sha256']:
        raise RuntimeError('Adapter source revision mismatch')
    manifest = json.loads(manifest_path.read_text())
    spec = importlib.util.spec_from_file_location('approved_studio_generator', source)
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    generator = getattr(module, manifest['generator_class'])(Path('/models'), Path('/output'))
    node = manifest['nodes'][0]
    for name in ['hf_repo', 'download_check', 'hf_skip_prefixes']:
        setattr(generator, name, node.get(name, '' if name != 'hf_skip_prefixes' else []))
    generator.MODEL_NODE_ID = 'generate'
    if not generator.is_downloaded(): raise RuntimeError('Installed weights are incomplete')
    # The container has no network and a read-only root; upstream automatic
    # repair/download code cannot install new dependencies during a user job.
    generator.load()
    output = Path(generator.generate(Path('/input/source').read_bytes(), parameters(known, request['params']), progress_cb=None, cancel_event=threading.Event())).resolve(strict=True)
    if not output.is_relative_to(Path('/output')) or output.is_symlink(): raise RuntimeError('Unsafe adapter output')
    from runtime.studio.mesh import execute
    data, _ = execute(output.read_bytes(), output.suffix.removeprefix('.').lower(), 'import', {})
    Path('/output/mesh.glb').write_bytes(data)
    generator.unload()

if __name__ == '__main__': main()
