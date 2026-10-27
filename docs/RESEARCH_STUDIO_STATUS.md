# Research Studio — implementation checkpoint

Updated: 2026-10-25 (Europe/Moscow).

## Current remote state

- Working branch: `feature/research-studio`.
- Base: `main` at `6a42d5b55e35f93e792f5abad629c9aa66e3dee3`.
- This checkpoint establishes remote-backed work instructions and a truthful continuation record.
- The earlier local-only attempt was unavailable. The application is being rebuilt on this remote-backed branch; historical test counts do not verify the rebuilt code.
- Web/API/queue release `d08dd18f12032a5ef966d85c659f92842ecfbbbc` is deployed on the existing cPanel backend and chat frontend, following the user's explicit instruction after the isolated-worker limitation was explained. See the latest deployment record below. No completion PR or merge has occurred; full processing/workflow acceptance remains pending.

## Verified repository baseline

- Laravel 12 backend with Sanctum authentication and interactive-session middleware.
- Next.js 16 / React frontend with an authenticated backend proxy.
- Existing hosted/NVIDIA gateway, model catalog and queue/storage configuration.
- Existing chat/web research capabilities are present; they do not constitute the requested Research Studio.
- Git working tree was clean before this checkpoint.

## Implementation sequence

1. Inspect existing gateway, authentication, UI/proxy, migrations and tests; document integration architecture.
2. Add project-scoped research persistence, authorization, APIs and revision-safe document storage.
3. Add independently executable agents, orchestrator, durable task dependencies, concurrency, retries, cancellation and recovery.
4. Add scholarly discovery, protected retrieval, PDF processing, evidence/reference verification and citations.
5. Add autonomous investigation, gap analysis and private isolated experiment workers.
6. Add academic editor, manuscript proposals, quality/integrity gates and human collaboration.
7. Connect all responsive workspace views to actual task and browsing events.
8. Add validated exports, multilingual support and deployment documentation.
9. Run deterministic unit/integration/browser tests and separate real integration checks. Report actual results.
10. Only after acceptance, open a completion PR and follow repository merge policy.

## Persistence policy

The user authorized ongoing GitHub checkpoint pushes. Save each coherent increment to the working branch and verify its remote head. Checkpoint pushes do not claim feature completion or authorize deployment. Update this record with implemented paths, actual checks and the next unfinished step.

## Verification

No new application test suite was run for this documentation-only checkpoint. Live reasoning/embeddings, Docker worker isolation/recovery and target deployment acceptance remain unverified.

## Checkpoint 1 — persistence and execution foundation

Added 18 research tables; scoped models/access; immutable manuscript revisions; source-linked evidence; hash-bound integrity gates; fifteen independently executable components (planner, twelve specialists, Q&A and selection editing); existing-gateway JSON inference; Crossref/OpenAlex discovery; protected licensed PDF retrieval; scoped lexical/optional real embedding retrieval; dependency orchestration, three-task concurrency, claim/generation fences, bounded retries, pause/resume/cancel and lease recovery. PHP syntax checks passed on new models/services/jobs. Application APIs, UI and worker wiring are still in progress. No live provider or Docker verification is claimed.

## Checkpoint 2 — authenticated APIs and processing jobs

Added 25 authenticated/scoped research routes; project creation/settings; short SSE activity delivery; versioned document saves/revisions; reference import; authorized private PDF/CSV/figure uploads; queued PDF extraction with replacement checks; evidence review; accepted invitations/roles; hash-bound approvals; explicit experiment authorization; frozen export snapshots; private checksum-verified downloads. New jobs require a configured private worker and do not execute uploaded/generated code in PHP. Route registration and PHP syntax checks passed; baseline/full integration tests are running. Frontend and private worker implementation are not complete at this checkpoint.

## Checkpoint 3 — integrated UI, private broker and first passing research flow

Added the `/research-studio` route and Cloud navigation; all fourteen workspace views; Tiptap rich manuscript editor with tables, equations, footnotes, citations, autosave/revisions; six real CSL styles; responsive themes and real SSE agent/browsing events; scoped authenticated proxy/download support. Added authenticated private broker, per-job document processing containers, approved scientific experiment container protocol and five actual document export converters.

New backend ResearchStudioTest: 11 tests passed with 84 assertions, including Bengali/English/Russian fixture workflows and a real Markdown converter export after evidence review/human approval. These model/index responses are deterministic mocks, not live inference. TypeScript and production frontend build passed. Worker converter checks exposed Markdown writer citation rendering, which was corrected; rerun in progress. Real Docker tests remain unavailable locally. Browser end-to-end, Unicode PDF, additional concurrency/security checks and final acceptance remain in progress.

## Checkpoint 4 — browser tests, Unicode and concurrency hardening

Added reproducible Playwright fixtures/runner, an actual two-PHP-process source-lock/deduplication test and six-style/numeric citation tests. Added glyph-checked Bengali/Devanagari/Arabic/CJK PDF font switching with Arabic bidi, portable LaTeX font requirements, stricter worker input/output bounds, separate unprivileged experiment child execution and persistent execution/cancellation guards. Added consistent figure ownership checks and locked export gate checks. Browser tests and final suites are still being debugged; no completion or live integration claim is made. GitHub CI now includes actual Docker worker tests, backend and browser jobs.

Actual checks at this checkpoint: 11 research backend tests passed (84 assertions), two actual PHP processes overlapped and deduplicated one source, all three frontend research unit tests passed (including six CSL styles and ordered numeric citations). Mobile browser editing/refresh passed; desktop browser revealed a fixture interaction with a temporarily disabled upload control, now corrected for rerun. Production build passed before the latest citation fix; final rebuild remains required.

## Checkpoint 5 — real CI defect corrections and research consistency

GitHub Actions on checkpoint 4 ran the full backend (329 passed, 2116 assertions), frontend build and 200 integration tests. Actual Docker tests exposed an incorrect document CLI import and an unnecessary sandbox chown requiring a dropped capability; both are corrected in this checkpoint. Browser mobile/authentication checks passed; desktop exposed a sticky editor toolbar overlap, corrected by removing its accidental scrolling ancestor. These fixes still require fresh CI verification.

