"""Private HMAC-authenticated, durable, bounded worker. Not a public browser API."""
from __future__ import annotations
import base64
from concurrent.futures import ThreadPoolExecutor
import hashlib
import hmac
import json
import multiprocessing
import os
from pathlib import Path
import queue
import re
import sqlite3
import time
import uuid
from fastapi import FastAPI, HTTPException, Request
from fastapi.responses import JSONResponse
from . import mesh


def child_execute(output, payload):
    try:
        if os.name == 'posix':
            import resource
            resource.setrlimit(resource.RLIMIT_CPU, (90, 90))
            resource.setrlimit(resource.RLIMIT_AS, (8 * 1024**3, 8 * 1024**3))
            resource.setrlimit(resource.RLIMIT_FSIZE, (6_000_000, 6_000_000))
        data = base64.b64decode(payload['input']['data'], validate=True)
        if hashlib.sha256(data).hexdigest() != payload['input']['sha256']:
            raise ValueError('Input checksum mismatch')
        if payload['operation'] == 'generate':
            from .extensions import generate
            result, metadata = generate(payload['engine'], data, payload['params'], job_id=payload['id'])
        elif payload['operation'] == 'compose':
            result, metadata = mesh.compose(data)
        elif payload['operation'] == 'preprocess':
            import io
            from PIL import Image
            if set(payload['params']) - {'size'}:
                raise ValueError('Unknown preprocessing parameter')
            size = payload['params'].get('size', 768)
            if type(size) is not int or not 64 <= size <= 1024:
                raise ValueError('Invalid image size')
            with Image.open(io.BytesIO(data)) as image:
                if image.width * image.height > 16_777_216 or image.format not in {'PNG', 'JPEG', 'WEBP'}:
                    raise ValueError('Invalid image')
                image.thumbnail((size, size))
                target = io.BytesIO(); image.convert('RGB').save(target, format='PNG')
                result, metadata = target.getvalue(), {'format': 'png', 'kind': 'image', 'width': image.width, 'height': image.height}
        else:
            result, metadata = mesh.execute(data, payload['input']['format'], payload['operation'], payload['params'])
        if len(result) > mesh.MAX_BYTES:
            raise ValueError('Artifact too large')
        output.put(('ok', result, metadata))
    except Exception as exc:
        # Never return stack traces, absolute paths, environment or raw prompts.
        output.put(('error', type(exc).__name__))


