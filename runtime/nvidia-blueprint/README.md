# NVIDIA Deep Researcher integration

This adapter runs the **actual NVIDIA AI-Q / Deep Researcher `release/2.2` implementation**, pinned to `a6d9efdec69a3052e1ee7f15a45d25711ac05d2a` (upstream package 2.2.1). It does not emulate the Blueprint with a single prompt. Upstream: <https://github.com/NVIDIA-AI-Blueprints/deep-researcher-agent/tree/release/2.2>. The upstream source retains its Apache-2.0 license.

The Laravel research queue launches one private Python process per NVIDIA task. The upstream planner, researcher and writer use the project's verified NVIDIA model through OpenMindAI's existing gateway. Provider credentials stay in Laravel. Expiring bridge tokens are bound to the active task claim, actor, model and generation; pausing/revoking a run fences subsequent callbacks. Model calls retain NVIDIA 40 RPM, user/model allowances and existing transport diagnostics. Web/scholarly search and URL reads use project-scoped source records and the existing DNS-pinned reader. The adapter uses the upstream virtual `StateBackend`; it enables no host shell, generated-code execution, public Python server or external MCP connection.

The NVIDIA task produces a reviewable report proposal. Existing literature/evidence extraction, reference verification, manuscript, citation, quality and integrity stages follow. Completed reports are checkpointed before follow-up stages; an interrupted native process can require a fresh NVIDIA run. Generated proposals never overwrite saved user manuscripts. Automated file bundles remain clearly marked as drafts until current integrity/approval gates pass.

## Private installation

Python 3.11–3.13, Git, a C++ compiler and roughly 1.5–3 GB of free disk are needed. Inference uses hosted NVIDIA APIs; no GPU is required. Run outside the web root:

```sh
sh runtime/nvidia-blueprint/install.sh /home/ACCOUNT/openmind-nvidia-deep-researcher /opt/alt/python311/bin/python3
```

The installer uses the pinned upstream lockfile and serial build/install settings suitable for shared hosting. It preserves a modified/different destination instead of resetting it. The per-host runtime lock prevents multiple memory-heavy NVIDIA workers running simultaneously on a shared account. Busy tasks wait without discarding completed work.

Set these private Laravel environment values and rebuild its configuration cache:

```dotenv
RESEARCH_BLUEPRINT_ROOT=/home/ACCOUNT/openmind-nvidia-deep-researcher
RESEARCH_BLUEPRINT_PYTHON=/home/ACCOUNT/openmind-nvidia-deep-researcher/.venv/bin/python
```

`APP_URL` must be the actual HTTPS Laravel origin. `/api/v1/research/status` checks the installed revision and validates the upstream configuration. Start with **NVIDIA Deep Research** in a research project. The native workflow has the upstream one-hour execution bound; it runs independently of the browser's request duration.

## Automatic research files and notifications

Run database migrations, install Composer's locked dependencies, then run `php artisan webpush:setup` and `php artisan config:cache`. VAPID keys are saved privately without printing credentials and existing keys are preserved. Browser permission/subscription is an explicit user action. Push payloads contain a generic update and an authenticated project URL, not manuscript text. Offline storage caches public assets only.

A completed workflow automatically queues DOCX, PDF, LaTeX ZIP, BibTeX and Markdown from a frozen saved manuscript or unaccepted proposal. Existing isolated export worker configuration is reused. All formats appear in the conversation and Export view; failed formats can be retried independently. These files do not bypass evidence, experiment or human approval gates.

## Verification

`tests/Feature/ResearchBlueprintTest.php` covers task-token fencing, source isolation, gateway reuse/output budgets and completed-report checkpoints. `tests/nvidia_blueprint_contract.py` validates adapter/source-markup contracts without pretending to run upstream inference. `runner.py --check` validates a real installed runtime. A real-provider research run and successful five-format delivery must be recorded separately before calling the deployment accepted.
