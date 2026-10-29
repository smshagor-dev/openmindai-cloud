# 3D Studio

Branch: `feature/3d-studio`, based on main `61c34c0`.

## Implemented

- New chat sidebar menu and `/3d-studio` workspace with Object and Architecture modes.
- Prompt-driven bounded procedural scenes via the existing authenticated OpenMindAI Ultra gateway, with existing quota accounting and provider configuration. No credentials in frontend.
- Strict scene schema: five primitive shapes, finite bounded transforms, colors, unique node IDs, bounded component counts and valid directed connections. No generated code execution or external assets.
- Private database-backed history, reopen and delete; regeneration creates a separate scene.
- Lazy-loaded Three.js viewer with orbit, pan, zoom, reset, part inspection, service/connection labels, GLB and JSON export. Labels use textContent, never generated HTML.
- Responsive desktop/mobile layout and explicit pending/failure states.

## Verification at implementation checkpoint

- Frontend production build passed, including `/3d-studio`.
- TypeScript check passed.
- Existing frontend integration suite: 235 passed, zero failed/skipped.
- Added backend tests for authentication, ownership, persistence/deletion, invalid input/output, lock release and unsafe scene data.
- Added desktop/mobile browser tests for both modes, GLB header verification, history and provider failure handling.
- Local production browser suite: all 5 tests passed (desktop/mobile Object and Architecture, real WebGL rendering, GLB magic-header verification, history/deletion and authentication/provider error handling). Browser APIs used fixtures; this is not live-provider verification.
- PHP 8.3 targeted backend suite passed in CI: 6 tests, 26 assertions, including the real application gateway with a fixture HTTP provider. Local PHP is unavailable. PHP formatting corrections and final PHP 8.2/8.3 CI are being checked. No live-provider acceptance claimed.

## Release requirements

Run `composer install`, `php artisan test --filter=SceneStudioTest`, `vendor/bin/pint --test`, frontend typecheck/build/integration, and `node e2e/studio-run.mjs`. Apply additive migration with `php artisan migrate --force` on release. No worker is required: this feature renders a bounded procedural JSON scene in the browser and does not execute generated programs.

Verify the deployed Ultra model with a real Object and Architecture prompt using a normal user account before claiming live generation acceptance. Preserve deployment configuration, uploads and unrelated files. No deployment or merge has been performed.

## Limits

Procedural primitive models are illustrative, not sculpted/photogrammetric meshes or engineering CAD. The first version supports up to 80 parts and 120 connections. GLB exports include geometry/materials and component names; DOM text labels are visible in the viewer and remain available in JSON, but are not baked into GLB. History lists the most recent 100 scenes. A failed upstream request does not save a scene. A schema-invalid completed model response may still consume existing gateway credits. Client disconnect recovery/idempotent retries are not implemented: check saved history before resubmitting after a network error.


## Integrated deployment candidate — 2026-10-29

The owner merged PR #67 at 4004773 and authorized deployment and live verification. The release/3d-studio-deployment integration retains the deployed Research recovery fixes, patched Symfony Mime 7.4.22 and current dependency override while incorporating main's 3D Studio. Merge conflicts preserve both worker-transfer and scene routes, the exact Research Studio label and the new 3D Studio menu. Added internal scene JSON request metadata to use the gateway's known non-thinking setting for the verified Nemotron models; ordinary chat and other research agents retain their previous behavior. The real-gateway fixture test now checks that provider payload. Full integrated CI, deployment and actual Object/Architecture provider acceptance are pending. No research budget, model enablement or evidence gate changed.

## Production deployment and actual provider verification — 2026-10-29

Deployed application SHA `2cc982bf1c40827d9a57ef5637de22d2cf94844f` on `release/3d-studio-deployment` integrates merged main PR #67 (`4004773`) with the existing deployed Research recovery fixes and patched Symfony Mime 7.4.22. Main itself was not modified by this deployment session. This integration avoids replacing the newer production Research code with main's older version.