class State:
    def __init__(self, root: Path):
        self.root = root.resolve(); self.root.mkdir(parents=True, exist_ok=True, mode=0o700)
        self.executor = ThreadPoolExecutor(max_workers=1)
        with self.db() as db:
            db.executescript('''PRAGMA journal_mode=WAL;
            CREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY, hash TEXT, status TEXT, payload TEXT, result TEXT, error TEXT, updated REAL);
            CREATE TABLE IF NOT EXISTS nonces(id TEXT PRIMARY KEY, expires REAL);''')
            db.execute("UPDATE jobs SET status='failed',error='worker_interrupted' WHERE status IN ('running','cancel_requested')")
            pending = [r['id'] for r in db.execute("SELECT id FROM jobs WHERE status='queued'")]
        for job in pending:
            self.executor.submit(self.run, job)

    def db(self):
        db = sqlite3.connect(self.root / 'state.sqlite', timeout=15)
        db.row_factory = sqlite3.Row
        return db

    def get(self, job):
        with self.db() as db:
            row = db.execute('SELECT * FROM jobs WHERE id=?', (job,)).fetchone()
        if row is None:
            raise HTTPException(404, 'Job not found')
        return dict(row)

    def update(self, job, status, result=None, error=None):
        with self.db() as db:
            db.execute('UPDATE jobs SET status=?,result=?,error=?,updated=? WHERE id=?',
                       (status, json.dumps(result) if result else None, error, time.time(), job))

    def run(self, job):
        with self.db() as db:
            changed = db.execute("UPDATE jobs SET status='running',updated=? WHERE id=? AND status='queued'", (time.time(), job)).rowcount
        if not changed:
            return
        payload = json.loads(self.get(job)['payload'])
        context = multiprocessing.get_context('spawn')
        channel = context.Queue(maxsize=1)
        process = context.Process(target=child_execute, args=(channel, payload), daemon=True)
        process.start(); deadline = time.monotonic() + (1800 if payload['operation'] == 'generate' else 120)
        try:
            while time.monotonic() < deadline:
                if self.get(job)['status'] == 'cancel_requested':
                    process.terminate(); process.join(timeout=5)
                    if process.is_alive(): process.kill(); process.join()
                    from .extensions import cleanup
                    confirmed = payload['operation'] != 'generate' or cleanup(job)
                    self.update(job, 'cancelled' if confirmed else 'failed', error=None if confirmed else 'cancellation_unconfirmed'); return
                try:
                    message = channel.get(timeout=0.2)
                except queue.Empty:
                    if not process.is_alive():
                        self.update(job, 'failed', error='worker_process_failed'); return
                    continue
                if message[0] == 'error':
                    self.update(job, 'failed', error='invalid_or_unsupported_geometry'); return
                data, metadata = message[1:]
                fmt = metadata.get('format', payload['params'].get('format', 'glb') if payload['operation'] == 'export' else 'glb')
                path = self.root / (job + '.artifact')
                temporary = path.with_suffix('.partial')
                temporary.write_bytes(data); os.chmod(temporary, 0o600); temporary.replace(path)
                self.update(job, 'completed', {'bytes': len(data), 'sha256': hashlib.sha256(data).hexdigest(), 'format': fmt, 'metadata': metadata})
                return
            self.update(job, 'failed', error='worker_timeout')
        finally:
            if process.is_alive(): process.terminate()
            process.join(timeout=5)
            if process.is_alive(): process.kill(); process.join()
            channel.close()
            if payload['operation'] == 'generate':
                from .extensions import cleanup
                cleanup(job)


