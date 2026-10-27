# Research processing on Vercel Sandbox

The existing chat deployment can broker research processing into a new Firecracker microVM for each operation. cPanel continues to own authentication, approvals, quotas, source files, durable state and queues. The original private Docker broker remains supported through the default `http` transport.

## Runtime and configuration

Build a new disposable Vercel Sandbox from the official universal image, uploading only the reviewed research source and CSL assets. Run `runtime/research/build-microvm.sh SOURCE_ROOT`, then snapshot its filesystem. Pin the returned immutable snapshot ID and the printed runtime digest. Do not include application credentials, user uploads, `.env`, authentication state or production data in this build VM or snapshot.

Configure the existing Vercel chat project with a dedicated sensitive `RESEARCH_WORKER_KEY` (at least 32 characters), `RESEARCH_WORKER_SNAPSHOT_ID`, and `RESEARCH_WORKER_RUNTIME_DIGEST`. Its existing `LARAVEL_API_URL` remains the fixed callback destination. The SDK authenticates through the deployment's OIDC identity; do not add an account token or pass provider credentials into microVMs. Scope production credentials to production. A separately scoped preview key can be used for release verification.

Set these private Laravel values to the matching deployment:

```
RESEARCH_WORKER_TRANSPORT=vercel
RESEARCH_WORKER_URL=https://chat.openmindai.org/api/research-worker
RESEARCH_WORKER_KEY=<dedicated matching secret>
RESEARCH_WORKER_SNAPSHOT_ID=<immutable snapshot ID>
RESEARCH_WORKER_RUNTIME_DIGEST=sha256:<verified runtime manifest digest>
```

Keep a private rollback copy of existing configuration; rebuild Laravel configuration and restart queue consumers after changing it. Existing research cron lanes and the scheduler stay in place. Configure snapshot retention deliberately; an expired/deleted snapshot fails readiness rather than invoking an unsafe execution fallback. Rebuild and test a new snapshot before replacing its ID/digest together on both deployments.

## Isolation and transfers

The function accepts only compact transfer identifiers. Laravel stores payloads/results in its private directory, protected by random operation-scoped tickets, short expiry, locked transitions and one successful result. The worker retrieves input and uploads results directly to the fixed backend so large PDFs and artifacts do not traverse Vercel's function request/response size limit. Normal completion/failure removes transfer payloads; the scheduler prunes abandoned payloads after fifteen minutes. Cancellation records are retained to fence delayed experiment dispatches.

Every operation creates a fresh VM from the pinned snapshot with `persistent:false`, `networkPolicy:deny-all`, no ports, no application secrets, one vCPU, and a 170-second VM deadline. A trusted root supervisor drops generated Python/document subprocesses to UID/GID 65534, disables privilege escalation and core dumps, applies process/file/CPU limits, bounds output and kills process groups. The supervisor never runs generated code on cPanel or in the Vercel function process. The VM is stopped in a finally block on success or failure, with its independent deadline covering function termination.

Cancellation writes a backend tombstone before stopping the named VM. After VM creation, a separate authorization handshake checks that tombstone before running code. This also fences the cancel-before-create race. Experiment outputs must retain matching execution ID, approved code hash/seed, offline isolation, the configured snapshot and runtime digest before they can count as executed evidence. Existing approval, artifact checksum and research integrity gates remain in force.

## Verification

`php artisan research:preflight --fresh` checks authenticated broker reachability, accessible snapshot metadata and pinned runtime identity. It explicitly does not execute an experiment or certify export correctness. Before enabling the worker, run actual multilingual export/reopen, PDF extraction, approved Python/artifact, network rejection, timeout and cancellation tests against the isolated runtime, followed by a real Laravel-to-production-broker check. Fixture tests are not a substitute for those checks.
