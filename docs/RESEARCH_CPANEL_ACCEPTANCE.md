# Research Studio on the existing cPanel host

Verified on 2026-10-25 through the authenticated cPanel Terminal. This records the actual account's capabilities; it is not a claim about every cPanel installation or a completed Research Studio deployment.

After this inspection, the user explicitly instructed deployment to proceed. The web/API/queue release `ca6497c60c63917a5f8f5cdc102904f930e56dbc` is now deployed on the existing backend and chat frontend, with processing-dependent operations still showing setup required. The observations below describe the pre-deployment capability inspection; the latest deployment results are in `RESEARCH_STUDIO_STATUS.md`. No completion PR/merge or full processing acceptance is implied.

## Observed capabilities

| Check | Actual result | Deployment implication |
| --- | --- | --- |
| Production checkout | `main`, `6a42d5b`; local Ultra seeder modification retained | Preserve the existing application and server-specific files. Research Studio remains on its feature branch. |
| PHP CLI | 8.2.35; PCNTL, POSIX and PDO MySQL enabled | Required queue extensions are present. |
| Queue tools | GNU `flock` 2.32.1 and `timeout` 8.30 | The scheduled research consumer's required executables are present. Real production queue consumption is still unverified. |
| Existing scheduled jobs | Six active crontab entries; one Laravel scheduler entry; zero research consumers | Preserve existing jobs. Do not install a duplicate scheduler. Cron contents were inspected internally and only aggregate counts were displayed. |
| Python | Default 3.6.8; alternative 3.11.17 available | A newer trusted Python runtime is available, but Python alone does not isolate uploaded/generated code. The provisioned CI/container runtime uses 3.12. |
| Node | Alternative runtime directories through Node 24 present | Directory presence does not verify application startup, supervision or a processing sandbox. |
| Isolation executables | No `docker`, `podman`, `bwrap`, `firejail` or `unshare` executable on the account PATH | The implemented Docker worker cannot currently start through this account. |
| User namespace | A one-time `unshare(CLONE_NEWUSER)` attempt returned `-1`, errno 28 | A user namespace could not be created by this account. No host settings or limits were changed. |
| Landlock | Kernel `4.18.0-513.18.1.lve.2.el8.x86_64`; Landlock ABI query returned `-1`, errno 38 | The syscall is unavailable to this process; it cannot provide the required filesystem isolation here. |
| Document tools | No `pandoc`, `xelatex` or `pdftotext` on the account PATH; Ghostscript present | Complete document processing/export has not been provisioned. Ghostscript alone does not satisfy the export pipeline or isolation requirement. |
| Private worker settings | `RESEARCH_WORKER_URL` and `RESEARCH_WORKER_KEY` absent from the deployed `.env` | No production research worker has been configured. Only presence/absence was displayed; no environment values were exposed. |

The account's previously observed limits are 2 GB memory and one CPU quota. This is not proof that all processing containers and the production application fit concurrently. Worker resource allocation needs hosting-provider verification.

## Same-host deployment requirement

The user's selected destination remains this hosting service. A new vendor, account or research-data destination has not been chosen or provisioned. The web application and bounded database-queue consumers can use cPanel's PHP/cron facilities. Completing this implementation's PDF, export and experiment workflows additionally requires a private isolated processing runtime.

The hosting administrator must provision or permit the reviewed worker on this same server, with a dedicated service identity, independently limited resources, supervised restart/recovery and private authenticated connectivity from Laravel. The Docker socket must not be exposed to the web application or granted to the cPanel account as a shortcut. Generated code must not run directly under the production account with access to its application files, uploads, credentials or network. Do not enable namespaces, change kernel settings, weaken security controls or install a privileged daemon from the cPanel account to get around hosting restrictions.

Suggested support request, prepared for the user and **not sent**:

> Can you provision the OpenMind Research Studio's reviewed, container-isolated processing broker on this same server under a dedicated service identity? It needs immutable processing/experiment image IDs, network-disabled per-job containers, read-only root filesystems, bounded memory/CPU/PIDs/time, no production-directory mounts or application credentials, private authenticated API access from Laravel, and restart/recovery supervision. The cPanel account should not receive Docker socket access or privileged-container permission. Please confirm the available resource budget and permitted cron/CLI execution duration. The current account cannot create a user namespace, and its Landlock syscall is unavailable. We have not changed kernel settings or installed a privileged service.

## Release and acceptance sequence

1. Obtain actual same-host worker provisioning and verify immutable images, authenticated health, process supervision and crash recovery. Health alone does not verify a workflow.
2. Execute the actual isolated document extraction, five-format export, approved experiment, cancellation and delayed-submission checks against that runtime. Reopen/validate the resulting files and verify network/filesystem restrictions and recorded provenance.
3. Complete the real-provider multilingual research-to-manuscript-to-export acceptance workflow. The previous Ultra JSON/SSE/chat smoke tests do not satisfy this full workflow.
4. Follow the feature-branch release/PR/merge policy. Preserve the production seeder change, `.env`, uploads and server-specific files when deploying the reviewed release. No reset, clean, force pull or database reset is needed.
5. Configure the worker URL/key privately; run feature migrations, production Composer installation, cache optimization and `research:preflight --fresh`. Never print or commit secrets.
6. Add the three bounded research queue lanes only after the reviewed release and runtime are available. Reuse the existing scheduler; confirm its actual working directory and CLI path without publishing private cron contents. See [the queue instructions](RESEARCH_STUDIO.md#cpanel-queue-scheduling).
7. Verify actual production queue acknowledgement, overlapping-lane rejection, interrupted-job recovery, project authorization, live SSE, real browser workflow, exports and Laravel error headings. Record actual outcomes before claiming production completion.

No feature migration, worker installation, cron change, production feature activation, PR or merge was performed during this capability inspection. Existing production maintenance and live Ultra verification are documented separately in `RESEARCH_STUDIO_STATUS.md`.