Added thematic multi-paper synthesis, resumable per-chunk evidence extraction, canonical literal-author verification, exact permissive-license matching, numeric MySQL model casts, account-deletion foreign key compatibility, root-locked atomic snapshots with recent SSE history/cursor and rate-limit backoff, dependency graph, stable academic cross-references and equation/table identifiers, source quota handling, revoked-actor/stale-job recovery and experiment approval/provenance revalidation. PDF extraction cannot overwrite newer manual text or replacement uploads. Quality review receives actual execution records; experiment planning receives authorized dataset manifests. Current local research suite: 15 passed, 103 assertions. Full rerun, desktop browser, Docker isolation, live provider and deployment acceptance are still pending. This is a working checkpoint, not a release.

## Checkpoint 6 — approved experiments, manuscript artifacts and passing local acceptance checks

Added scoped per-experiment cancellation with explicit confirmed/unconfirmed termination state; all result acceptance is fenced before worker contact. Added exact CSV/image experiment artifacts to reviewable manuscript proposals and private figure/export selection. Only approved, successful, matching-provenance runs are eligible; tabular values come from actual checksummed CSV rather than model-generated metrics. Added bounded reviewer/editor execution context, managed-container expiry/reaping after broker recovery, configuration examples and full architecture/API/worker operations documentation in `docs/RESEARCH_STUDIO.md`.

Actual local checks: full Laravel suite 337 passed (2169 assertions), including 19 research tests; all 200 frontend integration tests passed; production frontend build and TypeScript passed. All three browser tests passed at 1440px/390px plus unauthenticated access. Desktop flow parsed a real uploaded PDF, inserted a citation, saved/refreshed the rich document and downloaded/reopened DOCX. Worker suite: 10 tests, 8 passed and 2 Docker-only skips; five real export conversions and multilingual PDF/DOCX checks passed. Actual Docker experiment execution passed in GitHub CI on checkpoint 5; its document container exposed missing `lmodern`, now added to the image for the next CI run. Live NVIDIA/embedding, target hosting and final Docker rerun remain pending. No PR, merge or production deployment has occurred.


## Checkpoint 7 — MySQL/browser CI passes; complete worker font dependencies

GitHub Research Studio run 40194003218 verified the checkpoint-6 backend, MySQL 8.4 research suite and desktop/mobile browser jobs successfully. Actual isolated Python execution also passed. The minimal processing image still lacked the Zapf Dingbats TeX metrics required by hyperref; added explicit texlive-fonts-recommended instead of weakening PDF validation. The generic PHP CI workflow lacked the new real-converter test dependencies; it now provisions Python, Pandoc and the worker libraries just like the Research Studio backend workflow. A fresh CI rerun is required for these corrections.

cPanel secure sign-in timed out. Fresh target-origin verification still shows cPanel Login. No production commands, config changes, database migrations or live NVIDIA inference have been performed. Model enablement remains blocked on verified credentials/provider access and real routing/streaming checks.


## Checkpoint 8 — full formatting check and real cancellation test

Full-repository Pint inspection found three remaining research fixture/console formatting files; formatted those without changing behavior, and full Pint now passes locally. Added a Docker-only test that cancels an actually running sandbox, confirms the container is gone and proves the persistent cancellation guard rejects delayed submission. This test requires fresh CI execution; it is not yet reported as passed.

## Checkpoint 9 — explicit language choices and verified CI baseline

All checks on commit `36f6614a03fb490a07d25fd637f6fb54bb2bbc24` passed: Research Studio backend, MySQL 8.4, browser and worker jobs; generic PHP 8.2/8.3, frontend and runtime-contract jobs. Worker CI ran 11 tests with no skips, including actual isolated document export/extraction, actual network-isolated unprivileged Python execution/artifacts and termination of a running sandbox with delayed-submission fencing.

Final review found that detected prompt language could take precedence over explicitly chosen progress/manuscript languages in presentation. Planning now preserves explicit user selections even if a provider returns conflicting language fields. Gateway prompts explicitly separate agent explanation and manuscript language, and UI resolves native language names/codes consistently with status translations for all eight non-English choices. Added backend and frontend regression coverage. Latest local checks: 20 research tests passed (141 assertions), frontend build/TypeScript and all 201 integration tests passed, full Pint passed. This final language delta requires its own CI rerun. No live NVIDIA/embedding or production verification is implied by these deterministic tests.

## Live production verification — 2026-10-25

cPanel sign-in is now verified. The correct deployed checkout is the existing Cloud application on `main` at `6a42d5b`; upstream `origin/main` matches. Ran `git pull --ff-only origin main`, production Composer install with `--no-dev --optimize-autoloader`, `php artisan migrate --force`, `NemotronUltra550BSeeder --force`, `optimize:clear` and `optimize` successfully. The local seeder intentionally avoids duplicating an existing provider registered under the public `openmindai-ultra` slug. Preserved that change. Before/after checksums confirmed `.env`, server-specific web/PHP files and the local seeder unchanged. No user uploads were removed or overwritten, no database reset occurred and no unrelated service was changed.

The existing Ultra record was already enabled/API-ready and previously verified; no duplicate model, new persistent credential or additional enablement was created. New actual NVIDIA probe returned HTTP 200 with valid inference. Actual Laravel gateway JSON inference returned HTTP 200 with content and the expected public model slug (1.99 seconds). Actual gateway SSE returned HTTP 200, six parsed events, nonempty content, `[DONE]` and no error event (1.73 seconds). The CLI gateway checks used an existing active administrator in process with an unsaved token object; they did not test external bearer authentication. Separately, the already-authenticated live chat frontend successfully completed a temporary Bengali JSON request using OpenMind Ultra, confirming the real browser-to-frontend-to-Laravel/provider flow. Restored the original Auto model selection after testing.

Cloud and chat public pages returned HTTP 200. An unauthenticated request to the protected models endpoint correctly returned HTTP 401. Direct live Crossref and OpenAlex canonical DOI metadata checks each returned HTTP 200 with the expected title; these are metadata connectivity checks, not a full live Research Studio workflow. Recent Laravel log error headings were inspected without exposing private log bodies: eight historical error entries in the inspected window, latest 2026-10-24 23:52:23; no new error heading during these smoke tests.

