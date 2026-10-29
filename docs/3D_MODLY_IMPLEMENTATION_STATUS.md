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
