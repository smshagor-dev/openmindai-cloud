# OpenMindAI hosted coding API

## Server setup

Run Laravel migrations. Set `NVIDIA_API_KEY` in the Laravel server's private `.env` (not in Git or `NEXT_PUBLIC_`). The Next.js hosted chat also needs its own private `NVIDIA_API_KEY` if its original hosted-chat path remains in use. Both servers can use the same key, but keep it server-side.

In Admin → NVIDIA models, every registered model stays visible; OpenMindAI Super, Ultra and Lite can be selected for chat. Their NVIDIA model IDs are stored in the database. Model availability and provider quota are controlled by NVIDIA and must be tested with your key.

The public API base URL is `{APP_URL}/api/v1/openmindai`, for example `https://models.example.com/api/v1/openmindai`. Replace the example hostname with your deployed Laravel APP_URL.

- `GET /models`
- `POST /chat/completions` (OpenAI-compatible chat-completions shape)
- `Authorization: Bearer <developer-api-key>`

Each user can create up to ten developer keys (90-day expiry) in Account → Developer API; a key is visible only when created, and can be revoked.

Example:

```bash
curl "$BASE_URL/chat/completions" \
  -H "Authorization: Bearer $OPENMINDAI_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"model":"openmindai-lite","messages":[{"role":"user","content":"Write a Python function to sum a list"}],"stream":false}'
```

Available aliases are `openmindai-super`, `openmindai-ultra`, and `openmindai-lite`. Send up to 40 messages with `system`, `user` or `assistant` roles. Maximum output is 8192 tokens. `stream: true` streams SSE completion chunks.

These developer keys are **secrets**, not public/publishable frontend keys. Keep keys on trusted servers. The gateway enforces each model's monthly credit allocation and the app owner's monthly allocation; usage counts successful calls as one application unit, not NVIDIA billing tokens.

## First-party applications without per-user keys

In Admin → Code agents, register an owned app and copy its one-time **App ID** and **signing secret** into that app's *server environment*. There is no secure anonymous client-side API without a secret or user session. First-party calls use a server-held signing secret rather than individual user API keys.

Use `{APP_URL}/api/v1/first-party/models` and `{APP_URL}/api/v1/first-party/chat/completions`. Every request must have:

- `X-OpenMindAI-App`: App ID
- `X-OpenMindAI-Timestamp`: Unix seconds (within five minutes)
- `X-OpenMindAI-Nonce`: unique 32–64 lowercase hex
- `X-OpenMindAI-Signature`: lowercase hex HMAC-SHA256 with the signing secret

Construct the canonical input as `timestamp + "\n" + nonce + "\n" + uppercase_method + "\n" + "/" + relative_path + "\n" + sha256(raw_request_body)` and sign the resulting UTF-8 bytes using the secret as a UTF-8 HMAC key. For example, the path is `/api/v1/first-party/chat/completions`. Requests cannot be replayed and are rate-limited per app. Registered app usage is charged to its administrative owner, respecting the same credit limits.

**Security:** First-party secrets must stay on a server or trusted backend, never in a web/mobile binary or JavaScript bundle. Public browser users should use the existing authenticated site and Next.js proxy instead.

**Scope:** The gateway currently exposes hosted code-model listing and chat completion. Image, speech, video and document workflows remain on their existing authenticated hosted-media routes; they have not been added as developer-key endpoints here.


## NVIDIA Build free-endpoint catalog (2026-10-21)

The NVIDIA Build catalog advertised 42 free endpoint models on October 21, 2026. The migration `2026_10_21_000006_nvidia_free_model_catalog.php` seeds them into the existing `hosted_code_agents` database table. All 42 models are permanently listed in the authenticated catalogs, including models whose specialized API integration still requires verification. Chat models use the shared chat-completion route. Provider entitlement and API quotas must be checked independently.

Admin → NVIDIA models (`/admin/nvidia-models`) displays all 42, with search, categories, editable display labels, NVIDIA model IDs, JSON API paths, whether the endpoint expects a model field, and integration readiness. Models cannot be hidden or disabled through this admin menu. This is a database-backed configuration, not a .env list. Updating configuration changes how inference requests are routed, but never removes models from catalog discovery.

Authenticated developer-key endpoints:

- `GET /api/v1/openmindai/catalog` lists **all** registered models, including category, integration readiness, supported operation and a relative path only when ready.
- `GET /api/v1/openmindai/models` lists all registered models with `available` and `operation` metadata so clients can distinguish catalog visibility from API readiness.
- `POST /api/v1/openmindai/chat/completions` retains the existing streaming and non-streaming chat gateway.
- `POST /api/v1/openmindai/models/{slug}/invoke` routes admin-verified **non-chat JSON** models to their configured NVIDIA `/v1/` API path, provided the service uses the `integrate.api.nvidia.com` origin.

Invoke request body:

```json
{"input":{"input":"Text to embed"}}
```

The backend removes any caller-supplied `model` or `stream`, optionally inserts the server-side model ID and returns `{"model":"alias","output":{...provider JSON...}}`. It enforces the same user and model monthly limits and records successful requests. API keys are never sent to arbitrary user-selected hosts. HMAC-protected first-party equivalents exist under `/api/v1/first-party/catalog` and `/api/v1/first-party/models/{slug}/invoke`.