def create_app(key=None, root=None):
    key = key or os.environ.get('STUDIO_WORKER_KEY', '')
    if len(key) < 32:
        raise RuntimeError('Set a dedicated STUDIO_WORKER_KEY of at least 32 characters')
    state = State(Path(root or os.environ.get('STUDIO_WORKER_STATE', '/var/lib/openmind-studio')))
    api = FastAPI(docs_url=None, redoc_url=None, openapi_url=None)
    api.state.worker = state

    @api.middleware('http')
    async def authentication(request: Request, call_next):
        now = time.time(); stamp = request.headers.get('x-studio-time', ''); nonce = request.headers.get('x-studio-nonce', '')
        if not stamp.isdigit() or abs(now - int(stamp)) > 60 or not re.fullmatch('[a-f0-9]{32}', nonce) or request.url.query:
            return JSONResponse({'error': 'unauthorized'}, status_code=401)
        length = request.headers.get('content-length', '0')
        if not length.isdigit() or int(length) > 4_500_000 or (request.method != 'GET' and int(length) == 0):
            return JSONResponse({'error': 'invalid_length'}, status_code=413)
        body = await request.body()
        if len(body) != int(length): return JSONResponse({'error': 'invalid_length'}, status_code=413)
        message = '\n'.join([request.method, request.url.path, stamp, nonce, hashlib.sha256(body).hexdigest()])
        expected = hmac.new(key.encode(), message.encode(), hashlib.sha256).hexdigest()
        if not hmac.compare_digest(expected, request.headers.get('x-studio-signature', '')):
            return JSONResponse({'error': 'unauthorized'}, status_code=401)
        try:
            with state.db() as db:
                db.execute('DELETE FROM nonces WHERE expires<?', (now,))
                db.execute('INSERT INTO nonces VALUES (?,?)', (nonce, now + 120))
        except sqlite3.IntegrityError:
            return JSONResponse({'error': 'replay'}, status_code=409)
        return await call_next(request)

    @api.get('/health')
    def health():
        from .extensions import capabilities
        return {'protocol': 1, 'ready': True, 'processors': ['import', 'inspect', 'smooth', 'decimate', 'repair', 'transform', 'export', 'preprocess', 'compose'], **capabilities()}

    @api.post('/jobs', status_code=202)
    async def submit(request: Request):
        try:
            payload = await request.json()
        except (ValueError, UnicodeError):
            raise HTTPException(422, 'Invalid JSON')
        if not isinstance(payload, dict) or set(payload) != {'id', 'engine', 'operation', 'input', 'params'}:
            raise HTTPException(422, 'Invalid worker request')
        try:
            if str(uuid.UUID(payload['id'])) != payload['id']: raise ValueError()
            if payload['operation'] not in {'generate', 'import', 'inspect', 'smooth', 'decimate', 'repair', 'transform', 'export', 'preprocess', 'compose'}: raise ValueError()
            if not isinstance(payload['params'], dict) or len(json.dumps(payload['params'])) > 16000: raise ValueError()
            source = payload['input']
            if set(source) != {'data', 'format', 'sha256'} or len(source['data']) > 4_000_000 or len(source['sha256']) != 64: raise ValueError()
            data = base64.b64decode(source['data'], validate=True)
            if not 0 < len(data) <= mesh.MAX_BYTES or hashlib.sha256(data).hexdigest() != source['sha256']: raise ValueError()
            if payload['operation'] == 'generate':
                from .extensions import require_ready
                require_ready(payload['engine'])
            elif payload['engine'] != 'cpu': raise ValueError()
        except (ValueError, KeyError, TypeError):
            raise HTTPException(422, 'Invalid input or unavailable engine')
        serialized = json.dumps(payload, sort_keys=True, separators=(',', ':')); digest = hashlib.sha256(serialized.encode()).hexdigest()
        if sum(p.stat().st_size for p in state.root.iterdir() if p.is_file()) + len(serialized) + mesh.MAX_BYTES > int(os.environ.get('STUDIO_WORKER_STORAGE_BYTES', '1000000000')):
            raise HTTPException(507, 'Worker storage is full')
        with state.db() as db:
            existing = db.execute('SELECT hash FROM jobs WHERE id=?', (payload['id'],)).fetchone()
            if existing and existing['hash'] != digest: raise HTTPException(409, 'Idempotency conflict')
            if not existing:
                if db.execute("SELECT count(*) FROM jobs WHERE status IN ('queued','running')").fetchone()[0] >= 20:
                    raise HTTPException(429, 'Worker queue is full')
                db.execute('INSERT INTO jobs VALUES (?,?,?,?,NULL,NULL,?)', (payload['id'], digest, 'queued', serialized, time.time()))
        if not existing: state.executor.submit(state.run, payload['id'])
        return public(state.get(payload['id']))

    def public(row):
        return {'id': row['id'], 'status': row['status'], 'result': json.loads(row['result']) if row['result'] else None, 'error_code': row['error']}

    @api.get('/jobs/{job}')
    def status(job: uuid.UUID):
        return public(state.get(str(job)))

    @api.post('/jobs/{job}/cancel')
    def cancel(job: uuid.UUID):
        identity = str(job)
        with state.db() as db:
            state.get(identity)
            db.execute("UPDATE jobs SET status=CASE WHEN status='queued' THEN 'cancelled' ELSE 'cancel_requested' END WHERE id=? AND status IN ('queued','running')", (identity,))
        return public(state.get(identity))

    @api.get('/jobs/{job}/artifact')
    def artifact(job: uuid.UUID):
        row = state.get(str(job))
        if row['status'] != 'completed': raise HTTPException(409, 'Artifact not ready')
        result = json.loads(row['result']); data = (state.root / (str(job) + '.artifact')).read_bytes()
        if hashlib.sha256(data).hexdigest() != result['sha256']: raise HTTPException(409, 'Artifact integrity failed')
        return {**result, 'data': base64.b64encode(data).decode()}

    return api
