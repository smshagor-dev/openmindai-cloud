# Tool flow verification — 22 October 2026

## Observed live

- DOCX generation initially reached drafting but its completion callback returned HTTP 500. The production log identified a truncated `output_mime` column: DOCX uses a 71-character MIME type, while the MySQL column was VARCHAR(64).
- Expanded the column to VARCHAR(128), preserving existing records. Added a permanent migration and a DOCX save/download regression test.
- Retried the same request through chat.openmindai.org. Task 9 completed, its preview showed `OMA-4729`, and its authenticated download succeeded. The downloaded ZIP passed integrity verification and contained the exact marker in `word/document.xml`.
- A real multimodal request from the backend to the enabled 11B Vision model returned HTTP 200 and correctly identified a synthetic red PNG as `Red.`. This establishes a basic image-understanding check, not a broad OCR/visual benchmark.
- The initial selected Web Search request retrieved search portals instead of the requested PHP documentation. These were not relevant evidence.

## Changes

- Reject common search-engine home/search pages as research results so an unusable provider can fall through to the next provider.
- Auto mode with Web Search now uses native tool calling to form a focused query from the full request, then search/read; it no longer sends the entire instruction through the legacy initial quick-search path. This path uses the enabled Lite coordinator before the failing Super endpoint. Explicit user model choices and no-browse instructions remain honored.
- Image attachments can select enabled 11B/90B Vision or Omni models, with Super retained as the final supported fallback. They never fall back to text-only inference after a vision failure.
- PDF/DOCX/legacy DOC extracted text can be reviewed and attached directly to a chat. Text PDFs are parsed locally using the serverless PDF.js build from `unpdf`, with file/page/text limits and resource cleanup. Scanned PDFs without text report that OCR is required. Oversized extracted text is not silently attached or truncated into chat.
- Standalone document drafting uses the authenticated Laravel chat provider route and does not require a frontend NVIDIA key. Other media engine configuration requirements remain distinct.
- File-save failures include the backend HTTP status; known missing ZIP support has an actionable message without exposing raw upstream bodies.

## Validation boundaries

The real DOCX generation, save, preview and download passed after the live schema correction. PDF generation/extraction round-trip, native search/read orchestration, vision routing, private DOCX storage, and portal filtering have automated regression coverage. Frontend type checking and production build pass. These do not by themselves establish deployed UI success for every newly changed path.

Image generation/editing remains blocked by the previously observed provider timeout/404 and missing private editing NIM configuration. No substitute placeholder image is returned as a successful generation. Private filesystem access is not granted to a language model; read/write is scoped to reviewed attachments, private media artifacts and the existing user-owned workspace APIs.

A subsequent real backend search returned generic PHP pages for the specific `array_map` request. Added identifier relevance filtering so these results cannot satisfy a function-specific query; the service tries the next provider or fails explicitly. A Vercel deployment attempt reported `Deployment rate limited — retry in 24 hours`, so the new frontend UI could not be released or live-verified during this run. The existing production frontend remains in place.
