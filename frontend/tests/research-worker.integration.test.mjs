import test from 'node:test';
import assert from 'node:assert/strict';
import { researchWorker, limitedJson } from '../src/lib/research-worker.mjs';

const key = 'synthetic-private-worker-'.repeat(3);
const id = 'a'.repeat(64), ticket = 'b'.repeat(64);
const env = { RESEARCH_WORKER_KEY: key, RESEARCH_WORKER_SNAPSHOT_ID: 'snap_test123', RESEARCH_WORKER_RUNTIME_DIGEST: 'sha256:' + 'c'.repeat(64), LARAVEL_API_URL: 'https://backend.example/api/v1' };
const request = (operation, body = { id, ticket }, secret = key) => new Request('https://frontend.example/api/research-worker/' + operation, { method: operation === 'health' ? 'GET' : 'POST', headers: { Authorization: 'Bearer ' + secret }, ...(operation === 'health' ? {} : { body: JSON.stringify(body) }) });

function fixture({ authorize = true, commandExit = 0 } = {}) {
  const calls = [];
  const sandbox = {
    async writeFiles(files) { calls.push(['write', files]); },
    async runCommand(command) { calls.push(['run', command]); return { exitCode: commandExit }; },
    async readFileToBuffer() { return Buffer.from(JSON.stringify({ text: 'Extracted controlled text', pages: 1, ocr: false })); },
    async stop() { calls.push(['stop']); },
  };
  const Sandbox = { async create(options) { calls.push(['create', options]); return sandbox; }, async get(options) { calls.push(['get', options]); return sandbox; } };
  const Snapshot = { async get(options) { calls.push(['snapshot', options]); return { status: 'created' }; } };
  const fetcher = async (url, options) => {
    calls.push(['fetch', url, options]);
    if (url.endsWith('/input')) return Response.json({ operation: 'extract', payload: { data: 'x'.repeat(5000000) } });
    if (url.endsWith('/authorize')) return Response.json({ authorized: authorize }, { status: authorize ? 200 : 409 });
    return Response.json({ stored: true });
  };
  return { calls, handler: researchWorker({ Sandbox, Snapshot, env, fetcher }) };
}

test('worker refuses missing/wrong credentials before inspecting runtime or creating VMs', async () => {
  const f = fixture();
  assert.equal((await f.handler(request('health', {}, 'wrong'), 'health')).status, 401);
  assert.equal(f.calls.length, 0);
});

test('health checks an accessible snapshot, reports microVM isolation, and does not claim execution', async () => {
  const f = fixture();
  const r = await f.handler(request('health'), 'health');
  const body = await r.json();
  assert.equal(r.status, 200); assert.equal(body.runtime_checked, true); assert.equal(body.execution_verified, false);
  assert.equal(body.document_processing, 'isolated_microvm'); assert.equal(body.snapshot_id, env.RESEARCH_WORKER_SNAPSHOT_ID);
  assert.equal(f.calls.filter(x => x[0] === 'create').length, 0);
});

test('large private input/result use scoped backend transfers and only an offline disposable VM executes', async () => {
  const f = fixture();
  const r = await f.handler(request('extract'), 'extract');
  assert.deepEqual(await r.json(), { stored: true });
  const opts = f.calls.find(x => x[0] === 'create')[1];
  assert.equal(opts.networkPolicy, 'deny-all'); assert.equal(opts.persistent, false); assert.deepEqual(opts.env, {}); assert.deepEqual(opts.ports, []);
  assert.equal(opts.source.snapshotId, env.RESEARCH_WORKER_SNAPSHOT_ID);
  assert.ok(f.calls.find(x => x[0] === 'write')[1][0].content.length > 4500000);
  const callbacks = f.calls.filter(x => x[0] === 'fetch');
  assert.ok(callbacks.every(x => x[1].startsWith('https://backend.example/api/v1/internal/research-worker/transfers/' + id)));
  assert.ok(callbacks.every(x => x[2].headers.Authorization === 'Bearer ' + ticket && x[2].redirect === 'error'));
  assert.ok(f.calls.findIndex(x => x[0] === 'create') < f.calls.findIndex(x => x[0] === 'fetch' && x[1].endsWith('/authorize')));
  assert.equal(f.calls.at(-1)[0], 'stop');
});

test('cancellation fences delayed submission after VM creation and stops before running code', async () => {
  const f = fixture({ authorize: false });
  assert.equal((await f.handler(request('extract'), 'extract')).status, 503);
  assert.equal(f.calls.some(x => x[0] === 'run'), false); assert.equal(f.calls.at(-1)[0], 'stop');
});

test('processing errors stop the VM and never post a successful result', async () => {
  const f = fixture({ commandExit: 1 });
  const r = await f.handler(request('extract'), 'extract');
  assert.equal(r.status, 503); assert.equal(f.calls.at(-1)[0], 'stop');
  assert.equal(f.calls.some(x => x[0] === 'fetch' && x[1].endsWith('/result')), false);
  assert.doesNotMatch(await r.text(), /synthetic|Bearer|snapshot|backend\.example/);
});

test('cancel never resumes an existing or stopped VM', async () => {
  const f = fixture(); const execution_id = 'research-' + 'e'.repeat(32);
  assert.equal((await f.handler(request('cancel', { execution_id }), 'cancel')).status, 200);
  assert.deepEqual(f.calls.find(x => x[0] === 'get')[1], { name: execution_id, resume: false });
  assert.equal(f.calls.some(x => x[0] === 'create'), false);
});

test('bounded parsing rejects oversized bodies even without Content-Length', async () => {
  await assert.rejects(() => limitedJson(new Response('x'.repeat(2050)), 2048), /body_limit/);
});