All implementation CI jobs for `bec8bd3820bacfd95c477c23008bffb9cc4c7154` passed. Actual final suite totals: 338 backend tests / 2173 assertions, 201 frontend tests, three browser tests, eleven worker tests with actual Docker document/experiment/cancellation cases, and passing MySQL checks.

Research Studio itself remains on the feature branch and has not been merged or deployed. The cPanel shell has no Docker executable, and the currently deployed application does not expose a configured research worker. Production acceptance still requires an isolated worker host/endpoint with immutable images, private credentials, queue/scheduler/supervision/recovery verification and a full real-provider research-to-export workflow. Optional embedding integration is not live-verified. No completion PR or merge is claimed while these acceptance requirements remain unresolved. The existing-main maintenance and Ultra live smoke tests above are complete; they do not deploy the new Studio module.

## Infrastructure readiness and deployment diagnostics

Added an authenticated, interactive-session-only `GET /api/v1/research/status` endpoint, Overview/Settings readiness cards and `php artisan research:preflight --fresh`. They distinguish missing model setup, unavailable/unverified worker runtime and rejected worker credentials, while hiding all keys, private endpoints and raw upstream responses. Health responses are bounded, redirects are disabled and worker checks are cached for 30 seconds; changes to configured credentials invalidate that cache. Embedded URL credentials are rejected even when only a username is present.

The private broker now checks its immutable processing/sandbox images through Docker before returning a ready health status; unavailable setup/runtime returns HTTP 503. It explicitly reports that execution has not been verified by this health check. Existing actual Docker export/extraction tests now assert that real runtime health passes before executing jobs. This is operational readiness, not a claim that a complete research workflow or production supervision passed.

Actual local verification: full backend suite 340 passed / 2191 assertions; all 201 frontend integration tests passed; production frontend build and TypeScript passed; two actual PHP processes overlapped and source deduplication passed. Worker suite: 12 tests, 8 passed and 4 skipped locally (three Docker cases and Unicode-font verification require their provisioned environments). GitHub Docker/browser/MySQL reruns for this delta are pending. The former production-main maintenance and live provider checks remain recorded above; they have not been repeated or undone. The new browser session requires sign-in again. A production isolated worker host/endpoint remains an external deployment requirement; no unrelated host/service or production secrets were modified.

## cPanel scheduled queue runner

All GitHub checks on `b3f67be2738acae28c11c404b0c5d2e0a955fb6c` passed: generic PHP 8.2/8.3, frontend/runtime contract and Research Studio backend/MySQL/browser/actual Docker worker jobs. The user confirmed that only cPanel hosting is currently available.

Added `runtime/research/cpanel-queue.sh` for once-per-minute research-only CLI consumers using GNU flock and timeout. Each of three lanes rejects overlap, propagates failed PHP exit status and releases its OS lock for a later run. A 500-second outer deadline plus ten-second termination grace bounds the process even without PHP PCNTL. The script does not install software, change crontabs or run generated Python on the web host. Production cron changes have not been made. Existing scheduler/lease recovery remains responsible for interrupted tasks.

Actual local shell/process fixture tests passed for overlap rejection, a failed worker followed by a successful retry, arguments and a PHP executable path containing spaces. Full worker suite: 13 tests, 9 passed and 4 environment-dependent skips locally. Shell syntax and diff checks passed. The new shell/process test and unchanged actual Docker cases require the final GitHub rerun. Only cPanel is available; a separate isolated execution endpoint remains necessary for PDF processing, experiments and exports. No such service has been provisioned or falsely reported as running.


All GitHub checks on `387412439bae661ce7be39a2b161485a54626880` passed, including all 13 worker tests without skips in the provisioned Docker/font environment. Extended the existing two-process integration test to enqueue an actual Laravel research job, run the scheduled CLI consumer against a disposable SQLite database, verify queue acknowledgement/no failed jobs and preserve a newer replacement source. This real consumer check passed locally and is part of the existing CI concurrency step. Generated lane lock files are excluded from Git; lock files are retained on disk rather than removed while another process could use them. No production cron entry has been created.

## Verified cPanel capabilities — same-host deployment request

All checks on `4d35d57e6f199321e676425d3278bbaf4b9e6d43` passed, including the actual Laravel scheduled-consumer test. The user explicitly selected the existing cPanel hosting. Signed in again using secure browser authentication and inspected its actual runtime without changing services, kernel settings, files or crontabs.

Confirmed PHP 8.2.35 with PCNTL/POSIX/PDO MySQL, GNU flock 2.32.1, timeout 8.30, alternative Python 3.11.17 and alternative Node runtime directories. The crontab has six active entries, including one Laravel scheduler and no research consumers; no raw cron commands or credentials were exposed. The deployed checkout remains `main` at `6a42d5b`, with the same local Ultra seeder modification. `.env` worker URL/key fields are absent; only their presence/absence was displayed.

No Docker/Podman/bubblewrap/firejail/unshare executable is available on the account PATH. A harmless one-time user-namespace creation attempt returned `-1` / errno 28. The Landlock ABI query returned `-1` / errno 38 on the account's 4.18 LVE kernel. These account-level results provide no usable isolation path for the implemented processing worker. Pandoc, XeLaTeX and pdftotext are also absent from PATH. Python alone is not a safe substitute for the document/experiment sandbox.

Added `docs/RESEARCH_CPANEL_ACCEPTANCE.md` with the exact observations, a prepared but unsent same-server hosting-support request and the remaining release/acceptance sequence. This does not require selecting another hosting vendor. The hosting administrator must provision the private isolated runtime on this server before its full workflow can be verified. No privileged daemon, namespace setting, public endpoint, worker credential, feature migration, cron consumer, PR, merge or production Research Studio activation was created. Existing production data/server files remain preserved. This documentation checkpoint changes no application/runtime behavior; application test suites were not rerun for it. `git diff --check` is the applicable local check.

## User-authorized web/API/queue deployment — 2026-10-25

