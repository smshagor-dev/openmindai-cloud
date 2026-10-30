# Hosted NVIDIA chat: Next.js inference with Laravel persistence

Browser -> same-origin Next.js API -> NVIDIA hosted models.
Separately, Next.js -> https://cloud.openmindai.org/api/v1 for Laravel
authentication, available model catalog, user quota, history, research
sources, resumable checkpoints and private media output.

**The NVIDIA key is never sent to the browser.** The Next.js server sends
the provider requests for chat, automatic model classification, research
planning, image/voice/video and document text. This restores the earlier
frontend-hosted inference behavior without reverting later feature fixes.
All browser requests still use `/api/backend/*`, not NVIDIA directly.

## Next.js / Vercel server environment

- `LARAVEL_API_URL=https://cloud.openmindai.org/api/v1`
- `CHAT_PROVIDER=nvidia`
- `NVIDIA_API_KEY`: your valid NVIDIA credential. Add it as a **secret
  environment variable on Vercel**, never as `NEXT_PUBLIC_NVIDIA_API_KEY`.
- `FRONTEND_CHAT_BRIDGE_KEY`: securely generated 32+ character value;
  identical to Laravel `.env`.
- `NVIDIA_MODEL`, `NVIDIA_FAST_MODEL`, `NVIDIA_REASONING_MODEL`,
  `NVIDIA_VISION_MODEL` and `NVIDIA_ROUTER_MODEL` can customize the
  configured server-side model IDs.

## Laravel backend environment

- `FRONTEND_CHAT_BRIDGE_KEY` must match Next.js.
- Laravel does **not** need `NVIDIA_API_KEY` to handle the Next.js-hosted
  chat/media storage flow. It can retain a separate `NVIDIA_API_KEY` for
  its own developer and administrator model APIs if those services are used.
- Keep database migrations, workers, Sanctum authentication, chat lease
  endpoints, per-user quota and private storage configured normally.

## Request handling

- Next.js checks the browser's HttpOnly auth cookie and request origin.
- Laravel validates signed-in users and manages model availability.
- The Next.js Node function sends HTTPS requests to fixed NVIDIA endpoints
  and never exposes its Authorization header to browser-side code.
- The same Node function streams chat tokens to the browser while
  checkpointing and finalizing the Laravel record.
- Hosted media tasks reserve quota in Laravel, invoke the provider from
  Next.js, then save validated artifacts to private Laravel storage.
- Expired sessions, canceled tasks, provider failures and storage errors
  remain separately handled; no result is reported complete when saving fails.

Vercel must receive the private NVIDIA_API_KEY and shared bridge key **before
testing actual inference**. See [Vercel deployment](VERCEL_DEPLOYMENT.md).
A successful build alone does not verify live provider access or usage quota.
