# Web Search completion and Chromium reader

Updated: 2026-11-10 (Europe/Moscow).

Implemented:
- SSE comment heartbeat every ten seconds while search, model reasoning or verification is silent.
- Preserve tool results and synthesize a final answer after the research loop stops making progress. Retry an empty provider stop without replaying tool side effects.
- Publish the verified answer before checkpoint/final-save work. Reject empty tool responses before marking the checkpoint finished.
- Chromium HTML extraction with JavaScript support, sandbox enabled, clean subprocess environment, fresh browser context, DNS-validated and pinned GET-only resource loading, blocked sockets/service workers and bounded runtime. Static extraction is an explicit fallback when the browser is unavailable. PDF extraction is unchanged.
- Backend reader engine metadata and installation/configuration instructions in runtime/web-reader/README.md.

Validation:
- Full frontend integration suite: 266 passed, zero failed (fixture/mocked providers).
- Reader network-boundary tests: 2 passed, zero failed.
- Frontend TypeScript check passed.
- Final Next.js production build passed, including heartbeat and completion changes.
- JavaScript syntax checks and git diff whitespace checks passed.

Outstanding environment checks:
- Live Chromium rendering could not run here: the installed system Chromium fails at socket creation with Operation not permitted. Playwright's browser download also returned an invalid archive. Browser sandbox was not disabled to bypass this restriction.
- PHP/Composer are unavailable here, so Laravel runtime tests and PHP lint have not run.
- No live model/search-provider acceptance, backend installation, merge or production deployment has been performed. The backend host needs the Node/browser dependencies installed before Chromium rendering is active; otherwise HTML uses the documented static fallback.
