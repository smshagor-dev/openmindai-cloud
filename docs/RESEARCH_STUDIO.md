# OpenMind AI Research Studio

Implementation branch: `feature/research-studio`. Working implementation; live provider, hosting and final acceptance are not yet certified. See `RESEARCH_STUDIO_STATUS.md` for actual checkpoint results. No production secrets or uploads belong in this repository.

## Architecture

The existing Laravel 12 application owns authentication, authorization, project data, agent orchestration, gateway credits and durable database queues. The existing Next.js Cloud application adds `/research-studio` and uses its authenticated same-origin backend proxy. A private authenticated Python broker starts one isolated Docker container per document operation or explicitly authorized Python experiment. It is not exposed to the browser.

A persisted task dependency graph runs independent discovery/verification work concurrently, up to three tasks per project. Gateway reasoning calls are serialized per project to respect the existing gateway lock. Runs and tasks have generation/claim fences, bounded retries, leases, checkpoints and immutable component schema/code snapshots. Recovery runs every minute. Changed component code requires a new run rather than silently resuming a different implementation.

Events are appended by actual backend actions. Short SSE responses carry real task, source, browsing, manuscript and worker events; the client reconnects with a cursor, loads a recent snapshot and supports older browsing history. Measured progress is completed tasks/work units. The dependency diagram reads stored edges. No simulated browsing, screenshots or progress are generated.

## Components

| Component | Real responsibility and outputs |
| --- | --- |
| Planner | Multilingual intent, constraints, output language, perspectives and an experimental/nonexperimental plan |
| Discovery | Crossref/OpenAlex searches, date filters, DOI/title deduplication, source persistence and licensed PDF retrieval |
| Literature review | Chunked PDF/text analysis, exact quoted evidence candidates, paper matrix, themes and contradictions |
| Autonomous investigation | Distinct perspectives, evidence-linked questions/hypotheses and bounded evidence revisit |
| Academic editor | Section-by-section academic drafting, review feedback and proposals based on the current document version |
| Citation manager | Saved-document citation audit, scoped source IDs, missing references and CSL/BibTeX interchange |
| Gap analysis | Evidence-linked gaps, limitations, qualified novelty and recommended investigations |
| Experiment workspace | Reproducible code plans using available authorized dataset manifests; execution requires exact owner approval |
| Reference verification | Canonical DOI metadata, title/authors/year/venue checks, correction/retraction metadata and verification timestamps |
| Manuscript builder | Rich document proposals, citations and exact verified experiment CSV/image artifacts; no automatic user-edit overwrite |
| Quality review | Saved-manuscript hash, evidence/execution review, limitations/disclosure and project-corpus textual overlap |
| Collaboration | Proposed review assignments, accepted memberships, roles, scoped comments, approvals and revision history |
| Research integrity | Evidence, approved execution/provenance and current manuscript review/approval gates |
| Q&A | Project/source-scoped retrieval, lexical ranking and optional real configured embeddings |
| Selection editor | Reviewable suggestions for explicitly selected text |

Each component has its own execution class, input/output validation, declared tools/permissions, versioned definition, task state and audit events. Findings, questions, hypotheses, gaps and quality reports use typed `research_findings` records. No component may silently replace the user's document or share a project.

## Persistence

Migration `2026_10_25_000001_create_research_studio.php` adds 19 tables:

- `research_projects`, `research_members`, `research_runs`
- `research_agent_definitions`, `research_tasks`, `research_dependencies`, `research_events`
- `research_sources`, `research_evidence`, `research_findings`
- `research_documents`, `research_revisions`, `research_citations`, `research_comments`, `research_approvals`
- `research_experiments`, `research_artifacts`, `research_exports`

Project children have scoped foreign keys/indexes. Source fingerprints and task claims prevent duplicate/conflicting writes. Documents use version compare-and-swap plus immutable revisions; conflicts preserve local edits. Figures, citations, evidence and downloads must belong to the project. Files remain on the private local storage disk. Account deletion retains nullable collaborator audit attribution while owned-project rows follow existing owner deletion semantics. There is no new destructive purge endpoint. Back up private research files with the database; agree on retention before production use.

## REST API

All paths below are relative to `/api/v1/research`. Interactive Sanctum sessions, active-user and project-role checks apply. Developer API keys cannot manage these research projects. Mutation body, request, source, storage, token and task limits apply.

