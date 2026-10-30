# OpenMindAI Developer Portal

A separate Next.js application inside `Api/` for developer registration and sign-in, personal API keys, hosted-model discovery, usage reporting, and integration documentation.

## Architecture

- Next.js hosts both browser-account endpoints under `/api/bridge/*` and the public Bearer-key developer gateway under `/api/v1/openmindai/*`.
- Laravel remains the identity provider, developer key issuer, source of model truth, and usage database. Do not create a second account database.
- Login and registration reuse Laravel `/api/v1/register`, `/login`, `/login/verify-ip`, `/email/verify-code`, `/email/resend`, `/forgot-password`, and `/public-config`. Existing OpenMindAI accounts can sign in. A person who registers here becomes a developer by managing keys, not by obtaining extra Laravel permissions.
- Login bearer tokens are stored only in a Secure (production), HttpOnly, SameSite=Lax cookie. The browser never receives them in JSON or localStorage.
- API keys are separate Sanctum tokens scoped to `openmindai:invoke`, shown only when created, with a 90-day lifetime, up to 10 active keys. External clients must use this Next.js portal's `/api/v1/openmindai/*`, not Laravel's domain.
- Credentials for hosted model providers remain exclusively on the Laravel server. There is no NVIDIA credential in the Next.js portal.
- Developer reports are limited to their own usage. Each completed request counts as one **application request credit**, not a provider billing token.

## Local development

Run the Laravel backend migrations and start Laravel using the instructions in the repository root. Then:

```bash
cd Api
cp .env.example .env.local
npm install
npm run dev
```

Open `http://localhost:3100`. Set `LARAVEL_API_URL` to the full backend API URL ending in `/api/v1`. During local development, `http://127.0.0.1:8000/api/v1` is valid. Set `PORTAL_ORIGIN=http://localhost:3100` (the **exact** origin your browser opens). The Laravel `.env` must have its own valid `APP_URL`, SMTP settings if email confirmation is enabled, `NVIDIA_API_KEY` for inference, and a configured database.

## Deploy on Vercel (recommended)

Deploy this **separate** Developer API portal from the same GitHub repository as
the main frontend. Its Vercel project must have **Root Directory: `Api`**;
the Laravel root and the separate `frontend/` app must not be deployed in this
project.

| Vercel setting | Value |
| --- | --- |
| Framework | Next.js |
| Root Directory | `Api` (case-sensitive) |
| Node.js version | 22.x |
| Install command | `npm install --no-audit --no-fund` |
| Build command | `npm run typecheck && npm run build` |
| Output directory | Framework default |
| Production branch | `main` |

There is currently no `Api/package-lock.json`. Use `npm install`, **not**
`npm ci`, until a lockfile is committed. All declared package versions are
currently exact, but transitive dependencies may change; generate and commit
a lockfile before claiming reproducible installs.

Set these **server-side** environment variables for Production and Preview:

- `LARAVEL_API_URL=https://cloud.openmindai.org/api/v1`
- `PORTAL_ORIGIN=https://YOUR-VERCEL-PORTAL-DOMAIN` (canonical production
  HTTPS URL, **without** `/api/v1/openmindai`)

The portal accepts the current Vercel deployment origin for authenticated
browser mutations, so Preview URLs can be used without weakening the
origin check. Preview developer-key responses use the Preview origin.

The production public developer API base URL is
`https://YOUR-VERCEL-PORTAL-DOMAIN/api/v1/openmindai`.
Set that full URL as `DEVELOPER_API_BASE_URL` on **Laravel**, then clear
Laravel configuration cache. Set the origin only (no path) as
`NEXT_PUBLIC_DEVELOPER_PORTAL_URL` on the **main `frontend/` Vercel
project**, then redeploy that project to display the new portal link.

Do not put `NVIDIA_API_KEY` or Laravel's `APP_KEY` in this Developer API
portal. Laravel handles provider credentials, developer-key verification,
rate limits and credit accounting for the public Developer API gateway.
The separate user-facing chat frontend has its own server-side NVIDIA
credential and remains an independent Vercel project.

For a post-deployment smoke test, open `/`, `/login`, `/docs`, and check
that `/api/v1/openmindai/models` without a Bearer key returns HTTP 401
JSON rather than HTML. Then sign in through Laravel, create a developer key,
check model catalog and credits, and try an authenticated request. A
successful Vercel build is not evidence that the Laravel backend, NVIDIA
model account entitlements or authenticated API requests work.

## Production deployment

1. Deploy Laravel first and run `php artisan migrate --force` followed by `php artisan optimize:clear`.
2. Deploy the **separate** `Api/` Node.js application on a Node-capable host. A PHP-only cPanel account cannot run dynamic Next.js routes.
3. Set private environment variables: `LARAVEL_API_URL=https://YOUR-LARAVEL-DOMAIN/api/v1` and `PORTAL_ORIGIN=https://YOUR-PORTAL-DOMAIN`. Do not use `NEXT_PUBLIC_` for backend URLs or credentials.
4. Run `npm install --no-audit --no-fund && npm run build && npm run start` from `Api/`. Adjust the port using your hosting process manager if needed.
5. Configure HTTPS and an ingress that preserves the correct request origin; verify cookies are Secure and HttpOnly. Production rejects non-HTTPS backend URLs.
6. Login security: Laravel applies unfamiliar-IP checks using its trusted remote address. If Next.js sits behind a trusted reverse proxy that overwrites incoming `X-Forwarded-For`, you may set `TRUST_PROXY_HEADERS=true` to forward that validated address. Never enable it for arbitrary client-supplied forwarding headers.
7. When Laravel email verification is enabled, registration opens `/verify-email` in the Developer Portal. Users enter the six-digit code sent by Laravel, with resend subject to the backend two-minute cooldown and hourly limits, and then sign in. Password reset emails currently use Laravel's configured `FRONTEND_URL` and may open the primary OpenMindAI frontend.
8. Do not expose the Laravel NVIDIA secret, third-party model credentials, account session tokens, or signing secrets to client JavaScript.

