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

Run Python worker tests, the real signed PHP/HTTPS CPU bridge, backend ownership/idempotency/cancellation tests, frontend build/integration and browser acceptance before release. Repeat actual mesh generation for each enabled GPU family/quantization, inspect outputs in an independent viewer, test cancellation and process restart, and record hardware, immutable versions, time, output hash and observed errors. Fixture provider tests do not replace these checks.

Worker interruption marks an active local execution failed rather than guessing success. Queued jobs resume. Laravel uses stable job IDs for retries and verifies artifact hash/size before private publication; late cancellation cannot publish a new output. A procedural call interrupted without a persisted scene fails with an uncertain outcome and is not automatically billed again. Inspect and deliberately create a new request after review when needed.

Do not remove durable state or the private asset store to recover a queue. Preserve backups and job evidence. A rollback should return to the previous application release and leave additive tables/files intact; disable worker configuration to close processing gates. Shared model weights are not removed by registration rollback. Full production deployment and GPU acceptance remain pending.
