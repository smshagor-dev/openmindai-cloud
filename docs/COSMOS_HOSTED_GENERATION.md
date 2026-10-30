
## Cosmos3 Nano hosted image/video generation

NVIDIA's documented managed endpoint is `POST https://ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano` (see https://build.nvidia.com/nvidia/cosmos3-nano/modelcard). Unlike chat completions, it returns base64 JPEG/MP4 media and may take a long time. Do **not** use the generic JSON `/models/{slug}/invoke` route for this model.

The Laravel backend provides an asynchronous, per-user generation workflow:

1. `POST /api/v1/openmindai/cosmos/generations` with `Authorization: Bearer YOUR_DEVELOPER_API_KEY` and JSON `{"model_mode":"text2video","prompt":"A robot arm places a box on a shelf"}`. Modes `text2image` and `text2video` are supported. The backend uses fixed short 480p previews; user-supplied video URLs, extra media and arbitrary model IDs are rejected.
2. Receive HTTP 202 containing the unique generation ID and `status_path`. Poll `GET /api/v1/openmindai/cosmos/{id}` with the same Bearer key.
3. On `completed`, download the private JPEG or MP4 at `GET /api/v1/openmindai/cosmos/{id}/download` with that user's own Bearer key. Files and encrypted prompt are not served publicly, and storage files are removed after 24 hours by Laravel Scheduler.

Deployment requires `php artisan migrate --force`, a configured `NVIDIA_API_KEY`, working private Laravel storage, database-backed queue tables, a **long-running-capable CLI worker** and Laravel Scheduler. To process queued jobs on shared hosting, run a suitable cron command such as `php artisan queue:work database --queue=default --stop-when-empty --timeout=900 --tries=1` and also `php artisan schedule:run` every minute. Server PHP CLI limits and cron execution limits must allow Cosmos generation; if your shared host terminates long CLI processes, move the media worker to a worker-capable service, not the web PHP process. A queued response does not mean the provider has generated anything yet.

The server validates the returned file magic and bounds response size; NVIDIA model keys never enter client responses. Successful completed jobs consume one OpenMindAI app credit and enforce user/model monthly limits. Queue or provider failures do not falsely charge a success. Actual NVIDIA entitlement and successful media generation must be checked on the deployed environment, independently of regression mock tests.
