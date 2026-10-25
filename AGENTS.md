# Repository working instructions

## Durable progress

The user explicitly authorized GitHub working-branch checkpoints on 2026-10-25 after local-only work became unavailable. This replaces the earlier instruction to defer every push until implementation is complete.

- Inspect Git status and the current remote branch before editing. Preserve unrelated changes.
- Work on a dedicated feature branch; Research Studio uses `feature/research-studio`.
- Commit and push small, coherent work checkpoints during implementation. Verify the commit is reachable from the remote branch before saying it is saved. A local commit alone is not a durable checkpoint.
- If the local checkout is unavailable, recover from GitHub before rebuilding. Never claim previous local-only code or test results exist in the recovered branch without inspecting it.
- Record actual implemented work, test results and remaining tasks in `docs/RESEARCH_STUDIO_STATUS.md` for this feature. Mark unfinished checkpoints clearly.
- Keep checkpoint pushes separate from release approval. Do not open a misleading completion PR, merge to main, bypass protections or deploy an unfinished feature. Complete required verification before the final PR.
- Never force-push over another contributor's work. Re-read a changed remote head and reconcile safely.
- Never commit secrets, `.env` files, credentials, user uploads or server-specific private files. Do not reset the database or delete user data.
- Tests performed with fixtures or mocked providers are not real-provider verification. Skips and external blockers must remain explicit.

## Authorized deployment state

On 2026-10-25, after the missing isolated worker was explained, the user explicitly instructed deployment to proceed. This authorizes deploying the available Research Studio web application, APIs and bounded research queue consumers with processing-dependent operations still gated by the missing private worker. It does not certify those operations or authorize an unsafe execution fallback. Release `ca6497c60c63917a5f8f5cdc102904f930e56dbc` was deployed from `feature/research-studio` to the existing cPanel backend and existing chat frontend. Preserve that deployment and consult the latest status record before repeating maintenance. A completion PR/merge still requires the remaining acceptance checks; do not describe this limited deployment as full production acceptance.

## Research Studio objective

Integrate the multilingual autonomous research platform into the existing Laravel/Sanctum backend and Next.js Cloud frontend. Reuse the existing AI gateway and project authentication. Follow the user's full twelve-agent requirements, evidence/integrity gates, isolated experiment execution, persistent queues, activity events, academic editor, citations, collaboration and export requirements. Do not represent partial implementation as production-ready.
