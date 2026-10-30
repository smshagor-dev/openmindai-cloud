# Chat access and previews

Workspace settings now include **Guest chat requests per day per IP** (default 5; 0 requires login) and **Frontend chat inference**. Guest inference always runs through Laravel and keeps its conversation in the browser. Its allowance lasts 24 hours from the first request. Failed provider attempts also consume allowance. Shared networks share an IP allowance.

Backend mode sends hosted text/vision/research chat inference from Laravel. Direct mode sends it from the Next.js server. Both modes keep credentials private and retain Laravel authentication, history and usage accounting. The selector applies to chat inference; existing media generation integrations retain their current routing.

Deploy the Laravel changes together with the frontend changes. Laravel needs `NVIDIA_API_KEY`; both servers need the same `FRONTEND_CHAT_BRIDGE_KEY` (at least 32 characters). Next.js still uses `CHAT_PROVIDER=nvidia` to expose the hosted catalog. Direct mode also needs the provider key on Next.js. Existing backends without the setting keep their previous direct routing until upgraded. There is no new database migration: the settings service provides defaults and saves choices in the existing settings table.

Vercel forwards the client IP with a server-generated HMAC for guest quota enforcement. Other reverse proxies must use the existing `TRUST_PROXY_HEADERS=true` setting only when the hosting proxy replaces untrusted forwarding headers. Direct requests to Laravel use Laravel's resolved request IP. Never trust arbitrary client forwarding headers.

Saved conversations use `/c/{id}-{title-slug}` and remain private to their owner. Guest conversations use `/c/guest-{uuid}` and can reopen in the same browser. Refresh and Back/Forward restore the selected conversation. Temporary signed-in chats remain unsaved.

Fenced response code has syntax coloring, Copy and Open controls. HTML and SVG can run in a sandboxed preview with server and browser storage access isolated. Other languages open as highlighted source. The preview does not install packages or execute Python, PHP or other server languages.

PDF uses the browser's PDF viewer. Markdown renders locally. DOCX is read locally into a text preview, including paragraph text, without executing embedded content. Legacy DOC uses an authenticated Next.js parser and supports files up to 3 MB; its content is not sent to inference. Other local document previews support up to 8 MB. Generated documents can be opened from chat, Media Studio and Library.