| Method | Path | Purpose |
| --- | --- | --- |
| GET | `/status` | Authenticated model/worker readiness; private endpoint and credentials are never returned |
| GET, POST | `/projects` | List/create owned or accepted shared projects |
| GET | `/projects/{id}` | Atomic workspace snapshot, integrity and recent event cursor |
| PUT | `/projects/{id}/settings` | Languages, reference dates, journal/layout and model workloads |
| POST | `/projects/{id}/runs` | Start complete workflow |
| POST | `/projects/{id}/components` | Execute an independently validated helper component |
| POST | `/projects/{id}/control` | Pause, resume or cancel run and active experiments |
| GET | `/projects/{id}/events?after={cursor}` | Incremental SSE events; `before` retrieves older history |
| PUT | `/projects/{id}/document` | Save rich document with expected version |
| GET | `/projects/{id}/revisions` | Revision history/proposals |
| POST | `/projects/{id}/sources` | Import citation records |
| GET, PUT | `/projects/{id}/sources/{source}` | Read/update project source and manual text |
| POST | `/projects/{id}/uploads` | Authorized PDF, CSV dataset or PNG/JPEG figure |
| PUT | `/projects/{id}/evidence/{evidence}` | Human evidence support review |
| POST | `/projects/{id}/comments` | Project/document annotations/review decisions |
| POST, DELETE | `/projects/{id}/members[/{member}]` | Explicit invitations/revocation |
| GET | `/invitations` | Current user's invitations |
| POST | `/invitations/{invite}/accept` | Accept access before materials are shared |
| POST | `/projects/{id}/approvals` | Content-hash-bound approval or explicit reasoned override |
| POST | `/projects/{id}/experiments` | Save exact code, dataset list and seed |
| POST | `/projects/{id}/experiments/{experiment}/execute` | Owner authorizes exact hash and queues execution |
| POST | `/projects/{id}/experiments/{experiment}/cancel` | Fence results and request actual worker termination |
| POST | `/projects/{id}/exports` | Queue frozen draft or gated final export |
| GET | `/projects/{id}/exports/{file}/download` | Private checksum-verified export |
| GET | `/projects/{id}/artifacts/{file}/download` | Private checksum-verified artifact |

## Workspace and editor

The Cloud menu links AI Research Studio to `/research-studio`. Project views cover overview, live agents, web activity, literature, PDF reading/chat, gaps, experiments, manuscript, citations, quality, collaboration, export, history and settings. Desktop has collapsible project/assistant panels; mobile uses navigation and activity drawers. Themes, RTL text, Unicode fonts, keyboard controls, focus states and reduced motion are supported.

The Tiptap editor supports headings/outline, formatting, tables, private figures/captions, KaTeX equations, footnotes, source citations, bibliography, stable cross-references, annotations, suggestions, undo/redo, autosave and revision restore. Local recovery preserves unsaved content after conflicts. Agent drafts are accepted explicitly against their base version. Six bundled CSL styles are APA, IEEE, ACM, Vancouver, Chicago and Harvard. Journal layouts (IEEE, ACM, Springer, Elsevier and custom) are independently implemented formatting presets, not licensed official journal classes or submission certification.

## Providers and evidence

Reasoning reuses `OpenMindAiGatewayController::mobileChat`, existing model routing, provider timeouts, user credits and usage accounting. Model routing requires enabled, API-ready catalog entries with a successful provider verification status. Nemotron 3 Ultra 550B is preferred only when those checks pass; fallback candidates are explicit and bounded. The seeder creates an unverified disabled model and preserves operator state on repeat runs. No new model is automatically enabled.

Optional embeddings use the existing gateway and a separately configured verified embedding-capable model. If absent or failing, project-scoped lexical retrieval remains available and reports that mode. Research structured JSON is validated; the system does not assume universal provider tool-call/embedding capability. Independent agent tools are executed by constrained services rather than arbitrary model-generated HTTP requests.

Crossref and OpenAlex are the implemented scholarly providers. Other example providers in the product brief are not claimed as integrations. Canonical metadata, original titles and source provenance are retained. Discovery observes bounded requests/rate limits and caches. Full-text retrieval accepts supported permissive licenses, public DNS-pinned HTTPS, small PDF responses and no redirects/proxies/private addresses. Untrusted source text is treated as evidence rather than tool instructions.

## Private workers and operation

Use a Linux Docker worker host; cPanel PHP does not execute generated code. Install Python 3.12 and a virtualenv with `runtime/research/requirements.txt`. The broker user needs access to the Docker socket and is therefore an infrastructure trust boundary; isolate this host from unrelated services and credentials.

Build images from a reviewed commit:

```sh
docker build -f runtime/research/Dockerfile.processing -t openmind-research-processing .
docker build -f runtime/research/Dockerfile.sandbox -t openmind-research-sandbox .
docker image inspect openmind-research-processing --format '{{.Id}}'
docker image inspect openmind-research-sandbox --format '{{.Id}}'
```

Set the resulting immutable `sha256:...` IDs as `RESEARCH_PROCESSING_IMAGE` and `RESEARCH_SANDBOX_IMAGE` in the broker's private environment. Set `RESEARCH_WORKER_KEY` to an independently generated secret of at least 32 characters, never in Git/browser. The Laravel application needs the same key and `RESEARCH_WORKER_URL` (HTTPS, or loopback HTTP). A remote broker must sit behind authenticated TLS transport and private ingress controls. `GET /health` requires the bearer token and checks that the configured immutable images are available through the Docker daemon. It returns HTTP 503 when setup/runtime is unavailable and explicitly does not claim successful job execution. The workspace Overview/Settings status card exposes only sanitized readiness, cached for 30 seconds. Run `php artisan research:preflight --fresh` to perform the same check from the application host; its exit status is nonzero until model configuration and worker runtime are ready. No inference, conversion or experiment is executed by preflight.

