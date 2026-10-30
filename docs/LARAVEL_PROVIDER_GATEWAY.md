# Frontend inference and Laravel backend integration

The former dedicated `POST /api/v1/provider/invoke` route was removed from
the frontend-hosted workflow. It is no longer used or exposed. The current
intended architecture for the OpenMindAI chat frontend is:

**Browser -> Next.js server -> NVIDIA**

**Next.js server -> Laravel** at https://cloud.openmindai.org/api/v1 for
authentication, per-user quotas, model catalog, chat history, research
sources/checkpoints, task lifecycle and private media files.

The NVIDIA_API_KEY for browser-facing chat/media features is stored only in
the Next.js server's environment. It must never be prefixed NEXT_PUBLIC_.
The matching 32+ character FRONTEND_CHAT_BRIDGE_KEY is needed on both
Laravel and Next.js to authenticate private server-to-server storage flows.

Existing Laravel Developer API routes are separate and still available;
they may require their own Laravel NVIDIA_API_KEY. They are not the HTTP
transport for the ordinary browser chat and media workflow.

Chat completions and Auto routing, research-planner calls, memory extraction,
image, transcription, speech, document drafting and hosted video requests
are initiated by Next.js. User records and resulting private file uploads
are persisted by Laravel using existing authenticated endpoints.

For actual deployment and environment variables, see
[VERCEL_DEPLOYMENT.md](VERCEL_DEPLOYMENT.md) and
[NVIDIA_FRONTEND_CHAT.md](NVIDIA_FRONTEND_CHAT.md).
