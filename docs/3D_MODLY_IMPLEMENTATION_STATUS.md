# 3D workbench integration — working checkpoint, 2026-10-29

This is implementation in progress on `feature/3d-modly-capabilities`, not a production release or completed capability parity. Existing deployed fixes from `e9810ff` are merged without rewriting the specification branch history. Production remains the earlier tested release; no new model, worker or migration is deployed by this checkpoint.

## Reviewed source and application boundaries

Modly stable v0.4.3 is pinned to `f11a4d2bd9fe994b9d30f76a36cb6c18ac8733ea`; checkout HEAD reviewed separately was `a471509b164b02bc573f4eafbe2dbf5149c1d6d1`. Inspected actual API generation/workflow/export routers, generator registry/BaseGenerator/runner/extension process, model source validation, mesh operations, CLI tests, Electron installation/hardware adapters, frontend workflow/store/node code and test structure. Desktop Electron IPC and unrestricted installation are not copied into the web application. Upstream smooth_progress fabricates intermediate percentages; this implementation uses durable execution phases and node states instead.

The existing Laravel/Sanctum controller boundary, Next.js cookie proxy, private upload store, SceneStudioController/StudioScene, Three.js viewer, account ownership, NVIDIA gateway and shared 40 RPM admission remain in place. Research code and runtime are preserved. New code is additive under app/Services/Studio, StudioWorkbenchController, StudioJob/queue jobs, runtime/studio and workbench components.

## Immutable extension and weight review

| Family | Extension revision | Model source revision | Runtime/license status |
|---|---|---|---|
| Hunyuan Mini | c927d5657249f91d09bb912900d5d4f7b26725fb | tencent/Hunyuan3D-2mini f90a0f7df7d5e6f71109cf333f6a95a0ae3194a6 | No extension LICENSE found; Tencent model terms require review |
| Hunyuan Mini Turbo | 9457b71b6e3ee5436240cac0d6c1bbcd02e9f53d | same pinned model source | Same review gate; independent subfolder/steps |
| Hunyuan Mini Fast | 56a0bab6b039d9922d3ab228941eb8cd0dbb9ff2 | same pinned model source | Same review gate; independent subfolder/steps |
| TripoSG | cc30cb8bedddf8141e78dc6cf88a4cb1a305d128 | VAST-AI/TripoSG 2c1c516d22d58db486a058d98d31bb6177344e06 | No extension LICENSE; model MIT label does not cover all bundled dependencies |
| TRELLIS2 GGUF | db287c0c37241c354bb4addf2ca5f53ba0d866f3 | Aero-Ex/Trellis2-GGUF edf81aef93af0823ac8721da3c9cd3c52d539f87 | No extension LICENSE; weight/runtime dependency rights require review |

The facts, parameter contracts, quantization variants, manifest SHA-256 and generator SHA-256 are recorded in runtime/studio/catalog.json. No extension source or model weights are redistributed. Upstream setup/load code can fetch mutable main.zip archives or install packages at runtime; it is not run here. GPU execution requires a separately reviewed digest-pinned image, pinned source labels, read-only weights, signed administrator installation attestation and a real generation smoke before readiness. Runtime containers have no network, writable root, extra capabilities, host source path or Docker socket. They run with CPU/RAM/PID/time limits. CUDA only is considered by the adapter; ROCm/MPS are not certified.

Primary sources: https://github.com/lightningpixel/modly, each pinned extension above, https://huggingface.co/tencent/Hunyuan3D-2mini, https://huggingface.co/VAST-AI/TripoSG, https://github.com/VAST-AI-Research/TripoSG/blob/main/NOTICE, https://huggingface.co/Aero-Ex/Trellis2-GGUF. Model labels are not interpreted as blanket commercial permission. An administrator must record the exact pre-existing rights review before installation; software does not accept legal agreements for users.

