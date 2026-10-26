# Advanced web search and deep research in Cloud chat

This change extends the existing Cloud chat rather than creating another research product. It preserves Sanctum ownership, the server-side AI gateway/bridge, chat credits, cancellation, history and Research Studio.

## Reference inspected

The actual reference is `smshagor-dev/OpenMindAI` (desktop), main at `7e3f11b`, not the mobile application. Reviewed `src-tauri/src/web_research.rs`, `src/components/ResearchProgress.tsx`, `src/research-progress.css` and the live-web-research, quality-validation and corpus-reference documents. The reference is Apache-2.0. This is an independent PHP/TypeScript implementation of the relevant behavior, without desktop/native access, branding assets or simulated browsing.

## Implemented behavior

- Existing Web Search uses focused searches and inspected pages before a sourced answer. Search results preserve provider and retrieval time. SearXNG, optional Brave and Google CSE, then existing keyless Bing RSS/DuckDuckGo are supported. Transient retries and provider cooldowns are bounded; a configured provider is not reported as healthy merely because a key exists.
- Hosted Deep Research can run up to three independent search queries or page reads concurrently, with individually correlated real start/completion/error events. Citations are assigned deterministically in request order. Successful siblings survive an interrupted batch; resumptions retry only pending requests. Batch items each consume the existing action/read budgets.
- Deep Research uses an adaptive plan/search/read/review/write loop in the existing Next server-side chat orchestrator. The planner can name up to twelve user objectives and link inspected, nonduplicate source IDs to each objective. Missing or invalid coverage remains unresolved. This is a planner coverage assessment, not a semantic proof.
- Internal per-invocation safety budgets are 24 tool actions, 12 page reads, 40 source records and 120 seconds between actions. Source counts are not research goals. At a budget boundary the hosted path persists an encrypted checkpoint and pauses; Resume continues the same message with a fresh bounded invocation. Cancellation does not claim a completed report. Completed side-effecting Auto tool runs remain non-resumable.
- Public HTML/text retrieval retains bounded content from the page, then selects query-relevant passages rather than only the introduction. Public text PDFs use actual page extraction with a deterministic sample of at most eight pages across the document, including the final page. The UI explicitly states which pages were sampled and whether the whole document was read.
- Similar five-word passage shingles flag mirrored/syndicated excerpts. Multiple domains alone do not establish independence.
- The hosted report checks retrieved citation numbers/URLs, named citation links and bare numeric citations, preserving code examples. Paragraph-level lexical passage and numeric/unit checks flag unsupported or contradictory claims for review. Bengali and Arabic numerals are normalized for these heuristics. These are not truth, originality, semantic entailment or statistical-validity guarantees. Arbitrary unlinked model text is not automatically validated.
- Chat uses compact activity above the answer, inline citation buttons and a responsive source drawer with title/domain/excerpt search, inspected/limited filters and PDF-page links. Unknown or mismatched citations remain ordinary links, never retrieved evidence. The UI omits only the recorded host-generated footer; copy and Markdown exports retain the full source index. Web Search and Deep Research have distinct labels, and active parallel counts come only from correlated operation events.
- Chat displays actual backend search/read events, provider fallback events, queries, URLs, timestamps, objectives, evidence limitations and report checks. It shows no invented browser snapshots or progress percentages. Selected source passages and authentic PDF-page links remain available after refresh. Public metadata is bounded to 200 KB of UTF-8 JSON, separately from the 4 MB bounded encrypted research checkpoint request. This avoids false size failures from ASCII-escaped Bengali/Arabic/Chinese text.
- The local Laravel runtime also has bounded planning and mandatory page inspection, persistent browsing events, selected passages and citation URL checks. At its request budget it writes an explicitly limited result. Its local SSE path does not offer the hosted checkpoint-resume workflow or the hosted numeric/lexical audit; it does not claim parity on those capabilities.
- Shared chat font fallbacks cover Bengali, Arabic, Devanagari and Chinese using the already-installed font packages. RTL passage/objective text uses automatic direction. Narrow headers keep all controls inside the viewport.

## Existing integration points

No new authentication or AI gateway is introduced. Existing endpoints are extended:

