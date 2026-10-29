"""Operator-only installation, verification and rollback. Never routed from HTTP."""
import argparse
import hashlib
import hmac
import json
import os
from pathlib import Path
import shutil
import subprocess
from .extensions import CATALOG, generate


def signing_key():
    key = os.environ.get('STUDIO_INSTALL_KEY', '')
    if len(key) < 32: raise ValueError('A separate administrator installation key is required')
    return key.encode()


def envelope(record):
    raw = json.dumps(record, sort_keys=True, separators=(',', ':')).encode()
    return {'record': record, 'signature': hmac.new(signing_key(), raw, hashlib.sha256).hexdigest()}


def digest(path):
    sha = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(4 * 1024 * 1024), b''): sha.update(chunk)
    return sha.hexdigest()


def approve_install(engine, image, weights, approval):
    known = next(e for e in CATALOG['engines'] if e['id'] == engine)
    review = json.loads(Path(approval).read_text())
    # This file records an operator's pre-existing legal/source review. The CLI
    # does not accept terms or infer rights from a model card's MIT badge.
    if review.get('source_revision') != known['revision'] or review.get('weight_revision') != known['weight_revision'] or review.get('license_approved') is not True or not review.get('review_reference'):
        raise ValueError('Exact source/weights license review record required')
    info = json.loads(subprocess.check_output(['docker', 'image', 'inspect', image], timeout=10))[0]
    if image not in info.get('RepoDigests', []): raise ValueError('Use the inspected immutable image digest')
    labels = info.get('Config', {}).get('Labels') or {}
    if labels.get('openmind.extension.revision') != known['revision'] or labels.get('openmind.modly.revision') != CATALOG['upstream']['revision']:
        raise ValueError('Image source labels differ from reviewed pins')
    root = Path(weights).resolve(strict=True)
    files = {}
    for path in root.rglob('*'):
        if path.is_symlink(): raise ValueError('Symlink weights are not accepted')
        if path.is_file():
            stat = path.stat(); files[path.relative_to(root).as_posix()] = {'sha256': digest(path), 'bytes': stat.st_size, 'mtime_ns': stat.st_mtime_ns}
    if not files: raise ValueError('No installed weights')
    record = {'engine': engine, 'revision': known['revision'], 'weight_revision': known['weight_revision'], 'image': image, 'weights': str(root), 'files': files, 'license_approved': True, 'review_reference': review['review_reference'], 'smoke_verified': False}
    write(engine, record)


def write(engine, record):
    root = Path(os.environ.get('STUDIO_INSTALL_ROOT', '/var/lib/openmind-studio-installs'))
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    target = root / (engine + '.json')
    if target.exists(): shutil.copyfile(target, target.with_suffix('.previous'))
    temporary = target.with_suffix('.partial'); temporary.write_text(json.dumps(envelope(record), indent=2)); temporary.chmod(0o600); temporary.replace(target)


def download(engine, plan_file, destination):
    known = next(e for e in CATALOG['engines'] if e['id'] == engine)
    plan = json.loads(Path(plan_file).read_text()); record = plan['record']
    if not hmac.compare_digest(envelope(record)['signature'], plan['signature']): raise ValueError('Download plan is not signed by administrator')
    if record.get('repo') != known['weight_source'] or record.get('revision') != known['weight_revision'] or record.get('license_approved') is not True: raise ValueError('Unapproved download source/revision/license')
    variant = record.get('variant', 'default')
    allowed = ['default'] + [v['id'] for v in known.get('variants', {}).get('options', [])]
    if variant not in allowed: raise ValueError('Unknown quantization')
    root = Path(destination).resolve() / engine / known['weight_revision'] / variant
    root.mkdir(parents=True, exist_ok=True, mode=0o700)
    from huggingface_hub import hf_hub_download
    for name, expected in record['files'].items():
        if name.startswith('/') or '..' in name.split('/') or '\\' in name: raise ValueError('Unsafe model filename')
        source = Path(hf_hub_download(repo_id=record['repo'], revision=record['revision'], filename=name))
        if digest(source) != expected['sha256'] or source.stat().st_size != expected['bytes']: raise ValueError('Downloaded weight checksum mismatch')
        target = root / name; target.parent.mkdir(parents=True, exist_ok=True)
        # HF cache provides shared immutable blobs across independently installed variants.
        if not target.exists():
            try: os.link(source.resolve(), target)
            except OSError: shutil.copyfile(source, target)
        if digest(target) != expected['sha256']: raise ValueError('Installed weight checksum mismatch')
        print(json.dumps({'file': name, 'verified_bytes': target.stat().st_size}))


def main():
    parser = argparse.ArgumentParser(); parser.add_argument('action', choices=['catalog', 'attest', 'smoke', 'remove', 'rollback', 'download'])
    parser.add_argument('--engine'); parser.add_argument('--image'); parser.add_argument('--weights'); parser.add_argument('--approval'); parser.add_argument('--input'); parser.add_argument('--plan'); parser.add_argument('--destination')
    args = parser.parse_args()
    if args.action == 'catalog': print(json.dumps(CATALOG, indent=2)); return
    if args.engine not in [e['id'] for e in CATALOG['engines']]: raise ValueError('Unknown engine')
    root = Path(os.environ.get('STUDIO_INSTALL_ROOT', '/var/lib/openmind-studio-installs')); target = root / (args.engine + '.json')
    if args.action == 'attest': approve_install(args.engine, args.image, args.weights, args.approval)
    elif args.action == 'download': download(args.engine, args.plan, args.destination)
    elif args.action == 'smoke':
        from .extensions import receipt
        _, record = receipt(args.engine)
        data, metadata = generate(args.engine, Path(args.input).read_bytes(), {}, smoke=True)
        record.update(smoke_verified=True, smoke_sha256=hashlib.sha256(data).hexdigest(), smoke_faces=metadata['faces'])
        write(args.engine, record)
    elif args.action == 'remove':
        # Reversible removal of capability registration. Weights and artifacts are preserved.
        if target.exists(): target.replace(target.with_suffix('.disabled'))
    elif args.action == 'rollback':
        previous = target.with_suffix('.previous')
        if not previous.is_file(): raise ValueError('No previous installation')
        candidate = json.loads(previous.read_text())
        if not hmac.compare_digest(envelope(candidate['record'])['signature'], candidate['signature']): raise ValueError('Invalid previous attestation')
        previous.replace(target)

if __name__ == '__main__': main()
