# Chromium source reader

Laravel WebReader uses Chromium for HTML by default. PDFs retain the existing PDF extractor. If Node, Chromium or the sandbox is unavailable, static HTML extraction remains available; this is a fallback, not proof that JavaScript content was rendered.

Install on the backend host as the unprivileged application user:

```sh
cd runtime/web-reader
npm ci
npm run install:browser
```

Use Node 20.9+ and Chromium's required operating-system libraries. Chromium runs with its sandbox enabled. On hosts with a system Chromium, configure `WEB_RESEARCH_CHROMIUM_BINARY=/usr/bin/chromium`. Optional settings: `WEB_RESEARCH_NODE_BINARY=node`, `WEB_RESEARCH_READER_ENGINE=chromium` (`static` disables rendering). Refresh Laravel's configuration cache after changing settings.

Each read starts a fresh browser profile with no application secrets or cookies. Navigation HTML comes from Laravel's existing DNS-pinned reader. GET-only scripts, styles and API reads go through DNS validation and pinned Node connections; private/reserved destinations, redirects, sockets, service workers and other resource types are blocked. The browser has a dead-end proxy so unhandled requests cannot connect directly. Limits bound time, requests and response sizes. Runtime output reports the engine used.

Run `npm test` for network-boundary checks. A real Chromium smoke check can pass a JSON object with `url` and `html` on stdin to `node chromium.mjs`; verify that inline JavaScript updates the extracted text. Never treat mocked provider tests as live-source or live-model verification.