After the processing limitation was explained, the user explicitly said to deploy. Deployed the available Studio components while keeping private processing unavailable and visibly marked setup required. This instruction overrides the earlier restriction on deploying before complete processing acceptance; it does not authorize removing security controls or claiming full completion.

Release: `ca6497c60c63917a5f8f5cdc102904f930e56dbc`, branch `feature/research-studio`. All checks on this exact commit passed before release: Research Studio workflow `40220707077` and Backend/frontend validation `40220706226`, including PHP 8.2/8.3, frontend/runtime contract, MySQL, browser and real Docker worker jobs. The exact application delta from the previously passing implementation was documentation only. No completion PR or merge was opened.

### Backend and preservation

Created a private backup outside the webroot containing the original Git state/local patch, existing crontab, six configuration/server/seeder files and a transaction-consistent compressed database dump. The dump was 163771 bytes and passed `gzip -t`; its checksum and completion manifest are retained privately. Credentials/configuration values were never printed, copied to GitHub or downloaded. The dump has not been restored into a second database; gzip validation is not a restore test.

Fetched and verified the exact release, confirmed no upstream change to the locally modified seeder or Composer manifests, then switched the production checkout to track the feature branch without stashing, resetting or overwriting local changes. Ran safe feature-branch pull, `composer install --no-dev --optimize-autoloader --no-interaction`, `php artisan migrate --force`, the requested `NemotronUltra550BSeeder --force`, `optimize:clear` and `optimize` successfully. Maintenance mode was brief and the application was returned to live. All six preserved-file hashes matched afterward; existing upload storage and server-specific files remain intact.

The research migration is recorded as run in batch 14. Production inspection confirms **18 research tables**. Earlier documentation said 19; the actual migration creates 18, and that count has been corrected. No existing table was reset and no user data was deleted.

Installed three once-per-minute bounded `cpanel-queue.sh` lanes with the verified CLI binary. The existing six cron entries and existing scheduler were preserved exactly; no duplicate scheduler or unrelated consumer was added. Executed the actual research consumer successfully. Subsequent real UI-created research work was consumed automatically by these scheduled workers. Final queue inspection: zero queued research jobs and zero failed research queue jobs.

### Frontend and live verification

Updated the existing `openmind-ai` Vercel project, not a new hosting destination. Production deployment `dpl_D9gqQ7tKr1Dv3jiJsVCd9NmRzpMD` reached READY for the exact release SHA, with no alias error, and serves the existing `chat.openmindai.org` domain. The previous ready production deployment remains available for code rollback; no protection setting, domain or private environment value was changed.

Actual authenticated browser-to-frontend-to-Laravel checks passed:

- Opened `/research-studio` and created a clearly labelled, private deployment-verification project.
- Submitted Bengali instructions with explicit Bengali progress and English manuscript settings. Actual Ultra-backed planning preserved both language selections and the no-experiment constraint.
- Observed actual queue-derived progress, Crossref/OpenAlex discovery, public source URLs and timestamped browsing/DOI-verification events through the live UI. The workflow reached 60 persisted sources and its configured source bound truthfully emitted limit events.
- Canonical verification accepted 27 sources, flagged 8 metadata mismatches and left 25 unverified when the test was stopped. These counts are distinct from evidence approval or complete literature verification.
- Literature processing created 12 exact-source evidence rows. They remain unreviewed; no evidence gate or manuscript approval was fabricated.
- Edited a real rich-text heading and bilingual paragraph, saved version 3, reloaded and confirmed both content and formatting persisted.
- Paused, reloaded, resumed and then cancelled the bounded deployment check. Final task states are 9 completed and 14 cancelled; all test sources, manuscript content and audit history remain available. The test project was retained rather than deleted. This is a stopped smoke workflow, not a completed research-to-export acceptance run.

Fresh post-deployment `models:audit --live --model=openmindai-ultra --timeout=120` returned success/HTTP 200 with configured runtime and no missing settings. The existing verified model state was preserved. Research reasoning uses its verified fallback record. Readiness correctly reports reasoning ready and private processing setup required. The preflight nonzero exit for the missing worker is expected and was not hidden.

HTTP checks: backend admin login 200; unauthenticated research projects 401 as required; deployed Studio page 200. The actual authenticated creation/edit/control flow above additionally verifies private frontend API routing. No new Laravel error headings appeared in the inspected recent log window after backup/deployment; latest observed historical error remains 2026-10-24 23:52:23. The live queue has no failed jobs. Previous JSON/SSE gateway smoke results remain recorded above; this release did not change the underlying gateway implementation.

### Remaining acceptance

Private PDF extraction, isolated Python experiments and all five final export conversions remain **unavailable on this production host** until its isolated processing broker is provisioned. No unsafe native execution fallback, fake export, fabricated experiment, production worker secret or new public processing endpoint was introduced. Full research-to-export acceptance, actual processing supervision/crash recovery, optional live embeddings and live mobile responsive verification remain open. CI desktop/mobile and actual Docker tests passed in their provisioned environments; they do not establish those missing production integrations. No complete-production-readiness or completion-PR claim is made.

This deployment record and table-count correction change documentation only. `git diff --check` passed; application suites were not repeated for this audit-only delta.

## Conversation-first design implementation

The user selected implementation after reviewing three interface directions. Implemented Focused Chat with an on-demand manuscript/tool canvas. The main workspace now shows the original research request, a readable persisted plan, expandable actual agent/browsing activity, source/document attachments, agent findings, real experiment states and validated export links. The composer starts new research directly, asks questions using project evidence or requests reviewable manuscript revisions. The project sidebar keeps research tools in a compact disclosure instead of fourteen permanent navigation entries. Existing tools, permissions, source-upload consent, exact experiment approvals, evidence gates, proposal review, rich-editor autosave and version conflicts remain connected.

Added a bounded project-authorized conversation projection to the existing project snapshot, using existing tasks/findings across later runs. Latest 50 questions/revisions and a truncation flag are returned without including raw retrieval context in the conversation projection. The workflow plan survives subsequent question runs. No database migration, new credential or provider integration was introduced. Selected manuscript/source context clears on project change. Unsaved manuscript edits block leaving the canvas; suggestions do not overwrite the document. Markdown does not execute HTML or automatically load model-supplied remote images. The editor loads only when its canvas is opened.

