# Chat generation integration

## Behavior

- Clear image/document/speech/video creation requests use the existing authenticated media services even when a text model is explicitly selected. Ordinary text, code, explanations and negated generation requests retain their existing routing.
- Tools > Create image and Create document select the chat composer mode and focus the prompt; they do not open a separate generation dialog. The existing Images navigation selects the same composer flow.
- Generation stays in a saved conversation. Accepted task links are checkpointed immediately, including queued images, and become inline preview cards in the assistant turn after refresh. A queued task is never presented as a completed file or automatically regenerated.
- Library displays lazy image thumbnails and small previews extracted from the actual generated PDF/DOCX. Status and output requests retain their existing ownership checks and cookie-based authentication.
- No database migration, provider secret, new billing flow or worker configuration is introduced.
- Source-dependent requests and requests referring to earlier conversation turns retain the native context/tool workflow; the standalone creation shortcut does not discard their context or skip requested research.

## Validation checkpoint

- Local TypeScript check: passed.
- Local Next.js production build: passed.
- Local frontend integration suite: 233 passed, zero failures. Fixtures include explicit-model generation, queued task persistence, single execution, error responses, unsafe URL rejection and real PDF/DOCX byte generation. These are deterministic mocked-provider tests.
- Added eight browser scenarios at 1440, 768, 375 and 320 px for composer selection without a generation popup, queued-to-completed status, actual image/document rendering, refresh recovery without resubmission and Library thumbnails. Existing eight research browser scenarios remain enabled.
- Local browser package download failed (the vendor archive was truncated); browser scenarios require CI verification before release.
- Real-provider smoke tests and release are pending at this checkpoint. Do not describe fixture results as live inference.

Research Studio's separate literature final-comparison failure and private experiment/export worker requirements are unchanged.