`runtime/research/research-worker.service` is a systemd template with restart-on-failure and persistent cancellation/submission guards. Adapt `/srv/openmind`, the virtualenv and service identity for the worker host. `runtime/research/queue-supervisor.conf` runs three database queue consumers for the `research` queue. Keep queue reservation retention longer than the 440-second consumer timeout; the existing default is 86400 seconds. Run the existing Laravel scheduler every minute so `research:recover` can fence expired leases and fail interrupted worker jobs truthfully. Check existing jobs before restarting consumers.

Document workers have no network, host mounts, API secrets or writable root filesystem; they use user 65534 and bounded CPU/memory/PIDs/tmpfs/time/output. Experiment workers use a small trusted parent wrapper with SETUID/SETGID/KILL only; generated Python drops to UID/GID 65534 before running. Its scientific libraries are installed in the immutable image. The wrapper records seed, code/bootstrap/image hashes, dependencies, dataset checksums, execution timestamp, actual logs/exit code and checksummed artifacts. It terminates remaining child processes. Broker cancellation tombstones fence delayed starts and repeated submissions. Expiry labels permit the broker to reap only its own expired managed containers after a broker crash. Unreachable cancellation is visibly unconfirmed; late results remain fenced.

Owner authorization is bound to code, dataset IDs and seed, revalidated by the worker job. Private dataset bytes are checksum verified. Reproducibility does not guarantee scientific validity. Human evidence/method/result review remains necessary.

## cPanel queue scheduling

The verified target-account capabilities and remaining same-host provisioning requirements are recorded in [cPanel acceptance](RESEARCH_CPANEL_ACCEPTANCE.md). cPanel itself does not imply that an isolated processing runtime is available.

The existing Laravel scheduler must still run every minute. When a hosting account provides GNU `flock` and `timeout` but no persistent supervisor, `runtime/research/cpanel-queue.sh` can consume the `research` database queue through scheduled CLI processes. Configure three once-per-minute Cron Jobs with the same application/PHP paths and lanes `1`, `2`, `3`:

```sh
/bin/sh /path/to/app/runtime/research/cpanel-queue.sh /path/to/app /path/to/php 1
```

Each lane has an OS file lock, exits successfully if already running, and has a 500-second outer deadline plus a ten-second termination grace period. This outer bound also works without PHP PCNTL. The consumer checks its shorter run duration between jobs; unfinished claims recover through the existing scheduler/lease mechanism. Direct output to a private rotated log, keep queue reservation retention above the worker deadline and use this mode instead of adding duplicate supervisor consumers. Check the hosting provider's CLI/cron execution limits before enabling it. This script does not install tools, change crontabs, execute generated Python on the web host or replace the isolated processing broker. PDF/experiment/export work still requires the private isolated worker endpoint.

## Exports

DOCX, PDF, LaTeX ZIP, BibTeX and Markdown use actual Pandoc/CSL conversion. DOCX files reopen with python-docx/ZIP structure checks; PDF files reopen with pypdf and reject missing glyph logs; LaTeX validates generated content/bundled artifacts. Math, tables, footnotes, figure captions and resolved cross-references are preserved. PDF requires XeLaTeX, bidi, Latin Modern, DejaVu and relevant Noto fonts. Unicode font requirements accompany portable LaTeX output. Generated CSV tables are exact bounded previews with explicit truncation captions and full original artifacts retained.

Final export requires Evidence Verified, Experiment Verified when applicable, and current Manuscript Reviewed and Approved. Hash changes invalidate prior review/approval. Explicit human overrides retain the failed gate, reason and audit record. Draft export is visibly marked and can be used before final approval.

## Verification and outstanding acceptance

```sh
composer install
php artisan test
vendor/bin/pint --test app/Models/Research app/Services/Research app/Jobs/Research app/Http/Controllers/ResearchStudioController.php config/research.php routes/research.php database/migrations/2026_10_25_000001_create_research_studio.php
python3 tests/research_concurrency.py
cd frontend
npm ci
npm run typecheck
npm run build
npm run test:integration
cd ..
node frontend/e2e/run.mjs
python3 -m unittest runtime.research.test_research -v
```

Browser tests require Chromium/Playwright and the test-only worker converter adapter; that adapter is not a production isolation bypass. Python converter tests require Pandoc, XeLaTeX and fonts. Actual Docker tests run only when immutable image IDs and Docker are supplied; skipped local tests are not counted as live execution. The GitHub Research Studio workflow builds both real images and exercises actual document/experiment containers.

Deterministic model/index fixtures cover Bengali, English and Russian workflows. They are separate from real-provider testing. Live NVIDIA inference, gateway streaming/catalog visibility, optional embeddings, live scholarly credentials/rate limits, target MySQL, worker supervision/restart recovery and complete production-host acceptance must be checked separately before release. Never output secret values or provider response bodies containing private research during readiness checks.

There is no built-in OCR or licensed global plagiarism service. Scanned PDFs require an authorized OCR workflow. Similarity reporting is explicitly limited to project sources. The platform does not promise global novelty, plagiarism-free text, human authorship or detection evasion. Human review/AI disclosure and journal policies still apply.
