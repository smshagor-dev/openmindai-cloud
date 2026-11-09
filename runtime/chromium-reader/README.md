# Chromium evidence reader

This is a **separate, optional** Playwright/Chromium worker for reading publicly accessible JavaScript-rendered HTML. Laravel first performs its existing DNS-pinned static fetch. For HTML only, when configured, it asks this service to render the page and extracts visible text. It falls back to the original static HTML parser when the browser worker fails. PDF and plain text keep their original paths.

## Run in a restricted environment

Build with `docker build -t openmindai-chromium-reader runtime/chromium-reader`. Configure a long random `CHROMIUM_RENDER_TOKEN` (at least 32 characters) and run with a nonroot user, a memory and CPU limit, read-only filesystem, dropped Linux capabilities and no-new-privileges. **Mandatory:** enforce network-level egress filtering so this browser cannot connect to private, loopback, link-local, metadata, Docker internal or cloud service addresses. The in-process DNS/URL checks are defense-in-depth, not a substitute for network isolation (DNS may change between validation and browser navigation). Do not expose the worker publicly.

Example environment for the worker:
```
CHROMIUM_RENDER_TOKEN=<long-secret>
HOST=0.0.0.0
PORT=3107
RENDER_CONCURRENCY=2
RENDER_TIMEOUT_MS=10000
```

Point Laravel at the private service URL and use the **same secret**:
```
WEB_RESEARCH_CHROMIUM_URL=http://chromium-reader:3107/render
WEB_RESEARCH_CHROMIUM_TOKEN=<long-secret>
```
After changing production Laravel environment settings, rebuild the configuration cache and verify /health privately. Do not check secrets into Git.

The worker rejects authentication failures, non-public initial URLs, disallowed ports, suspicious redirects, oversized requests and excessive page subrequests. Each request uses a fresh browser context without stored cookies. It executes scripts from untrusted websites: isolate it at OS and network levels. Rendering is bounded (10 seconds by default) and is **not** an endless browser session.

## Release boundary

The code alone does not enable Chromium on the hosted application. Install/deploy this worker and configure the two Laravel environment variables to activate it. Existing search-provider fallback and citation checks are unchanged.
