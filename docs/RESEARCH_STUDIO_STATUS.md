# Research Studio — implementation checkpoint

Updated: 2026-10-25 (Europe/Moscow).

## Current remote state

- Working branch: `feature/research-studio`.
- Base: `main` at `6a42d5b55e35f93e792f5abad629c9aa66e3dee3`.
- This checkpoint establishes remote-backed work instructions and a truthful continuation record.
- The earlier local-only attempt was unavailable. The application is being rebuilt on this remote-backed branch; historical test counts do not verify the rebuilt code.
- No Research Studio PR, merge or deployment has occurred.

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

Added 19 research tables; scoped models/access; immutable manuscript revisions; source-linked evidence; hash-bound integrity gates; fifteen independently executable components (planner, twelve specialists, Q&A and selection editing); existing-gateway JSON inference; Crossref/OpenAlex discovery; protected licensed PDF retrieval; scoped lexical/optional real embedding retrieval; dependency orchestration, three-task concurrency, claim/generation fences, bounded retries, pause/resume/cancel and lease recovery. PHP syntax checks passed on new models/services/jobs. Application APIs, UI and worker wiring are still in progress. No live provider or Docker verification is claimed.

## Checkpoint 2 — authenticated APIs and processing jobs

Added 25 authenticated/scoped research routes; project creation/settings; short SSE activity delivery; versioned document saves/revisions; reference import; authorized private PDF/CSV/figure uploads; queued PDF extraction with replacement checks; evidence review; accepted invitations/roles; hash-bound approvals; explicit experiment authorization; frozen export snapshots; private checksum-verified downloads. New jobs require a configured private worker and do not execute uploaded/generated code in PHP. Route registration and PHP syntax checks passed; baseline/full integration tests are running. Frontend and private worker implementation are not complete at this checkpoint.

## Checkpoint 3 — integrated UI, private broker and first passing research flow

Added the `/research-studio` route and Cloud navigation; all fourteen workspace views; Tiptap rich manuscript editor with tables, equations, footnotes, citations, autosave/revisions; six real CSL styles; responsive themes and real SSE agent/browsing events; scoped authenticated proxy/download support. Added authenticated private broker, per-job document processing containers, approved scientific experiment container protocol and five actual document export converters.

New backend ResearchStudioTest: 11 tests passed with 84 assertions, including Bengali/English/Russian fixture workflows and a real Markdown converter export after evidence review/human approval. These model/index responses are deterministic mocks, not live inference. TypeScript and production frontend build passed. Worker converter checks exposed Markdown writer citation rendering, which was corrected; rerun in progress. Real Docker tests remain unavailable locally. Browser end-to-end, Unicode PDF, additional concurrency/security checks and final acceptance remain in progress.

## Checkpoint 4 — browser tests, Unicode and concurrency hardening

Added reproducible Playwright fixtures/runner, an actual two-PHP-process source-lock/deduplication test and six-style/numeric citation tests. Added glyph-checked Bengali/Devanagari/Arabic/CJK PDF font switching with Arabic bidi, portable LaTeX font requirements, stricter worker input/output bounds, separate unprivileged experiment child execution and persistent execution/cancellation guards. Added consistent figure ownership checks and locked export gate checks. Browser tests and final suites are still being debugged; no completion or live integration claim is made. GitHub CI now includes actual Docker worker tests, backend and browser jobs.

Actual checks at this checkpoint: 11 research backend tests passed (84 assertions), two actual PHP processes overlapped and deduplicated one source, all three frontend research unit tests passed (including six CSL styles and ordered numeric citations). Mobile browser editing/refresh passed; desktop browser revealed a fixture interaction with a temporarily disabled upload control, now corrected for rerun. Production build passed before the latest citation fix; final rebuild remains required.
