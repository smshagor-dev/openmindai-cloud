# Private 3D worker operations — unreleased

This feature is not deployed. Run the CPU worker on a separate Linux host. cPanel runs Laravel and bounded queue consumers, not GPU model code. No production change is authorized by this runbook alone.

## CPU service

Build `docker build -f runtime/studio/Dockerfile -t openmind-studio-cpu .`. Run as the image's non-root UID with a persistent `/var/lib/openmind-studio` volume, read-only root filesystem, a bounded writable `/tmp`, no extra capabilities, PID/CPU/RAM limits and a private network. Set `STUDIO_WORKER_KEY` to a newly generated secret of at least 32 characters and `STUDIO_WORKER_STATE=/var/lib/openmind-studio`. Do not publish port 8000 to the public Internet. Terminate TLS on an operator-controlled private ingress and restrict source traffic to the Laravel server. Do not disable TLS verification.

Laravel requires `STUDIO_WORKER_URL` (the fixed HTTPS origin) and the matching `STUDIO_WORKER_KEY`. These are server configuration, never browser values. The URL has no per-user override. Requests sign method, path, timestamp, nonce and body hash. A signed `/health` response is required before CPU tools become available. Keep one worker API process: SQLite state and one execution slot provide bounded durable processing; horizontal scheduling is not implemented.

Run the additive application migration normally, after the usual verified database backup. Do not reset or reseed production. The new tables hold projects, assets, workflow definitions, jobs, events and scene revisions. Retain the existing application storage volume and its ownership. Originals and outputs stay on Laravel's private local disk, with per-user quota checks and checksums.

A deployment must also provision a bounded Laravel consumer for queue `studio`, separate from Research. For example, use `php artisan queue:work database --queue=studio --stop-when-empty --max-time=50 --timeout=180 --tries=1` under the host's existing overlap prevention/monitoring mechanism. A supervised long-lived consumer is preferred on a worker-capable host. Match process and hosting limits; a procedural gateway call may take up to 150 seconds, so a shared-host cron timeout shorter than that cannot run procedural workflows reliably. The scheduled `studio:recover` scan only re-enqueues expired claims; it does not replace the consumer. Do not change Research's consumer or provider admission settings.

## GPU engines

The CPU image intentionally contains no third-party extension code, weights, Torch or Docker socket. Model catalog presence never means installation. GPU adapters require compatible CUDA hardware, separately reviewed extension/model rights, exact source revisions, a digest-pinned operator-built runtime image, pinned and checksummed weights, a separate installation signing key and an actual successful generation smoke.

Inspect `python -m runtime.studio.manage --help` and each subcommand's help on the GPU administration host. `catalog` reports the allowlist; `download` accepts only a signed, reviewed exact-file plan; `attest` registers the approved immutable image and weights; `smoke` performs real inference before availability; `remove` quarantines the registration without deleting shared weights; `rollback` restores the previous signed registration. These are administrator CLI operations and have no ordinary-user web endpoint. Do not run upstream automatic setup/install code in cPanel or a request handler.

The GPU orchestration account must have permission to run its restricted containers. Docker access is equivalent to privileged host administration: keep it on a dedicated GPU host, separate from the public web application. Inference containers receive no network, a read-only root and weight/input mounts, a writable private output mount, bounded memory/CPU/PIDs and explicit GPU selection. The GPU runtime image and all required dependency builds must be operator-provisioned; this repository does not certify an image or silently install them.

## Verification and recovery

### Release preflight

Run `php artisan studio:preflight` after additive migrations and private worker configuration. A nonzero exit means the CPU infrastructure is not ready. `php artisan studio:preflight --require-gpu` additionally requires at least one catalog engine with verified installation, approved rights and actual inference smoke. Output contains booleans and engine IDs, never worker keys, URLs or request content. These checks inspect schema and signed capabilities; they do not certify queue consumer liveness or replace a real user workflow.

`runtime/studio/compose.yaml` supplies a bounded non-root CPU deployment with persistent state, read-only root, loopback-only ingress and rotated logs. Supply `STUDIO_CPU_IMAGE` as the operator-approved image repository, `STUDIO_CPU_DIGEST` as its 64-character SHA-256 digest and `STUDIO_WORKER_KEY` through the host's secret/environment manager. The compose template does not install Docker, create TLS, provision GPUs or enable models. The image reference always includes `@sha256:`; Docker rejects invalid digests. Keep the loopback socket behind the private HTTPS ingress described above.

Release sequence: preserve the database/config/uploads backup; deploy the exact green application and frontend SHA; apply additive migrations; start the private worker and Studio queue consumer; run preflight; then exercise authenticated procedural and Architecture generation, reference upload/private download, mesh import/edit/version/export/reopen and cancellation with two accounts to check ownership. Run actual image-to-mesh inference for every engine being enabled. Record output hashes, model revisions, hardware and observed duration. A green CPU preflight permits only the tested CPU scope; it is not full GPU production acceptance. If any required smoke fails, retain the old application release and leave the new worker/engine disabled.

Run Python worker tests, the real signed PHP/HTTPS CPU bridge, backend ownership/idempotency/cancellation tests, frontend build/integration and browser acceptance before release. Repeat actual mesh generation for each enabled GPU family/quantization, inspect outputs in an independent viewer, test cancellation and process restart, and record hardware, immutable versions, time, output hash and observed errors. Fixture provider tests do not replace these checks.

Worker interruption marks an active local execution failed rather than guessing success. Queued jobs resume. Laravel uses stable job IDs for retries and verifies artifact hash/size before private publication; late cancellation cannot publish a new output. A procedural call interrupted without a persisted scene fails with an uncertain outcome and is not automatically billed again. Inspect and deliberately create a new request after review when needed.

Do not remove durable state or the private asset store to recover a queue. Preserve backups and job evidence. A rollback should return to the previous application release and leave additive tables/files intact; disable worker configuration to close processing gates. Shared model weights are not removed by registration rollback. Full production deployment and GPU acceptance remain pending.
