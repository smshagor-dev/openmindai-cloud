# Frontend API routing checks

The OpenMindAI welcome page uses a Next.js server-side backend proxy at `/api/backend/*`. The Laravel API is configured by the **server-only** `LARAVEL_API_URL` environment variable. A static-only site or a web-server fallback that sends API requests to the HTML welcome page cannot support login, account information, models or chat.

## Verify the deployment

1. Visit `https://YOUR-FRONTEND-DOMAIN/api/health`. Expect HTTP 200 with `Content-Type: application/json`, `frontend: "ok"`, and `backendConfigured: true`. The health endpoint does not reveal credentials and does not test live Laravel connectivity.
2. Visit `https://YOUR-FRONTEND-DOMAIN/api/backend/public-config`. Expect JSON containing public workspace settings, not `<!DOCTYPE html>`. This verifies that the Next.js route and Laravel backend are reachable end to end.
3. If health returns HTML or 404, check that the domain points to the **running Next.js Node server**. Rebuild and restart the Node application instead of deploying an `out/` directory or pointing the domain at static frontend files. On cPanel this requires a Node-supported plan and correct Passenger/startup and reverse-proxy configuration. A PHP-only cPanel service cannot run the dynamic Next.js routes.
4. If health returns JSON with `backendConfigured: false`, set `LARAVEL_API_URL` on the **Next.js server** to the Laravel API base, including its `/api/v1` prefix; restart the frontend process. Do not use `NEXT_PUBLIC_LARAVEL_API_URL` or embed the private origin in browser JavaScript.
5. If health works but public-config returns a 502 or 503 JSON error, inspect Laravel logs, the upstream URL and TLS connectivity from the Next.js host. Confirm that Laravel handles `/api/v1/public-config` and does not redirect that API path to an HTML login, maintenance or 404 page.

For example:

```sh
curl -i https://YOUR-FRONTEND-DOMAIN/api/health
curl -i https://YOUR-FRONTEND-DOMAIN/api/backend/public-config
```

After deploying updates, rebuild and restart the Next.js process, then hard-refresh the page if old assets are cached.

The frontend now treats non-JSON content and malformed JSON as an actionable `ApiError` instead of exposing the browser's low-level `Unexpected token '<'` exception. It also validates the stream content type. These client-side safeguards do **not** fix a misconfigured reverse proxy or missing Node server; correct deployment routing is still necessary.