Actual local verification: full backend 341 passed / 2202 assertions; all 201 frontend integration tests passed; TypeScript and production build passed; all four browser tests passed. Browser checks cover 1440px/390px chat and rich editor, follow-up/revision presentation, refresh persistence, direct composer-to-project creation with Arabic text, mobile canvas open/close, source/PDF/citation flow, actual DOCX reopen and unauthenticated rejection. Model answers/planning are deterministic browser fixtures; real project/document APIs and converter validation are exercised separately. A new Laravel regression test verifies real cross-run conversation persistence, ordering/50-item bound, retained workflow plan, projection privacy and other-user rejection. Each viewport uses its own disposable test account so the production rate limits remain intact.

The local restored Turbopack persistence cache initially failed to open; preserved that cache outside the checkout and rebuilt successfully. A converter test initially ran outside its provisioned Python environment; rerunning in the existing dependency environment passed, followed by the full passing suite. These environment issues were not hidden or treated as passing checks. Final formatting/diff checks passed. CI and live verification of this design release are next; the previous production processing-worker limitation remains unchanged.

## Live conversation design verification — 2026-10-26 (Europe/Moscow)

All eight CI jobs on design release `4f85593aeb041de1bb2bcb48b3d601e80607a061` passed: Research Studio backend/MySQL/worker/browser (`40225197351`) and general PHP 8.2/8.3/frontend/runtime-contract (`40225197332`). Recovered the exact committed code into a fresh checkout after the transient local working directory disappeared; no implementation was recreated from memory.

Signed into cPanel securely, checked production Git state and made a new private rollback backup of the existing controller, configuration/server/seeder files, Git changes and crontab. Fast-forwarded the existing feature-branch production checkout to the exact tested design release and refreshed optimized framework metadata. All six configuration/server/seeder hashes matched; crontab was unchanged. No new migration, database reset, credential, queue configuration or unrelated service change was necessary.

The existing chat frontend production deployment `dpl_Bvm2wqrx1okAzSuV4w14tHbPYtXm` reached READY for that same SHA and the existing custom domain, without alias errors or protection changes. A separate private project named Chat design verification was created through the live authenticated UI. Existing active research was observed and left running.

Actual provider-backed checks passed: a Bengali source-grounded question correctly declined to claim research results with no evidence; an English manuscript revision preserved the verification-only/no-experimental-results meaning. Both responses remained in the chat after reload. The manuscript was saved through the canvas as version 2 and remained version 2 after the AI suggestion; the suggestion did not replace the saved document. These are real inference/UI checks, distinct from the deterministic browser fixtures.

The live activity polling check exposed HTTP 429 cooldown messages appearing as global chat errors. The follow-up UI correction keeps the existing backend limits, reduces baseline polling to five seconds, respects bounded Retry-After cooldown and uses a small reconnecting/delayed activity indicator that clears on successful reconnection. Mutation errors remain visible. A regression browser check simulates one 429 and verifies recovery without a global error alert. A completed question no longer labels an unstarted workflow as queued. Local TypeScript, production build, all 201 frontend integration tests and diff checks passed for this polish; its exact CI/browser verification and final frontend release are pending. Missing private PDF/experiment/export processing remains explicitly setup required.

### Final design release

Release `d08dd18f12032a5ef966d85c659f92842ecfbbbc` is now deployed to both existing application checkouts. The Vercel production deployment `dpl_CNyUKhAq6GAWYdFvV1hDg8TBayAS` reached READY for this exact SHA and the existing chat domain, with no alias error. The cPanel checkout fast-forwarded safely; no migration, secrets, crontab, queue settings or unrelated services were changed by the polish.

All eight CI jobs on this exact release passed: general validation `40242144737` (PHP 8.2, PHP 8.3, frontend, runtime contract) and Research Studio `40242144738` (backend, MySQL, actual Docker worker, browser). The browser job spent an extended period installing dependencies before successfully completing; its eventual result is success. Separately, the matching four local browser tests passed in 26.8 seconds, including a deliberate HTTP 429 cooldown followed by recovery, desktop/mobile editing, Arabic composer entry and authentication rejection. All 201 frontend integration tests, production build and TypeScript passed. The full backend suite remains 341 tests / 2202 assertions, also verified in CI.

Fresh live browser checks on the final release confirm both provider-backed responses persist, the misleading queued placeholder is absent, the transport error is not a global chat alert and the unchanged original manuscript remains version 2. The canvas opens beside the conversation and closes back to chat. The private production audit confirms one completed question and one completed selection task, original manuscript text preserved, zero failed research queue jobs and no new error headings in the inspected Laravel log window; latest observed historical heading remains 2026-10-24 23:52:23. The verification project and its audit history were retained.

Added a six-minute limit and bounded APT retries/timeouts to the browser document-dependency setup step so future stalled setup attempts terminate. Workflow YAML parses and the new configuration was inspected locally; this operational change does not change the deployed application release or remove any check. Its own new CI invocation is separate from the fully passing release checks above.

The conversation-first design implementation and available-component deployment are complete. The broader platform's private processing runtime, production PDF/experiment/export acceptance and completion PR/merge remain outstanding as already documented. No processing gate was weakened and no unsupported operation was reported as available.

## 2026-10-26 — Cloud chat advanced web research branch

`feature/advanced-web-research` extends the conversation-first release's Web Search/Deep Research using the inspected desktop OpenMindAI reference (`7e3f11b`). It adds adaptive objective coverage, bounded hosted pause/resume, real browsing events, selected passages/PDF page provenance, duplicate-excerpt warnings and hosted citation/numeric review. See `docs/ADVANCED_WEB_RESEARCH.md` for exact path differences, tests and release gates. This is not twelve-agent Research Studio acceptance, an Admin Dashboard completion claim or a production deployment. Public search/read live smoke was unavailable from the implementation environment; real provider/deployed acceptance remains outstanding.

### Main chat web search UX and concurrent research

