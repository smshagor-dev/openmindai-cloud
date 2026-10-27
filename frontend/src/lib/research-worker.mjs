import { timingSafeEqual } from 'node:crypto';

const hex = /^[a-f0-9]{64}$/;
const executionId = /^research-[a-f0-9]{32}$/;
const reply = (body, status = 200) => Response.json(body, { status, headers: { 'Cache-Control': 'no-store' } });

export async function limitedJson(response, limit) {
  if (!response.body) throw new Error('empty_body');
  const reader = response.body.getReader();
  const chunks = []; let size = 0;
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.length;
      if (size > limit) throw new Error('body_limit');
      chunks.push(Buffer.from(value));
    }
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
  } finally { await reader.cancel(); }
}

/** Only the gateway holds credentials; each job gets a fresh offline microVM. */
export function researchWorker({ Sandbox, Snapshot, env = process.env, fetcher = fetch }) {
  let checkedAt = 0; let checkedSnapshot = '';
  return async (request, operation) => {
    const key = env.RESEARCH_WORKER_KEY || '';
    const provided = request.headers.get('authorization') || '';
    const expected = 'Bearer ' + key;
    if (key.length < 32 || Buffer.byteLength(provided) !== Buffer.byteLength(expected) || !timingSafeEqual(Buffer.from(provided), Buffer.from(expected))) return reply({ error_code: 'unauthorized' }, 401);
    if (!['health', 'extract', 'export', 'experiment', 'cancel'].includes(operation)) return reply({ error_code: 'not_found' }, 404);
    if ((operation === 'health' ? 'GET' : 'POST') !== request.method) return reply({ error_code: 'method_not_allowed' }, 405);
    const snapshotId = env.RESEARCH_WORKER_SNAPSHOT_ID || '';
    const digest = env.RESEARCH_WORKER_RUNTIME_DIGEST || '';
    if (!/^snap_[A-Za-z0-9]+$/.test(snapshotId) || !/^sha256:[a-f0-9]{64}$/.test(digest)) return reply({ status: 'setup_required', runtime_checked: false }, 503);
    try {
      if (operation === 'health') {
        if (checkedSnapshot !== snapshotId || Date.now() - checkedAt > 30000) {
          const snapshot = await Snapshot.get({ snapshotId, signal: AbortSignal.timeout(4000) });
          if (snapshot.status !== 'created') throw new Error('snapshot_unavailable');
          checkedSnapshot = snapshotId; checkedAt = Date.now();
        }
        return reply({ status: 'ready', runtime_checked: true, execution_verified: false, document_processing: 'isolated_microvm', experiments: 'isolated_microvm', isolation: 'firecracker_microvm', network: 'none', snapshot_id: snapshotId, runtime_digest: digest });
      }
      const body = await limitedJson(request, 2048);
      if (operation === 'cancel') {
        if (!executionId.test(body.execution_id || '')) return reply({ error_code: 'invalid_execution_id' }, 422);
        try {
          const sandbox = await Sandbox.get({ name: body.execution_id, resume: false });
          await sandbox.stop();
        } catch (error) {
          if (error?.status !== 404 && error?.statusCode !== 404) throw error;
        }
        return reply({ status: 'cancelled', execution_id: body.execution_id });
      }
      if (!hex.test(body.id || '') || !hex.test(body.ticket || '')) return reply({ error_code: 'invalid_transfer' }, 422);
      const base = new URL(env.LARAVEL_API_URL || '');
      if (base.protocol !== 'https:' || base.username || base.password || base.search || base.hash) throw new Error('backend_configuration');
      const url = base.href.replace(/\/$/, '') + '/internal/research-worker/transfers/' + body.id;
      const transfer = async (action, method = 'GET', value) => {
        const r = await fetcher(url + '/' + action, { method, headers: { Authorization: 'Bearer ' + body.ticket, Accept: 'application/json', 'Content-Type': 'application/json' }, ...(value === undefined ? {} : { body: JSON.stringify(value) }), redirect: 'error', cache: 'no-store', signal: AbortSignal.timeout(20000) });
        if (!r.ok) throw new Error('transfer_rejected');
        return limitedJson(r, action === 'input' ? 24000000 : 2048);
      };
      const input = await transfer('input');
      if (input.operation !== operation || !input.payload || typeof input.payload !== 'object') throw new Error('operation_mismatch');
      if (operation === 'experiment' && !executionId.test(input.payload.execution_id || '')) throw new Error('invalid_execution_id');
      const name = operation === 'experiment' ? input.payload.execution_id : 'research-doc-' + body.id.slice(0, 32);
      const sandbox = await Sandbox.create({ name, source: { type: 'snapshot', snapshotId }, networkPolicy: 'deny-all', persistent: false, resources: { vcpus: 1 }, timeout: 170000, env: {}, ports: [] });
      try {
        await sandbox.writeFiles([{ path: '/vercel/sandbox/request.json', content: Buffer.from(JSON.stringify(input)) }]);
        // The VM must exist before authorization: cancellation fences a delayed create.
        await transfer('authorize', 'POST');
        const result = await sandbox.runCommand({ cmd: '/opt/research/venv/bin/python', args: ['-I', '/opt/openmind/runtime/research/microvm.py', '/vercel/sandbox/request.json', '/vercel/sandbox/result.json', digest], sudo: true, timeoutMs: 140000 });
        if (result.exitCode !== 0) throw new Error('processing_failed');
        const bytes = await sandbox.readFileToBuffer({ path: '/vercel/sandbox/result.json' });
        if (!bytes || bytes.length > 28000000) throw new Error('result_limit');
        const output = JSON.parse(bytes.toString('utf8'));
        if (operation === 'experiment') {
          output.provenance = { ...output.provenance, isolation: 'firecracker_microvm', network: 'none', snapshot_id: snapshotId, runtime_digest: digest };
        }
        await transfer('result', 'POST', output);
        return reply({ stored: true });
      } finally {
        // Also stop on rejected authorization, processing failure, or callback failure.
        await sandbox.stop();
      }
    } catch {
      return reply({ error_code: 'research_worker_unavailable' }, 503);
    }
  };
}
