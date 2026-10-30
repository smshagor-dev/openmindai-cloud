# OpenMind Cloud 3D Studio — Modly Capability Parity Plan

Status: implementation in progress on `feature/3d-modly-capabilities`; see `3D_MODLY_IMPLEMENTATION_STATUS.md` for tested capabilities and remaining acceptance. **Not production-verified or deployed.**
Upstream: https://github.com/lightningpixel/modly (reviewed 2026-10-29).
Existing: /3d-studio Object/Architecture procedural scene generation, auth, scene ownership, history, Three.js viewer and GLB/JSON export. Preserve this functionality.

## Product contract
- One 3D Studio entry with mode selector: Auto, 3D Model, 3D Architecture. An explicit manual mode must never be overridden by routing.
- Tools dropdown: Auto Engine, Procedural, approved Hunyuan3D Mini/Mini Turbo/Mini Fast, TripoSG, Trellis2 GGUF, registered process extensions, Architecture. Never show an uninstalled or unhealthy engine as available.
- Inputs: text prompt, uploaded image(s), validated existing mesh/scene. Uploads must use private storage with MIME and decoded-content validation, size/pixel limits and authenticated ownership.
- Auto routing: deterministic preference for architecture vs object first; model intent without image routed to a *verified* text-capable pipeline, otherwise explicit image-generation intermediary only with user consent. Nemotron is an intent/scene planner, not the mesh generator.
- Viewer: scene orbit/pan/zoom, part selection, materials/textures when available, reset, real progress/events, responsive UI and history. Preserve GLB and JSON export; add OBJ/STL/PLY where geometry conversion is actually supported.
- Mesh operations: smooth, decimate, normal repair, inspect topology, optionally retexture; operations must return concrete new artifacts and preserve originals/version history.
- Workflow editor: typed DAG nodes for image, prompt, import scene, selected model generation, mesh process, preview, save/export; validate port types and cycles before executing; durable retry, cancel, recover.
- Library: private models, thumbnails, metadata, version history, ownership, download/delete, source attribution, job provenance.

## Execution architecture
Browser (Next.js) -> authenticated Laravel API -> queues, DB jobs, private object storage -> isolated Python GPU worker(s) -> vetted model extension adapter -> mesh artifacts -> preview/export.

- Existing cPanel/PHP host remains API/orchestrator, **not** GPU inference. Real mesh generation requires external GPU execution service with access controls, runtime dependencies and enough VRAM.
- Separate control-plane extension registry from worker code. Curate/review approved repositories; do not execute arbitrary GitHub URLs, shell commands, Python, or model-supplied code requested by an ordinary user.
- Extension manifest registry: id, version, trusted source, signed/pinned ref, compatible runtime, capabilities, artifact formats, hardware and memory requirements, model sources, quantized weight variants, shared weight groups, integrity checks and install status.
- Reproduce Modly's useful model source/variant/shared-weight concepts using checksums, immutable install records, path traversal defenses, bounded disk quotas, download cleanup and license review.
- Worker lifecycle: registration, signed job claim/lease, heartbeats, capacity/concurrency limits, cancellation, bounded retries, progress SSE, encrypted service secrets, artifact-scoped signed URLs, failed-job cleanup.
- Per-user jobs and artifacts must enforce authorization on every read/write. Charge only according to existing gateway/accounting policy and record expensive GPU resource usage explicitly. Rate limit the expensive operations.
- Persist workflow definitions, job/run state, node output references, engine and model revision, logs/errors, and artifact versions; never trust client-side state as authoritative.
- Keep all existing database records and production uploaded files intact through additive migrations.

## Upstream capability checklist
- [ ] Prompt-to-real-mesh and image-to-mesh with generation backend
- [ ] Approved engine catalog incl. Hunyuan3D Mini, Turbo, Fast, TripoSG, Trellis2 GGUF
- [ ] Model versions/quantization/weights and hardware compatibility display
- [ ] Extension and process adapter manifest contract with safe installation
- [ ] Multiple Hugging Face sources per model, shared weight groups, independently installable variants
- [ ] Workflow graph editor, validation, execution, recovery and cancellation
- [ ] Scene manifest import/export and typed scene artifacts
- [ ] Mesh import, smooth, decimate, normal repair, process history
- [ ] 3D interactive preview, material inspection, export GLB/OBJ/STL/PLY
- [ ] User model collection, saved assets, versions and privacy
- [ ] API/CLI-equivalent authenticated automation for health, models, run status/cancel, generate/export
- [ ] Honest install/unavailable/queued/running/error states; no simulated success
- [ ] Mobile/tablet/desktop accessibility and dark/light theme
- [ ] Security, unit, browser, queue, worker and real GPU/provider end-to-end testing

## Delivery gates
1. Inspect current 3D Studio code and migrations and map routes/components; no rewrites that regress existing Object/Architecture.
2. Implement typed engine catalog and routing contracts, private upload/asset APIs and job schema + integration tests.
3. Implement isolated worker protocol and one real engine end to end with actual GPU validation (prompt/image -> mesh -> persisted artifact -> downloaded GLB).
4. Add remaining approved models and verified weight/variant management, test individual engine compatibility.
5. Add workflows and mesh processing, multi-format exports, model library and UX.
6. Run Laravel, frontend, worker, browser and GPU E2E; PR only with evidence. Merge after green CI; deploy separately only after infrastructure and real provider acceptance.

## Licensing
Review the upstream repository LICENSE and every model/weight/extension license before incorporating upstream source. Preserve notices/attribution as required. Reimplementing features does not imply upstream code is copied. Do not promise commercial availability without compatible rights.

## Current verified state
The prior procedural studio status is in docs/3D_STUDIO_STATUS.md. This document tracks **work still required** for parity. Creating this plan does not satisfy any checkbox above.

## Implementation checkpoint — 2026-10-29

Actual code is now in progress in the required branch; see `docs/3D_MODLY_IMPLEMENTATION_STATUS.md` for reviewed pins, implemented contracts, CPU geometry evidence, incomplete UI integration and concrete GPU/license blockers. The checklist above remains the full acceptance scope, not a claim that every item is complete.
