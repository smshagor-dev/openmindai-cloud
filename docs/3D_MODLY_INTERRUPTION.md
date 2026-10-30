# Interrupted 3D workbench checkpoint — 2026-10-29

Required branch: feature/3d-modly-capabilities. This is unfinished work, not a release.

The workspace became unavailable during the second checkpoint upload. Commit 099ae3bbcf628704425e06b793e19bf17f1b3189 preserves the completed portion of that transfer. The earlier complete source checkpoint is 733f03c2e7dad8e29083f61952c63465a2b3a427.

## Verified evidence and limits

At 733f03c, GitHub Actions run 40943811414 passed the real CPU worker integration, signed PHP/HTTPS bridge and bounded Docker tests. Backend/frontend run 40943811330 passed PHP 8.2 and 8.3 full suites, formatting, composer audit and runtime contracts. Its frontend typecheck, build, 243 integration tests and web-research browsers passed; the 3D browser step failed after page integration. Browser fixtures were subsequently adapted in 099ae3b, but that revision's CI has not been observed.

Before interruption, later local source passed TypeScript, webpack production build, 21 targeted PHP tests / 136 assertions, 243 integration tests, 9 actual CPU Python tests and the signed PHP/HTTPS worker smoke. A further proxy allowlist test passed individually. These local checks do not certify the partially uploaded remote tree. The full local PHP suite was still running with at least one failure when the environment disappeared; do not report it as passed.

## Resume checklist

Recover the required branch and inspect its actual files before rebuilding changes. Reconcile these later local edits that were not included in the partial tree transfer:

- frontend asset viewer: exception-safe disposal of geometry/materials/textures/renderer/controls/listeners/observer, plus native procedural JSON preview.
- frontend workbench: trash listing/restore, explicit initial refresh errors, parent-version fallback. Its backend trash endpoint is saved, but the corresponding UI may still be the earlier version.
- backend proxy: forward the bounded trash query parameter; otherwise trash lists show active assets.
- runtime mesh: validate hidden as boolean; native scene + composition geometry test (9th test).
- runtime server: check an existing idempotent job before rejecting a new storage allocation.
- frontend/tests/studio-workbench.test.mjs: method/path allowlist tests.

The latest saved page includes Auto Detect, engine options, asynchronous job submission, scene editor and submission locking; browser fixtures now target that API. Finish responsive/browser acceptance, harden cancellation/restart tests, inspect project/version workflow completeness, and run all applicable checks on the final exact remote head. Review generic mesh part editing, thumbnails and visual DAG positioning against the user's original acceptance list; those are not certified complete.

No GPU host, approved runtime image, rights-reviewed extensions/weights or actual model generation was available. All five GPU families remain gated. Do not claim image-to-mesh or complete Modly parity. Do not merge or deploy this feature until its actual acceptance requirements are met. The existing production application and Research jobs were not changed during this work.