Based on Modly (https://github.com/lightningpixel/modly), by Lightning Pixel (https://github.com/lightningpixel). Capability architecture is independently implemented; preserve upstream notices if later licensed code is incorporated.

## Capability matrix at this checkpoint

| Capability | State / evidence |
|---|---|
| Existing procedural/Architecture, private uploads, compatible preview | Preserved, regression tests pass |
| Engine registry / deterministic routing / manual-mode precedence | Backend implemented and fixture tested; GPU unavailable by default |
| Private asset/project/schema and durable idempotent worker jobs | Implemented; ownership, late cancellation and duplicate request tests pass |
| Signed worker transport / nonce replay protection / private artifacts | Python service tested; real PHP → signed HTTPS → CPU job → persisted GLB bridge passed |
| CPU mesh import/inspect/transform/smooth/decimate/repair | Real operations tested; 320-face mesh reduces to 160 faces |
| GLB/GLTF/OBJ/STL/PLY / self-contained scene JSON | Real conversion/reopen checks; external file/URL resolution rejected |
| Typed workflow graph / persistent execution | Backend typed DAG and procedural prompt workflow implemented; recovery reuses a persisted scene, uncertain provider outcomes fail without automatic rebilling |
| Per-part procedural edits / saved revisions | Backend implemented/tested; editor now wired into the page, browser acceptance pending |
| Frontend library / workflow / job/model panels | Components wired with Auto Detect, engine options, durable jobs, trash/restore and private asset preview; browser acceptance pending |
| GPU extension adapters / model installation and rollback | Operator-only digest/container/download/attestation interfaces implemented; no real GPU/model installation or inference verified |
| Complete parity, real image-to-mesh / text-image-mesh | Blocked by GPU/runtime, extension/model license review and actual model inference acceptance |

Local checks: **21 PHP targeted tests / 136 assertions**, **9 Python tests** with actual CPU mesh operations, frontend TypeScript passed. Real cross-language signed HTTPS smoke also passed: a box remained 12 faces and its minimum X moved to 2.5; repeated submission reused the same durable job. These counts are working-checkpoint checks, not full-suite or real GPU evidence. Production webpack build and all **243 frontend integration tests** pass. The native scene/compose test verifies hidden geometry and transforms. Browser tests have been updated for the durable API but local Playwright Chromium download returned a truncated archive; CI browser acceptance is still required. Next work: cancellation/recovery hardening, independent converter/browser acceptance and complete applicable CI. Operator provisioning is described in 3D_WORKER_OPERATIONS.md. Do not merge or deploy this unfinished work as production-ready.

## Recovery and verification follow-up — 2026-10-29

Recovered remote checkpoint `6cd41a8` into an isolated checkout and reconciled the later local changes: native procedural asset previews, exception-safe viewer cleanup, trash/restore controls, bounded trash-query forwarding, parent-version navigation, and strict boolean visibility. Existing unrelated working files were preserved.

Fixed narrow-screen mode wrapping and file-input/grid sizing rather than hiding page overflow. Browser acceptance now covers 1440, 768, 375 and 320 pixels, both procedural Object and Architecture, real WebGL and downloaded GLB headers. Expanded the flow to move geometry, undo/redo, save a revision and reopen the persisted edits through fixture APIs. These browser checks are not GPU or real-provider evidence.

Worker retries now check existing idempotent requests before requiring new storage capacity, under a SQLite immediate transaction. Completion atomically checks the running state; a cancellation racing artifact publication removes the unpublished artifact. Added actual CPU tests for cancellation, restart recovery, storage-pressure retries, native-scene visibility/transforms and composition. GPU adapter cleanup uses a finally block and rejects symlink outputs before resolving paths; real GPU adapter execution remains unverified.

Frontend production webpack build and 244 integration tests pass. Python worker and exact final browser counts are recorded in the next verification entry. The initial local full PHP run executed 404 tests with one failure caused by missing `citeproc-py-styles`; installing the declared research dependency made that exact export test pass (1 test / 12 assertions). Full-suite rerun is pending; no all-green claim is made for that initial run.

External production blockers remain concrete: this environment exposes no CUDA GPU device, no Docker executable, no configured private Studio worker URL/key, no reviewed GPU runtime image and no rights-approved model installation. No real neural image-to-mesh or text-image-mesh inference is certified. Provision the isolated worker according to 3D_WORKER_OPERATIONS.md and perform each model's real smoke before enabling it. Full product parity (including all generic mesh editing/workflow/browser scenarios) still requires acceptance work; this is a verified progress checkpoint, not completion, a main merge or deployment. Existing live Research/3D release was not changed.

### Completed follow-up checks

Source checkpoint `6286d0f8700f3145c4237f91691982499854704f` is verified reachable from the required remote branch. Local exact-source checks passed: PHP 8.3 **404 tests / 2589 assertions**, Pint, frontend production webpack build, **244 integration tests**, **10 Studio browser tests**, **13 Python worker tests**, and the real signed PHP/HTTPS CPU bridge. Browser flows cover 1440/768/375/320 widths, both modes, transforms/undo/redo, revision persistence, reopening, GLB download, private Markdown references, compatible rendering and clear errors. Browser APIs use fixtures; CPU geometry and bridge are real. GitHub worker-and-bridge run 40954918954 passed including actual bounded Docker execution; runtime-contract also passed. General frontend/PHP matrix CI is still running at this record. No real GPU inference, full Modly parity, main merge or production deployment is claimed.

## Production candidate preparation — 2026-10-30

Merged current main `209888f` (shared startup layout and responsive audit) with the workbench branch. Conflict resolution preserves Auto Detect, uploads, asynchronous jobs, editing/history, hidden geometry and compatible rendering, and incorporates main's shared welcome screen, wrapped headings and browser configuration. GLB exporter remains lazy loaded and Lambert preview materials are converted for export. Sidebar anchors explicitly suppress decoration.

Added read-only `studio:preflight` and `--require-gpu`, checking additive schema, HTTPS transport without embedded credentials, configured key length, signed protocol/processors and verified catalog engines. It returns nonzero for missing dependencies without exposing secrets. It explicitly does not certify queue-consumer liveness or actual inference. Three regression tests cover missing credentials, CPU-only readiness and unsafe transport. Added digest-pinned, non-root, bounded CPU Compose deployment with persistent state and loopback-only ingress, plus release/rollback instructions. No host, key, TLS ingress or GPU license is provisioned by these files.

Targeted exact-checkout backend tests passed: 24 tests / 146 assertions. Production webpack build, Pint and the 10 Studio interaction tests passed after merging main; 244 frontend integration tests passed before the final sidebar-decoration correction. Broader responsive and full 407-test backend reruns are in progress. Final exact-code CI must pass before release. No production migration, worker installation, model enablement, main merge or deployment has been performed.

External blockers: a persistent CPU worker host with private HTTPS ingress and Laravel deployment access, and a compatible GPU worker with approved immutable runtime/weights and rights review for neural generation. The browser session inventory currently contains no open deployment tabs. Full neural 3D production readiness cannot be accepted without those resources and real model/user smoke tests. No infrastructure purchase or license acceptance has been made.

## Recovered exact-source verification — 2026-10-30

The previous worktree's parent Git metadata and dependency links became unavailable. Recovered branch `89c5ead` from GitHub and reconciled the surviving production changes in a fresh standalone checkout. Remote merge checkpoint `9f35e17388bd2bb1d0753e16568812b57644e354` records main `209888f` as an additional parent and preserves the existing workbench branch history.

Fresh-checkout verification passed: PHP 8.3 **408 tests / 2605 assertions**, **244 frontend integration tests**, production webpack build, **35 responsive browser tests**, **10 initial Studio browser tests**, **13 CPU worker tests** and the real PHP/signed-HTTPS/CPU geometry bridge. The first broken-worktree responsive results are superseded by these fresh-checkout checks; they were not used as release evidence.

Visual inspection found a mixed dark-page/light-workbench palette. Added an explicit Studio theme preference applied consistently to every panel, styled native selectors/file controls, and persisted light/dark selection. Expanded Studio acceptance to both default and WebGL-disabled renderers at four viewport widths, plus theme reload persistence. Enabled the Chromium/Firefox/WebKit matrix for this feature branch. Queue preflight now refuses retry visibility shorter than Studio execution/consumer timeout, and always reports consumer liveness unverified. Added server-only worker settings to the environment example and CI validation for the bounded Compose configuration. Final expanded browser/cross-browser CI results remain pending at this checkpoint.

The user explicitly confirmed there is **no separate worker server yet**. Therefore no live CPU/GPU worker host, private TLS ingress, approved GPU runtime/weights or actual neural generation can be configured or accepted in production in this session. Deployment configurations and passing local/CI checks do not create that infrastructure. No purchase, model-license acceptance, main merge or production deployment has been performed. The completed available source is a release candidate with disabled/unverified processing capabilities, not full Modly parity or full production acceptance.

### Cross-browser follow-up

On source `aa1d0d6`, PHP 8.2/8.3 CI both passed the full **408 tests / 2607 assertions** and audit. Research backend, MySQL, browser and actual isolated worker checks passed. Chromium and Firefox passed responsive/Studio checks. WebKit exposed transient navigation errors in one run and two early-typing timeouts in another; the timeout snapshots showed an empty prompt before generation. The same source's retried WebKit job passed, so these failures are recorded rather than hidden.

Added a client-initialization guard to the composer/mode/upload controls so typing and clicks cannot be discarded before hydration attaches the handlers. A delayed-JavaScript browser regression checks the server-rendered controls are disabled, become enabled after initialization, and retain the entered prompt. That regression passes locally on the rebuilt exact source. Studio failures now retain Playwright traces. The final expanded matrix still needs to pass on this follow-up commit; no live CPU/GPU host or production acceptance is claimed.