All exact-code general, Research Studio and 3D checks passed: PHP 8.2 and 8.3 full suites each **387 tests / 2466 assertions**, 3D contracts on both versions, MySQL, actual Docker worker, frontend integration and responsive browser checks. Composer audit reported no advisories. Local frontend typecheck, production build and all **243 integration tests** passed; local PHP/browser runtime was unavailable, so executable PHP and WebGL browser checks were performed in CI.

Private code/configuration rollback files and a compressed database backup (656662 bytes, gzip integrity checked) were created before release. cPanel switched safely to the integrated branch; locked production dependencies, additive studio_scenes migration (batch 16), cache clear/rebuild and preservation checks succeeded. Environment, customized Nemotron seeder and hosting hashes matched. No database reset, upload deletion, research budget increase, model enable-flag change or unrelated cron/service change occurred.

Matching existing Vercel project production deployment `dpl_4n5cXFiuRQrMtxVo1BwP6fjyq9uE` reached READY for the exact SHA with chat.openmindai.org assigned and aliasError null. One premature frontend build was cancelled before readiness; the released build followed the completed checks. Bounded new-deployment error/fatal grouping returned no entries. Backend admin login returned HTTP 200; unauthenticated private scene API returned HTTP 401.

Actual provider checks used the existing ordinary user, real controller/gateway, quota accounting, unchanged strict schema and database persistence, with an in-memory user token rather than creating or exposing credentials. Both actual NVIDIA Nemotron 3 Ultra 550B requests returned HTTP 200, finish stop and zero reasoning characters with thinking disabled. Object creation returned HTTP 201 in **12.01 s**, six parts and zero edges; Architecture creation returned HTTP 201 in **18.60 s**, four nodes and three edges. Both records reopened identically through the real controller. These probes are real inference, not fixture provider output; the CLI generation probes alone do not certify browser authentication middleware.

The signed-in live browser independently listed and reopened both saved scenes through the deployed frontend/API. Architecture's actual JSON download reopened as UTF-8 JSON, **1775 bytes**, four nodes and three edges. The live Cloud Browser reported WebGL unavailable, so actual on-screen 3D rendering and live GLB download could not be accepted in that browser. CI's five browser workflows passed with software WebGL; those fixture scenes do not replace live rendering acceptance. Do not describe the live Cloud Browser renderer/GLB check as passed.

The broader retained Research run remains budget-blocked with completed Literature preserved, as documented in RESEARCH_STUDIO_STATUS.md; this 3D release does not claim full Research/manuscript acceptance.

## Uploads and compatible preview working checkpoint — 2026-10-29

The owner authorized unlimited cumulative Research token usage (the known provider constraint is 40 requests/minute), complete continuation, further 3D fixes and user reference uploads. This working branch removes the default cumulative run cap while preserving usage accounting and optional explicitly configured caps; shared backend NVIDIA admission uses a locked rolling 60-second/40-request window. Research rate waits retain checkpoints and do not consume consecutive-failure attempts. Structured research requests reserve output for JSON instead of extended hidden reasoning.

Added private original-file storage/download and owned scene references for Markdown/text/CSV/JSON, DOC/DOCX/PDF and PNG/JPEG/WebP. Documents are parsed through existing bounded client/server preview helpers; extracted reference text remains explicitly untrusted. Image references use a verified vision model before the scene model, or fail clearly while retaining the upload if none is available. Originals are checksum-checked; foreign uploads cannot be selected or downloaded. Scene history retains reference IDs. Original files have a 3 MB transport bound and private storage quota; these are file-safety limits, not cumulative inference-token caps.

Added SVG preview fallback and GPU-independent GLB export when WebGL cannot initialize. Existing WebGL remains the preferred renderer. Local TypeScript, production build and 243 integration tests passed. New PHP ownership/image/rate-wait regressions and a browser upload/fallback/GLB flow are pending executable verification. Nothing from this checkpoint is deployed yet; the existing completed Research evidence remains preserved.