The advanced-web-research branch now supports bounded concurrent search/read batches in the hosted chat orchestrator, individual execution events and pending-only batch recovery. Main chat shows compact activity above the answer, inline citation inspection, a responsive source drawer and explicit Web Search/Deep Research modes. Source filters distinguish inspected pages, partial PDFs, duplicate excerpts and snippets. UTF-8 metadata sizing is consistent with frontend limits; private research checkpoint requests remain bounded to 4 MB. Backend 349 tests/2245 assertions, Node 219 tests and browser 10 workflows passed locally, including light/dark layouts and 320–1920 px widths. Browser/provider fixtures are not production inference verification. See the subsequent release record for actual deployment progress.

### Advanced main-chat research deployment checkpoint — 2026-10-26

Secure cPanel sign-in succeeded after the previous tab expired. Existing production checkout is now `feature/advanced-web-research` at tested code `026987b6c18508500d763a7b3c24ca183eea5f43`. Private Git/configuration and compressed database rollback backups completed, gzip verified. Custom seeder and server/upload files remain preserved. Production Composer installation, additive metadata migration (batch 15), cache clearing/optimization and application return to live state completed. No database reset, unrelated migration/service or cron modification occurred.

Configured the isolated public PDF reader after privately backing up `.env`. Actual backend search returned five Bing RSS results; SQLite HTML reading returned eight passages; public arXiv PDF reading returned eight passages with real page provenance and explicitly partial 8/12-page sampling. Parser tests: two passed on production. NVIDIA/bridge key presence checks exposed no values, route remains backend, and real Ultra audit returned success/HTTP 200. Existing gateway service checks returned nonempty JSON and completed SSE (three events, delta and DONE), both HTTP 200; direct service invocation is not browser/auth-middleware acceptance.

Exact-SHA frontend production `dpl_AX9gDLv4JJ35NkyvWjwSEV4CurKp` reached READY in the existing `openmind-ai` Vercel project, with the existing chat domain assigned and no alias error. Real signed-in Web Search retrieved six sources, read the PHP manual and displayed actual passages and functional drawer filters. Auto's Bengali prose quality failed manual review. Ultra Deep Research showed three genuine concurrent searches and retained twenty sources/three page reads across refresh/resumption, but its planner timed out twice. A narrow follow-up bounds coordinator output and disables extended thinking for the verified Nemotron planner models; its tests and deployment are pending at this checkpoint. No report completion or language-quality acceptance is claimed yet.

All other environment entries were verified unchanged. Laravel's two new error headings were the corrected CLI instance-method probe and unavailable PDF probe. Initial Vercel error/fatal grouped scan was empty. Earlier deployment and fuller twelve-agent Research Studio limitations remain as documented. Public PDF reading does not provision the private experiment/export processing broker. No completion PR/merge or full-platform acceptance is claimed. Detailed positive and failed probes are in `docs/ADVANCED_WEB_RESEARCH.md`.

Coordinator timeout follow-up local checks passed: 220 Node tests, TypeScript, production build and ten browser workflows (17.8s). A test/build sequencing error was corrected by running proxy integration tests after their production build prerequisite completed. PHP/schema unchanged; new commit's CI and actual resumed-provider verification remain pending.

### Bounded planner live release and final routing correction

`3866ccc` subsequently passed all four CI jobs (`40377743612`), including fresh backend 349 tests/2245 assertions, and production `dpl_7tc36Syv83JrfyDyWsMGgJpHpz29` reached READY for the exact SHA and chat domain. cPanel safely fast-forwarded, preserving all local changes. Actual Stop, refresh and Resume retained progress, continuing from four to seventeen pages. The UI truthfully paused at an invocation budget. A later planning HTTP failure retained its checkpoint; a private direct probe of the same research context subsequently returned HTTP 200. Final manuscript/report acceptance is not yet claimed.

Ultra's actual native search function probe passed (HTTP 200). A further correction makes selected Auto Web Search prefer this successfully verified model, leaving general Auto chat and explicit selections compatible. It adds one auditable retry for transient planning 502/503/504, sharing the original deadline and cancellation. Final follow-up validation and deployment remain pending; see `docs/ADVANCED_WEB_RESEARCH.md` for exact verified versus unverified behavior.

Follow-up local validation completed: 223 Node tests, TypeScript, production build and ten browser workflows (18.3s) passed. New CI/release is still pending at this checkpoint. The actual long research checkpoint retains twenty sources/eighteen read pages; subsequent planning failure and final report acceptance remain under diagnosis. No completed report is claimed from these retained sources alone.

`6cd0701` then passed all four CI jobs (`40380408605`). The final pre-release correction prevents repeated actions from draining unrelated unread results: it permits one corrective planning turn and otherwise writes a clearly limited report, retaining mandatory initial page reading. Latest local checks passed: 224 Node tests, TypeScript, production build and ten responsive browser workflows (18.9s). Final exact-commit CI/release and actual report acceptance remain pending. Both small and saved-context unauthenticated bridge probes returned expected HTTP 401 JSON, providing no evidence of a firewall rejection; no security control was bypassed.

### Actual report delivery and final compatibility checks

`6884b15` passed all four jobs (`40381356865`) and production `dpl_B2HvAqVJryoJwQmEEGeopAR849F1` reached READY for its exact SHA and chat domain. cPanel fast-forwarded safely and stayed live. The retained Deep Research turn then delivered a persisted Bengali report with twenty sources/eighteen read pages, evidence gaps and five review flags. Language artefacts and claim concerns remain visible; delivery is not blanket factual/language-quality approval. Actual downloaded Markdown reopened as UTF-8 with twenty references and evidence limitations (8821 bytes).

Final compatibility work validates compound source/passage citations before making them inspectable, including old saved reports without rewriting content. It also reads user-supplied public URLs when search is unavailable, retains prior valid evidence and truthfully refuses if the supplied page cannot be read. Latest local checks passed: 226 Node tests, TypeScript, production build and ten browser workflows (19.5s), including the observed citation variant. Exact-commit CI/release and actual final supplied-URL/citation verification remain pending.

### Completed-response provenance persistence correction