- `POST /api/v1/chat/frontend/tool`: owned bridge ticket; search or protected page retrieval; optional original question for passage ranking.
- `POST /api/v1/chat/frontend/checkpoint`: encrypted resumable state plus bounded public research activity/source excerpts.
- `POST /api/v1/chat/frontend/finish`: final content, status, bounded source summaries and public research metadata.
- `POST /api/v1/chat/frontend/resume`: existing owner/latest-message checks and lease rotation.
- `POST /api/v1/chat/stream`: existing local runtime SSE path.
- Frontend remains `/` and `/c/[slug]`; Web Search and Deep Research remain chat tools. Research Studio stays at `/research-studio`.

Additive migration: `2026_10_26_000002_add_chat_research_metadata.php` adds nullable JSON `messages.research_metadata`. It does not reset or rewrite existing messages.

## Protected public retrieval

Every redirect destination is revalidated. DNS answers must be public and cURL is pinned to the validated address; redirects are never followed automatically. Credentials, private/reserved addresses and unsupported ports/types are rejected. Bodies are limited to 2 MB. No user authentication header is forwarded to a source.

The PDF helper is fixed Python code reading stdin, with no generated code, PDF actions, JavaScript or OCR. On Linux it applies 384 MB address-space, 6-second CPU, file-size/core limits, a 12-second PHP process timeout, page-tree limits and bounded stream decompression. Its environment excludes application secrets. This is a bounded parser, not an arbitrary-code experiment sandbox. Scanned, encrypted, malformed, oversized or unreadable PDFs fail closed with an unavailable status. It never falls back to invented PDF text.

## Deployment

Use an isolated Python virtual environment, not a global production package upgrade:

```sh
python3 -m venv /absolute/private/path/openmind-web-reader
/absolute/private/path/openmind-web-reader/bin/pip install -r runtime/web-reader/requirements.txt
```

Set `WEB_RESEARCH_PYTHON_BINARY` privately to that environment's `bin/python`. cPanel requires a supported Python interpreter and permission to launch the fixed process. Install `pypdf==6.10.0` from the committed requirements file. Without it, HTML search can still work and PDF reading reports unavailable.

Optional backend-only settings: `BRAVE_SEARCH_API_KEY`, `GOOGLE_SEARCH_API_KEY`, `GOOGLE_SEARCH_ENGINE_ID`. Existing `SEARXNG_SEARCH_URL` and `PUBLIC_SEARCH_FALLBACK` remain compatible. Never put keys in `NEXT_PUBLIC_*` or source control.

Run the additive migration with the normal release process, clear/cache Laravel configuration and build/deploy the existing Next frontend. Preserve production `.env`, uploads and server changes. Verify search/read/inference/stream/cancel/resume through the deployed app before release acceptance. No production deployment was performed for this branch during this implementation.

## Verification, 2026-10-26

Commands and final results: Automated providers are deterministic fixtures, not real provider verification.

- `php artisan test`: 349 passed, 2245 assertions, 0 failures.
- `vendor/bin/pint --test`: passed.
- `composer validate --strict`, `composer audit` and `npm audit --omit=dev --prefix frontend`: passed; both audits reported no vulnerabilities.
- Existing media runtime regression: 18 passed.
- Laravel route/view/config cache compilation passed; local cleanup passed with an array cache (the example MySQL database is not configured here).
- PHP syntax check: passed across app/bootstrap/config/database/routes/tests.
- `npm run typecheck --prefix frontend`: passed.
- `npm run build --prefix frontend`: passed.
- `npm run test:integration --prefix frontend`: 219 passed, 0 failed, 0 skipped.
- `python3 -m unittest discover -s runtime/web-reader -v`: 2 passed. Actual generated 24-page PDF retained page 24 evidence; malformed/oversized/decompression-bomb fixtures rejected.
- `RESEARCH_CHROMIUM_PATH=... node frontend/e2e/web-research-run.mjs`: 10 passed: Deep Research at 1920, 1440, 1280, 1024, 768, 430, 375 and 320 pixels, plus Web Search at 1440 (dark) and 375 (light). Browser fixtures exercise actual React/SSE parsing, inline citation inspection, source drawer search/filter/close, pause, refresh and resume controls. Actual database persistence/lease/ownership and orchestrator behavior have separate Laravel/Node integration tests.
- Direct application live smoke: public search and `https://sqlite.org/wal.html` retrieval were unavailable from this restricted environment. No real inference smoke test was performed with production credentials. These remain release gates; mocked tests do not establish live availability.

The full twelve-agent Research Studio, its isolated execution worker and the earlier Admin/Developer Dashboard work are separate objectives. This search change does not certify or reimplement those systems.