## Developer workflow

1. Register or log in. If email verification is enabled, enter the six-digit code on `/verify-email` before signing in. Laravel enforces registration controls, TOTP and unfamiliar-IP email challenges.
2. Open `/keys`, label a key, confirm the account password and enter a 2FA code when required.
3. Copy the new key **once** into a trusted desktop credential store or backend secrets manager.
4. Use the portal-origin `base_url` and `Authorization: Bearer ...` to call `/models` or `/chat/completions`. Never use a developer key to access account-management routes.
5. Open `/dashboard` for completed request credits and `/models` for model readiness. Read `/docs` for cURL, Node.js and Python integration samples.
6. Revoke compromised keys under `/keys`; clients using them will stop authenticating.

## Supported portal routes

| Page | Purpose |
| --- | --- |
| `/` | Public onboarding landing page |
| `/register` | Developer registration |
| `/verify-email` | Complete registration using an emailed six-digit code; resend with cooldown |
| `/login` | Developer sign in with TOTP/unfamiliar-IP challenge support |
| `/forgot-password` | Request account password reset |
| `/dashboard` | Private usage overview |
| `/keys` | Create, list, copy-once and revoke developer credentials |
| `/models` | Model catalog, capability and access-status metadata |
| `/docs` | Public integration instructions and endpoint reference |

### Laravel endpoints added

- `GET /api/v1/developer/usage`: current user's per-model monthly completed requests and recent daily activity.
- `GET /api/v1/developer/catalog`: authenticated, non-invocation model catalog for browser sessions.

Both routes use the existing `auth:sanctum`, `active`, `interactive-token` and throttling middleware, so developer invoke keys cannot access private portal data.

## Validation

```bash
cd Api
npm run test:integration
npm run typecheck
npm run build
cd ..
php artisan test --filter=DeveloperPortalTest
php artisan test --filter=OpenMindAiGatewayTest
```

The CI workflow runs the Next.js build and typecheck. Complete end-to-end HTTPS/cPanel and live NVIDIA integration testing after deployment. Use separate credentials for staging; do not test with production secrets in CI.

## Link from the existing frontend

Set `NEXT_PUBLIC_DEVELOPER_PORTAL_URL=https://YOUR-PORTAL-DOMAIN` on the existing `frontend/` deployment, rebuild and restart. Settings → Developer API then opens this standalone portal. Without the variable, original key-management settings remain functional through the authenticated proxy.

## Public Developer API Base URL

External clients connect to the **Next.js developer portal**, not directly to the Laravel backend.

**Base URL:** `https://YOUR-PORTAL-DOMAIN/api/v1/openmindai`

The portal's API key creation/list endpoints present that public URL based on `PORTAL_ORIGIN`. `LARAVEL_API_URL` is strictly an internal, server-side forwarding target and should not be shown to developers as their API connection URL.

Supported gateway routes:

| Method | Public path |
| --- | --- |
| GET | `/api/v1/openmindai/models` |
| GET | `/api/v1/openmindai/catalog` |
| POST | `/api/v1/openmindai/chat/completions` |
| POST | `/api/v1/openmindai/models/{slug}/invoke` |
| POST | `/api/v1/openmindai/cosmos/generations` |
| GET | `/api/v1/openmindai/cosmos/{uuid}` |
| GET | `/api/v1/openmindai/cosmos/{uuid}/download` |

The public gateway checks an explicit `Authorization: Bearer YOUR_DEVELOPER_KEY` header and never authenticates using the portal login cookie. It forwards only a restricted set of endpoints, preserves model errors and rate-limit headers, and streams SSE completions and authenticated media downloads. Laravel continues to validate Sanctum keys, enforce user/model permissions and credit limits, and talk to the inference provider.

Client example:

```bash
curl "https://YOUR-PORTAL-DOMAIN/api/v1/openmindai/models" \
  -H "Authorization: Bearer YOUR_DEVELOPER_KEY"
```

The Next.js deployment must use HTTPS and route `/api/v1/openmindai/*` to its own Node.js server. Do not configure an ingress rule that bypasses Next.js for these paths. For streaming inference, ensure the platform supports long-running HTTP responses.

### Laravel admin and legacy settings

Configure `DEVELOPER_API_BASE_URL=https://YOUR-PORTAL-DOMAIN/api/v1/openmindai` in the **Laravel** `.env`, and run `php artisan optimize:clear`. Laravel admin/API key screens will display the external Next.js URL instead of the Laravel backend URL. In the Next.js portal, API key responses always use the portal's configured `PORTAL_ORIGIN`.