**Important:** NVIDIA's free endpoint badge does not guarantee unrestricted or commercial availability. Models have different schemas and not all accept JSON-only invocation. No binary uploads, streaming audio, asynchronous media jobs, signed URLs or unsupported endpoint families are exposed through this gateway. These require their own reviewed integrations. Models remain discoverable even without a verified specialized API route. A valid Developer API Key authenticates to OpenMindAI Cloud, not to every NVIDIA model, and does not waive provider restrictions.


### Always-visible model catalog

All seeded NVIDIA Build models appear in both model discovery endpoints. A follow-up migration restores previously disabled rows and configures the common chat-completions path for chat models, so `enabled` is not a client-visibility toggle. The backend still returns unavailable/404 for unsupported specialized inference operations until their request format and NVIDIA path are configured. Free endpoint status, actual uptime, rate limits and account-level NVIDIA access remain external to OpenMindAI; listing a model does not promise successful inference.

## Generate Desktop Developer API Keys in Admin

Admin → Code agents → **Developer API Keys for OpenMind Studio** lets the currently signed-in administrator generate an ordinary **per-account Sanctum Developer API Key** with the `openmindai:invoke` ability and a 90-day expiry. The admin must re-enter their password and, when enabled, a valid 2FA code. The generated token is shown in the browser only once and cleared after 60 seconds. A refresh or later visit cannot reveal it: Sanctum stores only a hash of the token. Existing keys show metadata and support password/2FA-protected revocation.

This is the same credential type as Account → Developer API, and follows the same maximum of 10 active keys per user. The admin UI manages **only the signed-in administrator's own keys**, not all users' keys. Use this Bearer key in OpenMind Studio's encrypted cloud API-key setting. First-party App IDs and signing secrets are exclusively for trusted server-to-server integrations; never distribute these secrets in an installer.

## NVIDIA model-specific API integrations

NVIDIA NIM services do **not** all use the same endpoint or hostname. Database migration `2026_10_21_000008_nvidia_verified_inference_routes.php` configures documented backend routes for:
- Nemotron 3.5 Embed 1B/8B, Nemotron 3 Embed 1B: `https://integrate.api.nvidia.com/v1/embeddings` (JSON input and input_type)
- Nemotron 3.5 Rerank 8B: `https://ai.api.nvidia.com/v1/retrieval/nvidia/nemotron-3.5-rerank-8b/reranking` (query object, passages array)
- Kumo Tabular and Relational: `https://ai.api.nvidia.com/v1/structured-data/nvidia/{model}/predictions` (task, schema, context, predict, output envelope; special model-family identifiers)
- RFD4 Proteina: `https://health.api.nvidia.com/v1/biology/ipd/rfd4-proteina/generative` (protein task and condition input)
- Riva Translate 4B v2: `https://integrate.api.nvidia.com/v1/chat/completions`, as a chat-compatible translation model.

The API gateway restricts outbound requests to these three fixed NVIDIA-owned HTTPS origins, refuses redirects, and never accepts a caller-supplied destination URL or alternate model ID. Complex image, voice, and video services need separate binary upload, signed-asset or async job integration, and are **not** treated as working JSON APIs merely because NVIDIA lists a free endpoint.

In Admin → NVIDIA models, `API configured · not verified` means the protocol/path is implemented and mock-tested, **not** that your server NVIDIA key has permission or quota. The password/2FA-protected **Test live NVIDIA inference** action sends a tiny sample for chat, embeddings or reranking and records success, access denied, rate limiting or upstream failures. `Verified live` appears only after a successful real NVIDIA response. Other complex models remain in the catalog with `Custom protocol required` until a safe integration is built. Changes to model configuration reset any prior live verification.

Example specialized request:

```json
{"input":{"input":"Example sentence for RAG","input_type":"query"}}
```

Use `POST /api/v1/openmindai/models/nvidia-nemotron-3-embed-1b/invoke` with a Developer API Key. Provider-side access, availability, usage limits and applicable license terms are independent of local authentication.

## OpenMind Studio Cloud tool calling

The chat-completions gateway supports up to **64 declared function tools** and **64 tool calls within an assistant message**. This covers OpenMind Studio's 19 built-in tools without silently dropping, renaming, or truncating them. Tool schemas, tool-call IDs, roles and returned tool results are forwarded to NVIDIA unchanged. Inference requests are limited to **1 MiB** to prevent oversized nested tool-schema payloads.

The old Laravel `max:12` check was an application-side validation bug, not evidence of a documented 12-tool NVIDIA limit. Provider-side limits may vary by selected model. If NVIDIA rejects a tool schema or payload, the backend returns a clear HTTP 422 with `error.type=upstream_error` and `error.upstream_status`, without exposing the upstream response body or server API credentials.

A real desktop roundtrip with all 19 tools is still required after deployment to establish whether each NVIDIA model supports the workload. No client-side removal of agent tools or first-party signing secret is required.