`334f6e8` passed all four CI jobs (`40384217696`) and production `dpl_4xvyq2m5DZxAK9U1b4PvQXSemw3S` reached READY for its exact SHA/domain; cPanel synced safely. Final refresh uncovered finish validation dropping unlisted source provenance before persistence. The older completed report's source URLs survive, but its missing passages cannot be certified or silently reconstructed. Compound references without retained passage IDs correctly remain unverified.

Added explicit bounded source/passages/PDF validation and real begin/finish/history regressions. Raw/private fields are excluded; invalid provenance returns 422 without overwriting the active message. Nineteen FrontendChat tests passed (242 assertions), Pint and syntax checks passed. Corrected an initial test-environment/hosted-mode setup issue and reran deterministically with stray HTTP forbidden (5.52s). Full suite, exact-commit CI, deployment and fresh completed-response refresh verification remain pending. Existing frontend 226 tests/ten browser workflows remain the tested compatibility release.

Search configuration presence is confirmed: no SearXNG/Brave/Google CSE, public fallback enabled. Its latest live query was unavailable after earlier successes. Dedicated search-provider configuration remains an external requirement for reliable ongoing discovery; no full live-search availability claim is made.


### Provenance release verified; native citation follow-up — 2026-10-27 session

`04df1be` passed all four CI jobs (`40386931451`), including 351 PHP tests/2264 assertions, 226 frontend tests and ten browser workflows. The isolated local export failure was a missing test dependency; its later local rerun could not execute because recovered checkout lacked Composer vendor files. It is not reported as a local pass. Exact code deployed safely to cPanel and READY Vercel production `dpl_5BhTzLNGZ6U9oWJpHN5gtzndeEYy`, preserving `.env`, server files and the modified seeder by hashes. No new schema/dependency/seed/database reset or unrelated service change occurred.

Fresh signed-in Web Search completed with seventeen sources/three pages read; full refresh retained eight official PHP source passages, timestamp and DuckDuckGo provenance. Source filters/original link worked. Frontend health/admin login returned 200, private API unauthenticated returned 401; bounded log inspections found no new error headings/groups. The completed answer still had mixed-language prose, bare domain citation markers and four review flags, so it is not factual/language-quality acceptance. Older reports' lost passages were not reconstructed.

A follow-up supplies exact persisted citation links to native tools, preserves read evidence/numbering across later searches and strengthens requested-language instructions. Adapter regression covers actual search/read/search/read/finish persistence and tool results. Fifteen focused tests, 227 frontend integration tests, TypeScript/build passed locally; exact follow-up browser/CI/deployment/live retest remains pending. A temporary environment disconnect was recovered. Reliable dedicated search configuration and the separate private Research Studio processing worker remain external requirements. No full-platform completion PR/merge is claimed.

Follow-up responsive browser verification: ten workflows passed (16.2s) with the existing trusted local Chromium at all configured 320–1920 px widths. Initial execution lacked the Playwright browser, and its download returned a truncated archive; no application check was weakened. Reran using the already installed browser successfully. These browser/provider fixtures are not a live language-quality pass. Final exact-commit CI, deployment and real-provider retest remain pending.


### Stable-citation release verified; final activity correction

`bbb78d2e540a02160ab0ed052c85258d4fe32c5b` passed all four CI jobs (`40449023752`): PHP 8.2 and 8.3 each 351 tests/2264 assertions, 227 frontend tests, ten browser workflows (18.8s), build/typecheck/style/audits and runtime contract. Existing cPanel checkout fast-forwarded while preservation hashes matched; no backend schema/configuration/dependency changed. Vercel production `dpl_81X5odMbLQJ55xmWApW9jngMvMEv` reached READY for exact SHA/domain with aliasError null. Its bounded error/fatal scan returned no groups.

A fresh real native Web Search (retained conversation 30) read the supplied PHP manual after searches did not produce sources. The answer had three numeric citation buttons; clicking one opened the actual page and eight retained passages. Full refresh preserved both the inline buttons and passages. This verifies exact citation routing/new-response persistence, not semantic correctness. Bengali still contained unrelated-script fragments, and a claim about differently sized arrays was not accepted as correct. Three review flags remain visible. Prompt changes alone did not establish language/factual acceptance; no automatic rewriting of historical reports was performed.

Actual timeline inspection exposed absent terminal failure events when a native search's backend request failed, plus repeated reads of the same page through different fragment links. The final correction records sanitized failed events with the original query/URL on transport/HTTP/JSON failure and canonicalizes public page URLs before native deduplication. A real adapter regression verifies two failed search attempts, one actual read for two fragment links, persisted failure events, continued supplied-page citation and no leaked provider details. Sixteen focused tests and TypeScript passed. An initial fixture checked the event field `stage` rather than the existing `phase`; corrected fixture passed. Full local checks, exact CI/deployment and live activity retest are pending at this checkpoint. Dedicated search provisioning, model output quality and separate Research Studio private-worker acceptance remain outstanding.

Final activity correction local validation passed: 228 Node integration tests, TypeScript, production build and ten responsive browser workflows (16.0s), with no skips/failures. Exact new-commit CI/deployment/live failure-event verification remains pending.


### Current production verification — final 2026-10-27 session record

Deployed application code is `5f14eaf4ffdc7731c9f328b5f83de4343ee5e9eb` on `feature/advanced-web-research`. All four exact-code CI jobs passed in run `40449889927`: PHP 8.2 and PHP 8.3 each 351 tests/2264 assertions, frontend 228 integration tests/zero failures and ten browser workflows (19.0s), plus build/typecheck/style/security audits and runtime contract. Matching local frontend/browser checks passed as recorded above. This entry supersedes the pending release/activity-verification statements for that code; any following documentation-only commit is not a different deployed application build.

Existing cPanel checkout fast-forwarded to this exact code; `.env`, server configuration files and the custom local Nemotron seeder again matched the private preservation manifest. The application remained up. No new migration, Composer dependency, seeder run, database reset, uploaded-file deletion, worker/cron configuration or unrelated service change was made by the native follow-ups. Existing Vercel project production deployment `dpl_G1N3ejayiaFNqJSesKAGidPwJ4X4` reached READY for exact SHA, with the existing chat domain assigned and aliasError null. Prior READY releases remain rollback candidates; no rollback was performed.

