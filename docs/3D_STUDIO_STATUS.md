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
- Backend PHP 8.2/8.3 execution is running in CI; local PHP is unavailable. No live-provider acceptance claimed.

## Release requirements

Run `composer install`, `php artisan test --filter=SceneStudioTest`, `vendor/bin/pint --test`, frontend typecheck/build/integration, and `node e2e/studio-run.mjs`. Apply additive migration with `php artisan migrate --force` on release. No worker is required: this feature renders a bounded procedural JSON scene in the browser and does not execute generated programs.

Verify the deployed Ultra model with a real Object and Architecture prompt using a normal user account before claiming live generation acceptance. Preserve deployment configuration, uploads and unrelated files. No deployment or merge has been performed.

## Limits

Procedural primitive models are illustrative, not sculpted/photogrammetric meshes or engineering CAD. The first version supports up to 80 parts and 120 connections. GLB exports include geometry/materials and component names; DOM text labels are visible in the viewer and remain available in JSON, but are not baked into GLB. History lists the most recent 100 scenes. A failed upstream request does not save a scene. A schema-invalid completed model response may still consume existing gateway credits. Client disconnect recovery/idempotent retries are not implemented: check saved history before resubmitting after a network error.
