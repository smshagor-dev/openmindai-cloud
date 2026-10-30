# Hosted media integrations

OpenMindAI uses Next.js as its trusted API client. Laravel owns login,
quota checks, task records, private file storage, and authenticated downloads.
The NVIDIA_API_KEY stays exclusively on the Next.js server; never expose it in browser JavaScript. The
existing FRONTEND_CHAT_BRIDGE_KEY (32+ characters) must match on Next.js
and Laravel. No new public browser credentials are required.

## Capabilities and client paths

| Feature | Frontend path | Hosted model/API | Stored result |
| --- | --- | --- | --- |
| Image | media/image | FLUX.1-schnell | PNG/JPEG |
| Speech-to-text | media/transcription | Parakeet CTC 1.1B ASR HTTP | Transcribed text |
| Speech generation | media/speech | Magpie Multilingual TTS HTTP | WAV |
| Document creation | media/document_create | Configured chat model + file builder | DOCX/PDF |
| Video creation | media/video | Cosmos3 Nano text-to-video | MP4 |
| Live voice | Voice panel, Live workspace | Hosted transcription + chat stream + browser speech synthesis | Conversation text/history |

Source-controlled provider endpoints are fixed in Laravel; the browser cannot
supply arbitrary provider URLs, model IDs or API keys. Each supported request uses the existing
HttpOnly login cookie and an origin-checked Next.js API route. The Next.js
server calls a protected Laravel begin/complete/fail bridge; Laravel stores
results under private storage/app, subject to account ownership, daily usage
and pending task limits. The existing Library supports preview/download/delete.
Hosted outputs are only marked complete after Laravel confirms persistence.

Speech-to-text is available in English using the selected default hosted ASR
endpoint. The frontend converts compatible recorded/uploaded audio into
16 kHz mono PCM WAV (up to 2 minutes/8 MB) before inference. Unsupported
browser codecs produce an error instead of a false success. Alternative
languages require a compatible ASR model and language-specific routing.

Speech generation now offers documented English (en-US), Spanish (es-US)
and French (fr-FR) voices, selected from the Media studio. The server maps
each locale to its allowed voice and rejects unsupported languages before
reserving user quota. Generated WAV is saved privately. Additional languages
and voices require verified endpoint support; Bengali is not supported by
this hosted voice configuration. Live voice
has a hosted speech-to-text fallback when the separate private warm voice
runtime is unavailable. Chat responses are played back with the browser's
speech synthesis; this is sequential turn-taking, not native duplex streaming.

Document creation uses the configured server-only chat model for content, then
constructs real DOCX or PDF bytes without a local GPU. DOCX preserves Unicode
text. The bundled PDF builder uses an internal Helvetica/WinAnsi font and
rejects unsupported non-Latin scripts instead of silently corrupting them;
choose DOCX for Bengali and other non-Latin languages. PDF output supports
basic paragraphs, line wrapping and pagination, not complex layouts or charts.
Existing 'Read PDF or DOCX' remains separate and still depends on the private
media runtime if enabled.

Video uses the documented Cosmos3 Nano short text-to-video endpoint, validates
MP4 headers and stores the result. This is not the same as arbitrary motion
editing or commercial video generation. Large/slow video responses require
a host that accepts up to 180 seconds of server processing and private
binary payloads up to 20 MB.

## Hosting

- On the Next.js server set NVIDIA_API_KEY, LARAVEL_API_URL pointing to
  https://cloud.openmindai.org/api/v1 and FRONTEND_CHAT_BRIDGE_KEY.
  On Laravel set the identical FRONTEND_CHAT_BRIDGE_KEY.
  Do not use NEXT_PUBLIC_ with any secret; Laravel independently handles
  chat history, authenticated usage limits and private media storage.
- Run the existing Laravel migrations (media_tasks and media_daily_usage),
  config cache and scheduler. All media tasks use the same existing quotas.
- Allow request bodies up to 11 MB for transcription uploads and up to 28 MB
  for the private provider-to-Laravel complete bridge (post_max_size >= 32M).
- Enable PHP ZipArchive for saving generated DOCX files.
- Grant the API account access to each selected model; an API key existing
  for chat does not prove that speech, image or video endpoints are accessible.
- Keep allowed outbound provider domains restricted to the documented fixed
  endpoints. Do not forward provider error response bodies to clients.

The API bridge makes the request synchronously; a short-lived shared-hosting
process or timeout can interrupt a long-running provider call. If the browser
cancels after Laravel creates a task, the Next.js bridge will not start further
inference or save a late provider result, and it attempts to mark the task
failed. This prevents cancelled work from being reported as complete, but it
does not guarantee that a provider has stopped billing or running an already
accepted request. Deleting a task while generation is in progress removes
its private record and rejects later attempts to save output. The scheduler
handles abandoned tasks when a process exits before recording failure.
Production-grade long video jobs may require a durable background worker.

## Verification before production

1. Run frontend typecheck, build and integration tests.
2. Run Laravel tests covering protected task begin/complete/fail and downloads.
3. On the actual deployed account, record WAV audio and verify saved text,
   generate speech and download/play WAV, generate DOCX and PDF, generate MP4
   and play it from Library, and complete an entire live voice conversation.
4. Check invalid content, model refusal, quota exhaustion, rate limits,
   ownership, browser permissions, network interruption and retries.
5. Inspect browser network traffic for secret exposure, and confirm that
   user-visible UI does not reveal third-party model names.

Source tests use simulated provider fixtures. They do not prove the key has
access or that provider endpoints are currently healthy.

Official references:
- https://build.nvidia.com/nvidia/parakeet-ctc-1_1b-asr/api
- https://build.nvidia.com/nvidia/magpie-tts-multilingual/api
- https://build.nvidia.com/nvidia/cosmos3-nano/modelcard


### Image task remains queued

Image creation now uses Laravel's dedicated `media` database connection and the
`media` queue. A running default queue worker does not consume these jobs.
On the Laravel host, start a supervised worker from the application directory:

```sh
php artisan queue:work media --queue=media --sleep=3 --timeout=900 --tries=1
```

For cPanel hosts that allow cron workers, configure a guarded cron using the
actual PHP binary and application path:

```cron
* * * * * flock -n /home/USER/openmind-media.lock /opt/cpanel/ea-php82/root/usr/bin/php /home/USER/online-model/artisan queue:work media --queue=media --stop-when-empty --max-time=900 --timeout=900 --tries=1 >> /home/USER/online-model/storage/logs/media-worker.log 2>&1
```

Keep the existing scheduler cron. After backend updates, run migrations, refresh
configuration and restart workers. Verify a new task changes from queued to
running to complete and that its private image downloads successfully. An
accepted task is still processing; chat must retain its ID and must not describe
it as a failed generation or retry it just because the provider is slow.