Final authenticated native Web Search (retained verification conversation 31) recorded two actual unavailable search requests with explicit failed events and the original query. It then read the user-supplied official PHP manual once, produced a two-sentence English answer with two inspectable inline citations and retained eight actual selected passages. A full refresh preserved the completed response, citation routing, passages and retrieval timestamp. This verifies real failure-event reporting and successful supplied-page fallback, not successful search discovery. The heuristic audit showed zero flags for this focused English answer; that is not a semantic truth guarantee. The Bengali checks in conversations 29/30 remain failed language-quality acceptance and are not hidden by this English smoke test. The final answer did not verbally describe the search outage despite the request; the actual failed attempts are visible in the activity timeline. Richer automatic outage wording remains a quality improvement.

Final health checks: frontend health HTTP 200, backend admin login HTTP 200, unauthenticated private project API HTTP 401. Bounded Laravel heading scan found no newer error than the two already documented CLI probes; bounded new-deployment Vercel error/fatal grouping returned no groups. These bounded checks do not certify every service or historical log entry. A privacy-safe source-drawer screenshot was captured after the final refreshed citation interaction; no credentials/account details were included.

Remaining requirements: configure a dedicated supported search provider for dependable discovery; improve/revalidate Bengali model output and evidence-supported claim quality, including broader source passage coverage; provision/verify the separate private Research Studio experiment/export processing worker and finish its wider acceptance work. No full twelve-agent platform, Admin/Developer Dashboard completion, completion PR or main merge is claimed. Server secrets and user data remain preserved, and all retained verification conversations remain available.


## Literature recovery correction — 2026-10-27

The owner reported a Research Studio literature task failing after discovery/metadata verification completed. Authenticated inspection confirmed a saved chunk checkpoint, successful model responses followed by generic task errors, and dependent tasks waiting. One bounded private provider diagnostic returned matching source IDs but at least one non-verbatim model quotation. No source text, model quotation, credentials or private project prompt was printed into the public work record. Paused the active run through its normal control to prevent repeated failed work while fixing it; completed tasks, evidence and source documents remain intact.

Implemented exact source quote options: literature model output selects a bounded option ID, and the backend copies that option's literal Unicode source text. Legacy exact-quote responses and existing index/analyses checkpoints remain supported. A legacy paraphrase or invalid selection gets at most one bounded repair request using only supplied source options and claims; all repaired evidence still passes source ownership/contiguous-text validation before any candidate in that work unit is stored. Failed repair stores no fabricated evidence and leaves the checkpoint unchanged. Explicitly empty evidence records missing support and a review event; all collected evidence stays unreviewed and integrity gates are unchanged. New coverage counters distinguish tracked chunks from older checkpoint entries.

Successful chunk continuation now clears its consecutive failure/backoff state while historical failure events remain. A chunk that repeatedly fails without progress still stops after three attempts. Safe error codes distinguish quote/source mismatch, structured-output validation, changed implementation and token reservation limits. The activity card calls these consecutive failed attempts. Added eight regression tests covering Unicode/numeric source IDs, legacy quote repair, foreign/fabricated evidence rejection, explicit missing evidence, retries across three chunks, bounded repeated failure, legacy checkpoint resumption and the real inference validator/gateway with a mocked provider. The MySQL CI filter now includes this regression class.

Local verification: full Laravel suite 359 passed/2313 assertions; all 228 frontend integration tests, TypeScript, production build, PHP syntax, Pint and workflow YAML validation passed. The first focused suite invocation omitted the private local Python environment and hit the previously diagnosed export-test dependency problem; the full rerun with the proper environment passed including that real converter test. Initial local browser runs lacked PHP on PATH, then uvicorn in the test-only environment; installed committed runtime dependencies into the isolated environment and reran. Final browser/exact-code CI and production recovery verification are pending at this checkpoint. Mocked provider tests are not live post-fix verification.

Work remains on dedicated feature/research-studio, fast-forwarding from the deployed-compatible advanced-web-research ancestor without discarding its changes. Before production recovery, back up the paused task/run and code privately, verify that its declared agent input/output/tool contract is unchanged, and audit a task-specific implementation snapshot upgrade for the compatible literature checkpoint. Other task definitions/completed results must not be rebound or reset. Resume only through normal generation-fenced controls after the tested code is deployed. No database reset, unrelated service change, source deletion, secret overwrite or full-platform acceptance is authorized by this recovery claim.

Local Research Studio browser verification completed: all four browser workflows passed (26.6s), covering desktop/mobile document persistence, chat-based research start and unauthenticated proxy rejection. Corrected only the isolated test environment PATH/dependencies; no app security gate was changed. Exact-commit CI and production recovery remain pending.

Initial recovery CI (`4445cdf`) exposed a MySQL-only test assertion mismatch: native JSON reordered object keys while preserving the legacy checkpoint values/types. Corrected only that test to sort the outer object keys before strict comparison; nested analysis/list ordering and types remain strict. All eight local recovery tests (49 assertions) and Pint passed again. No application validation was weakened. Fresh exact-commit CI, including MySQL, and deployment remain pending.

## Vercel processing worker implementation — 2026-10-27

The owner requested resolution and deployment of the missing private processing worker. An actual disposable VM was created in the existing Vercel chat project; Python 3.14 executed as UID 1000 and an outbound TCP probe was blocked by deny-all networking. A separate trusted runtime build has installed the document/experiment dependencies and Python 3.12.15. These are infrastructure probes, not full application acceptance.

Implemented an opt-in Vercel transport, a private short-lived transfer protocol for large PDFs/results, an authenticated Next.js broker, per-job offline disposable microVMs, cancellation fencing after VM creation, immutable-snapshot provenance, runtime build instructions and seven broker/five backend regression tests. The original Docker transport remains supported. No production configuration has changed at this checkpoint.

Seven new broker tests and TypeScript passed. Initial all-frontend invocation ran before a production build existed and its proxy fixtures failed startup; the production build and proper full rerun are underway. Backend tests, actual runtime export/experiment/cancellation checks, exact-commit CI and production deployment remain pending. This checkpoint is not a completion claim.
