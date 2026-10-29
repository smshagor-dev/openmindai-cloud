import base64
import hashlib
import hmac
import json
import tempfile
import time
import unittest
import uuid
import os
import threading
from unittest.mock import patch
from pathlib import Path
import struct
import trimesh
from fastapi.testclient import TestClient
from . import mesh
from .server import create_app
from .extensions import parameters, CATALOG


class MeshTests(unittest.TestCase):
    def setUp(self):
        self.original = trimesh.creation.icosphere(subdivisions=2)
        self.data = self.original.export(file_type='glb')

    def test_real_exports_reopen_and_preserve_transforms(self):
        scene = trimesh.Scene()
        transform = trimesh.transformations.translation_matrix([5, 1, 0])
        scene.add_geometry(self.original, transform=transform)
        data = scene.export(file_type='glb')
        for fmt in sorted(mesh.FORMATS):
            with self.subTest(fmt=fmt):
                output, stats = mesh.execute(data, 'glb', 'export', {'format': fmt})
                loaded = mesh.load(output, fmt).to_mesh()
                self.assertEqual(stats['faces'], 320)
                self.assertAlmostEqual(float(loaded.bounds[0][0]), 4, places=4)
                if fmt == 'stl':
                    # Independent binary STL count check, outside the mesh loader.
                    self.assertEqual(struct.unpack('<I', output[80:84])[0], 320)
                    self.assertEqual(len(output), 84 + 50 * 320)
                if fmt == 'obj':
                    self.assertEqual(sum(line.startswith(b'f ') for line in output.splitlines()), 320)

    def test_editing_changes_real_geometry(self):
        data, stats = mesh.execute(self.data, 'glb', 'decimate', {'ratio': .5})
        self.assertEqual(stats['faces'], 160)
        data, _ = mesh.execute(self.data, 'glb', 'transform', {'position': [3, 0, 0]})
        self.assertAlmostEqual(mesh.load(data, 'glb').to_mesh().bounds[0][0], 2)
        smooth, _ = mesh.execute(self.data, 'glb', 'smooth', {'iterations': 5})
        self.assertNotEqual(smooth, self.data)
        repaired, stats = mesh.execute(self.data, 'glb', 'repair', {})
        self.assertTrue(stats['watertight'])

    def test_external_resources_and_invalid_geometry_fail_closed(self):
        bad = {'asset': {'version': '2.0'}, 'buffers': [{'uri': 'file:///etc/passwd', 'byteLength': 10}]}
        for uri in ['file:///etc/passwd', 'http://127.0.0.1/admin', '../mesh.bin']:
            bad['buffers'][0]['uri'] = uri
            with self.assertRaises(ValueError): mesh.load(json.dumps(bad).encode(), 'gltf')
        with self.assertRaises(ValueError): mesh.load(b'mtllib /etc/passwd\nv 0 0 0', 'obj')
        with self.assertRaises(ValueError): mesh.execute(self.data, 'glb', 'transform', {'scale': [0, 1, 1]})
        with self.assertRaises(ValueError): mesh.execute(self.data, 'glb', 'smooth', {'iterations': 999999})
        with self.assertRaises(ValueError): mesh.load(b'glTF' + b'\0' * 40, 'glb')

    def test_extension_parameters_are_manifest_bounded(self):
        known = CATALOG['engines'][0]
        values = parameters(known, {})
        self.assertEqual(values['seed'], -1)
        with self.assertRaises(ValueError): parameters(known, {'octree_resolution': 1000000})
        with self.assertRaises(ValueError): parameters(known, {'shell': 'unsafe'})

    def test_native_scene_visibility_transforms_and_composition(self):
        node = {'id': 'body', 'label': 'Body', 'shape': 'box', 'position': [3, 0, 0], 'rotation': [0, 0, 0], 'scale': [2, 1, 1], 'color': '#4488ff'}
        document = {'title': 'Robot', 'description': 'Test', 'nodes': [node, {**node, 'id': 'hidden', 'hidden': True}], 'edges': []}
        source = json.dumps(document).encode()
        output, stats = mesh.execute(source, 'json', 'export', {'format': 'glb'})
        self.assertEqual(stats['faces'], 12)
        self.assertAlmostEqual(mesh.load(output, 'glb').bounds[0][0], 2)
        combined, stats = mesh.compose(json.dumps([{'data': base64.b64encode(output).decode(), 'format': 'glb'}] * 2).encode())
        self.assertEqual(stats['faces'], 24)
        self.assertTrue(combined.startswith(b'glTF'))
        document['nodes'][1]['hidden'] = 'false'
        with self.assertRaises(ValueError): mesh.load(json.dumps(document).encode(), 'json')


class WorkerTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.key = 'test-worker-key-' + 'a' * 32
        self.app = create_app(self.key, Path(self.temp.name))
        self.client = TestClient(self.app)

    def tearDown(self):
        self.app.state.worker.executor.shutdown(wait=True)
        self.client.close(); self.temp.cleanup()

    def signed(self, method, path, payload=None, nonce=None):
        body = json.dumps(payload, separators=(',', ':')).encode() if payload is not None else b''
        stamp = str(int(time.time())); nonce = nonce or uuid.uuid4().hex
        msg = '\n'.join([method, path, stamp, nonce, hashlib.sha256(body).hexdigest()])
        return self.client.request(method, path, content=body, headers={'Content-Length': str(len(body)), 'Content-Type': 'application/json', 'X-Studio-Time': stamp, 'X-Studio-Nonce': nonce, 'X-Studio-Signature': hmac.new(self.key.encode(), msg.encode(), hashlib.sha256).hexdigest()})

    def payload(self):
        data = trimesh.creation.box().export(file_type='glb')
        return {'id': str(uuid.uuid4()), 'engine': 'cpu', 'operation': 'transform', 'input': {'data': base64.b64encode(data).decode(), 'format': 'glb', 'sha256': hashlib.sha256(data).hexdigest()}, 'params': {'position': [3, 0, 0]}}

    def wait(self, job):
        for _ in range(150):
            result = self.signed('GET', '/jobs/' + job).json()
            if result['status'] in {'completed', 'failed', 'cancelled'}: return result
            time.sleep(.05)
        self.fail('Worker did not finish bounded test')

    def test_hmac_authentication_and_replay_protection(self):
        self.assertEqual(self.client.get('/health').status_code, 401)
        nonce = uuid.uuid4().hex
        health = self.signed('GET', '/health', nonce=nonce)
        self.assertEqual(health.status_code, 200)
        self.assertTrue(health.json()['ready'])
        self.assertFalse(any(e['ready'] for e in health.json()['engines']))
        self.assertEqual(self.signed('GET', '/health', nonce=nonce).status_code, 409)

    def test_durable_actual_job_and_idempotency_and_checksum(self):
        payload = self.payload()
        self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 202)
        self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 202)
        result = self.wait(payload['id'])
        self.assertEqual(result['status'], 'completed', result)
        artifact = self.signed('GET', '/jobs/' + payload['id'] + '/artifact').json()
        data = base64.b64decode(artifact['data'])
        self.assertEqual(hashlib.sha256(data).hexdigest(), artifact['sha256'])
        self.assertAlmostEqual(mesh.load(data, 'glb').to_mesh().bounds[0][0], 2.5)
        payload['params'] = {'position': [4, 0, 0]}
        self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 409)

    def test_unverified_gpu_never_produces_placeholder(self):
        payload = self.payload(); payload.update(engine='hunyuan3d-mini', operation='generate', params={})
        self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 422)
        self.assertEqual(self.signed('GET', '/jobs/' + payload['id']).status_code, 404)

    def test_idempotent_retry_survives_storage_pressure(self):
        payload = self.payload()
        self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 202)
        self.assertEqual(self.wait(payload['id'])['status'], 'completed')
        with patch.dict(os.environ, {'STUDIO_WORKER_STORAGE_BYTES': '1'}):
            self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 202)
            self.assertEqual(self.signed('POST', '/jobs', self.payload()).status_code, 507)

    def test_cancelled_queued_job_never_executes_or_exports(self):
        gate = threading.Event()
        blocker = self.app.state.worker.executor.submit(gate.wait)
        try:
            payload = self.payload()
            self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 202)
            result = self.signed('POST', '/jobs/' + payload['id'] + '/cancel', {})
            self.assertEqual(result.json()['status'], 'cancelled')
        finally:
            gate.set()
            blocker.result(timeout=5)
        self.app.state.worker.executor.submit(lambda: None).result(timeout=5)
        self.assertEqual(self.signed('GET', '/jobs/' + payload['id']).json()['status'], 'cancelled')
        self.assertEqual(self.signed('GET', '/jobs/' + payload['id'] + '/artifact').status_code, 409)

    def test_restart_marks_interrupted_work_failed(self):
        from .server import State
        job = str(uuid.uuid4())
        with self.app.state.worker.db() as db:
            db.execute('INSERT INTO jobs VALUES (?,?,?,?,NULL,NULL,?)', (job, 'hash', 'running', '{}', time.time()))
        recovered = State(Path(self.temp.name))
        try:
            self.assertEqual(recovered.get(job)['status'], 'failed')
            self.assertEqual(recovered.get(job)['error'], 'worker_interrupted')
        finally:
            recovered.executor.shutdown(wait=True)

    def test_cancellation_at_artifact_publication_cannot_complete(self):
        payload = self.payload()
        replace = Path.replace
        def cancel_before_publication(path, target):
            result = replace(path, target)
            if path.name == payload['id'] + '.partial':
                with self.app.state.worker.db() as db:
                    db.execute("UPDATE jobs SET status='cancel_requested' WHERE id=?", (payload['id'],))
            return result
        with patch.object(Path, 'replace', cancel_before_publication):
            self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 202)
            self.assertEqual(self.wait(payload['id'])['status'], 'cancelled')
        self.assertEqual(self.signed('GET', '/jobs/' + payload['id'] + '/artifact').status_code, 409)
        self.assertFalse((Path(self.temp.name) / (payload['id'] + '.artifact')).exists())

    def test_invalid_checksum_and_mesh_report_failure(self):
        payload = self.payload(); payload['input']['sha256'] = '0' * 64
        self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 422)
        bad = b'invalid mesh'; payload['input'] = {'data': base64.b64encode(bad).decode(), 'sha256': hashlib.sha256(bad).hexdigest(), 'format': 'glb'}
        self.assertEqual(self.signed('POST', '/jobs', payload).status_code, 202)
        self.assertEqual(self.wait(payload['id'])['status'], 'failed')

if __name__ == '__main__': unittest.main()
