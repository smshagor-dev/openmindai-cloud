# Cloud Web Search: response lifecycle and Chromium reader

Status: code committed in `fix/web-search-completion-chromium`; **not yet production-deployed or live provider-verified**.

## Failure mode

Cloud's native Web Search waits for tool calls, page reads, second-pass answer verification, citations and persistence before it sends the final `answer` event. During these stages, there may be no client-visible stream output. On an idle proxy disconnect or hosted runtime deadline, a user could see a terminated stream without the verified reply.

This work improves the transport and makes interruption visible. It does not claim to eliminate hard platform limits or guarantee that an external LLM/search provider always returns a complete answer.

## Changes

- Send lightweight SSE heartbeat events while tool calls, research and answer verification are in progress. These are not `done` events.
- Set a bounded 220-second search/research deadline, leaving time within Vercel's configured 300-second handler limit for checkpoint/failure persistence and error delivery.
- Keep the user turn and assistant bubble visible after unexpected transport disconnects. If a search ends without any answer, show an explicit incomplete-state message rather than an empty response.
- The final verified `answer` is still sent before `done`; no unverified model draft is exposed as a successful answer.
- Add the isolated authenticated Playwright Chromium worker for HTML pages requiring JavaScript. It is used only when `WEB_RESEARCH_CHROMIUM_URL` and `WEB_RESEARCH_CHROMIUM_TOKEN` are configured in Laravel. Static DNS-pinned fetch and public-only URL checks still run, and any browser failure falls back to the existing static HTML extraction. PDFs keep their existing specialized reader.

## Verification and release gates

Automated cases now cover: Web Search answer-before-done ordering, heartbeat while a source read is pending, honest failed final status after search budget expiry, Chromium worker authentication and rejection of a private final URL. CI checks the Chromium JS syntax; existing frontend TypeScript, build/integration, PHP syntax, Laravel tests and Pint are configured in the repository.

**Still pending:** CI outcomes, real Chromium container smoke test against a JavaScript-rendered public page, production worker deployment with network-layer private-egress isolation, Laravel environment configuration, and authenticated end-to-end production Web Search verification across the full source-read/verification/saved-answer flow. Do not merge or deploy solely on fixture tests.

## Architectural limit

An SSE heartbeat prevents common idle proxy closures but does not extend the hard runtime limit and cannot recover after actual browser/network disconnection. Native web tool runs also can create artifacts in mixed requests and are intentionally not replayed automatically, to avoid duplicate side effects. Truly unbounded searches need a separately persisted background job with client polling/reconnection and idempotent tool checkpoints; that is not implemented in this patch.
