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

Run the additive migration with the normal release process, clear/cache Laravel configuration and build/deploy the existing Next frontend. Preserve production `.env`, uploads and server changes. Verify search/read/inference/stream/cancel/resume through the deployed app before release acceptance. Actual release progress is recorded below and in `RESEARCH_STUDIO_STATUS.md`.

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

## Production release verification — 2026-10-26

Backend release `026987b6c18508500d763a7b3c24ca183eea5f43` was deployed to the existing cPanel project after checking its Git state and upstream ancestry. The custom local Nemotron seeder, server files and uploads were preserved. A private configuration/Git backup and transaction-consistent compressed database dump were created outside the webroot; gzip integrity passed. No database restore test is claimed. Composer production installation, the single additive research metadata migration (batch 15), framework cache clearing/optimization and return from maintenance mode completed. No reset, unrelated migration, seeder overwrite, cron change or unrelated service modification occurred.

Installed the fixed public PDF reader in a dedicated private Python 3.11 environment with pinned `pypdf==6.10.0`. Backed up `.env` before adding only `WEB_RESEARCH_PYTHON_BINARY`; rebuilt Laravel caches. This reader is distinct from the still-unprovisioned Research Studio experiment/export processing worker.

Actual production backend checks, with credentials retained server-side:

- Configuration: NVIDIA and bridge key presence passed; selected inference route is `backend`.
- Live search: five results through `bing_rss`, including official SQLite documentation. This establishes the fallback provider, not availability of every optional search provider.
- HTML: official SQLite WAL page read successfully, yielding eight selected passages.
- PDF: arXiv `1301.3781` read successfully, with eight passages and actual page provenance. Twelve-page document sampled pages 1, 2, 4, 5, 7, 8, 10 and 12; explicitly partial, no OCR. Both parser regressions passed on production in 0.480 seconds.
- Ultra model audit: success, HTTP 200, runtime configured, no missing settings. The existing verified enabled state was preserved.
- Existing provider bridge controller/service: JSON HTTP 200 with nonempty content; SSE HTTP 200, three data events, delta and `[DONE]`. These direct service invocations do not independently test HTTP authentication middleware or browser streaming.
- Negative retrieval checks: obsolete RFC PDF URL was unavailable; a larger arXiv PDF was rejected by the 2 MB cap. Neither was passed off as successfully read. A first CLI configuration probe incorrectly called an instance method statically; corrected probe passed. No application source change was required by these probe errors.

Frontend production deployment `dpl_AX9gDLv4JJ35NkyvWjwSEV4CurKp` reached READY for that exact code SHA in existing Vercel project `openmind-ai`, with `chat.openmindai.org` assigned and no alias error. Previous READY production `dpl_CNyUKhAq6GAWYdFvV1hDg8TBayAS` is retained as a code rollback candidate. An earlier browser usage-limit interruption and expired cPanel tab delayed completion; secure sign-in subsequently succeeded.

Authenticated live Web Search retrieved six sources, read the official PHP page and exposed eight actual passages in the source drawer. The Pages read filter and original link worked. The Auto model's Bengali prose was visibly poor and its report had four flagged checks; this is not a language-quality pass. Ultra Deep Research emitted three actual concurrent searches, persisted twenty sources and three subsequently read pages. Its provider planner timed out twice; refresh/resume retained progress without inventing a completed report. A follow-up bounds each coordinator response to 2048 tokens and disables extended thinking only for the two known Nemotron planner models (Ultra's non-thinking request passed the live JSON probe). Ordinary report reasoning remains unchanged. Retesting and release of this follow-up are pending at this checkpoint.

Production preservation check confirmed every other `.env` entry unchanged. Inspected Laravel error headings on this date were the two failed CLI probes described above, not successful live workflow checks. The new Vercel release's grouped error/fatal log query returned no groups in its first thirty-minute window. This is a bounded inspection, not a guarantee of absence of all errors.

Coordinator follow-up local validation: 220 Node integration tests passed, TypeScript passed, production build passed and all ten browser workflows passed (17.8 seconds). First running the proxy tests while its required production build was still being generated failed on a missing build; rerunning after the completed build passed. PHP code and schema were unchanged by this follow-up; the existing 349-test backend result belongs to the preceding release, with new CI still required for the new commit.

### Verified planner correction and selected-search follow-up

Coordinator correction `3866cccd3cb597630c9183437818aa5dd5aafaac` passed all four CI jobs in run `40377743612`, including a fresh 349 backend tests/2245 assertions. Its production deployment `dpl_7tc36Syv83JrfyDyWsMGgJpHpz29` reached READY for the exact SHA and existing domains, without alias error; cPanel fast-forwarded while preserving the local seeder/server changes. No further Composer, migration or cache change was needed for this frontend-only correction.

Live cancellation reached Research stopped, refresh retained the checkpoint, and resumption continued the same conversation from four to seventeen actual page reads. A per-invocation budget pause was accurately displayed. A subsequent planning HTTP failure preserved progress; a server-private provider probe with the same research state returned HTTP 200 and planning content, without context/rate/token error indicators. This diagnosis does not assert that every earlier failure had the same cause.

Real Ultra native-tool probe also returned HTTP 200 and selected the supplied search function. The next follow-up therefore prefers successfully verified Ultra for manually selected Auto Web Search; ordinary Auto chat and explicit choices retain their existing routing. The existing coordinator remains the fallback when Ultra has not passed its last test. Native Ultra tool calls use its live-tested non-thinking setting. Transient planner 502/503/504 responses retry once, recording the failure/retry in the persisted activity trail; both attempts share the original deadline/cancellation signal. Rate limits and final failures remain explicit. Automated coverage checks real adapter/bridge routing, no secret emission and the bounded retry's success/failure paths. This follow-up's final validation and deployment are pending at this checkpoint.

This follow-up passed 223 Node integration tests, TypeScript, production build and ten browser workflows (18.3 seconds). PHP/schema remain unchanged. Its new CI and production release remain pending. The long live research run has retained twenty sources/eighteen page reads, but final report generation is still unverified; a later planning failure is under diagnosis rather than marked successful.

Routing/retry commit `6cd070188d27f8ef4d404aa7765796c49cbfc5cb` passed all four CI jobs (`40380408605`). Before its production release, inspection identified an orchestration fallback that could drain arbitrary unread results after a repeated action. The final correction instead supplies one bounded corrective planning turn after evidence has been read; another repeated action produces an explicitly limited report with unresolved gaps. It retains the mandatory first page inspection and never treats reading every search result as a completion goal. A regression test rejects any unrelated tool read in that case. Latest local validation: 224 Node tests, TypeScript, production build and ten browser workflows (18.9s), all passed. Final exact-commit CI, deployment and real report acceptance remain pending.

Read-only infrastructure diagnostics with the small and saved public-document request bodies both returned the expected unauthenticated HTTP 401 JSON; neither established a security-layer rejection. No firewall, authorization or provider restriction was weakened. Public health/admin login checks returned HTTP 200; unauthenticated private project API returned HTTP 401.
