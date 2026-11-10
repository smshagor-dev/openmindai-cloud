# Free full 3D on shared hosting

The `hf-trellis-2` engine uses the official public `microsoft/TRELLIS.2` Hugging Face Space. It is preferred for image-to-mesh Auto routing when enabled. No FAL key, local GPU, Python installation or resident inference daemon is required. Existing paid engines remain explicit choices; quota exhaustion never triggers a paid fallback. Procedural scenes and explicit 2.5D relief remain separate capabilities.

## Operation and limits

An authenticated image-model request creates a private durable job and returns 202. Web requests and job polling never hold a GPU stream. The ordinary Laravel scheduler invokes `studio:free-run` in the background: a single 240-second consumer uploads the owned image preview, preprocesses it, generates latents at 512 resolution and exports a textured GLB using one Gradio session. The session and provider event identifiers stay private. Provider HTML previews are never rendered. The GLB is downloaded from the exact Space origin without credentials, DNS-pinned to public addresses with bounded size/time, structurally validated and saved in the private asset library. No platform API-credit usage is charged for this engine.

Admission is serialized with a shared cache lock. Limits are counted from persisted jobs, including failed/cancelled attempts, so clearing a cache or retrying a terminal request cannot reset the hourly/daily budget. Defaults:

| Scope | Limit |
| --- | --- |
| User | 2 admitted requests per UTC day, 1 per rolling hour |
| Server | 2 admitted requests per UTC day |
| All users | 1 queued/running job at a time |
| Provider exhaustion | 1-hour server cooldown |

Exact request-key replays return the original job without using another slot. Changed inputs conflict. HTTP 429 includes Retry-After. The daily caps are configurable but do not create or expand Hugging Face GPU quota.

Cron must run `php artisan schedule:run` every minute. `studio:free-run` has a global 300-second lease and runs at most one queued job per invocation. A crashed session is failed with an uncertain outcome; it is never automatically submitted again. Cancellation fences publication, requests provider cancellation when an event is known, and does not claim physical GPU cancellation. Do not delete/reset user data to recover quota. Use a cache store shared by all application processes; a per-process array store is only suitable for tests.

## Configuration

```
STUDIO_FREE_3D_ENABLED=true
STUDIO_FREE_3D_HF_TOKEN=
STUDIO_FREE_3D_USER_DAILY=2
STUDIO_FREE_3D_SERVER_DAILY=2
```

The optional token belongs only in Laravel private environment configuration, never frontend code or Git. A free Hugging Face account token allows its included account quota; anonymous API requests share a smaller pool that can already be exhausted. Do not configure a paid account/automatic credit overage for a zero-cost deployment. The adapter uses the public Space only and does not purchase credits or upgrade accounts. The UI explains image transmission, admission caps and provider availability limits. After config changes rebuild Laravel config cache.

## Actual acceptance

2026-11-10: live public API schema/config inspected. A real image upload and preprocessing call succeeded using one session. The generation call returned `You have exceeded your ZeroGPU quota (120s requested vs. 0s left)` for the unauthenticated development client. No complete live GLB is claimed from that attempt. A free account token or available anonymous GPU allowance is needed to finish provider acceptance. Application rate limits cannot guarantee capacity or availability on the public demo.

Automated tests cover the full three-stage wire/session contract, preferred free routing, no inference on web polls, idempotency, global/hourly/daily limits, persistent quota counting, owner isolation, terminal recovery, cancellation publication fencing, invalid meshes and artifact-origin rejection. Fixture-generated GLBs prove application handling, not provider model quality. CI/browser and deployment outcomes are recorded in the release PR.

References: https://huggingface.co/spaces/microsoft/TRELLIS.2 and https://huggingface.co/docs/hub/spaces-api-endpoints . The public Space is an upstream dependency; schema changes, restarts, GPU queues and quota errors fail explicitly.
