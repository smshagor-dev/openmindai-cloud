# StreamPETR hosted scene inference

NVIDIA documents the official hosted endpoint at https://docs.api.nvidia.com/nim/reference/nvidia-streampetr-infer:

`POST https://ai.api.nvidia.com/v1/av/nvidia/streampetr` with `{"scene_id":"scene-0103"}`.

The hosted API processes NVIDIA's predefined nuScenes scenario IDs, **not arbitrary uploaded driving footage**. It may return a ZIP file (HTTP 200), or more commonly a temporary pre-signed Amazon S3 redirect (HTTP 302). The ZIP includes a `.response` JSON item containing camera/BEV MP4 data and inference metadata.

The OpenMindAI Cloud backend now supports:

`POST /api/v1/openmindai/av/streampetr/jobs`, authenticated with the user's Developer API Key (Bearer), JSON `{"scene_id":"scene-0103"}`. Other `scene-####` identifiers are syntactically allowed but require NVIDIA support. Returns HTTP 202 with a job ID.

`GET /api/v1/openmindai/av/jobs/{id}` returns progress. On completion, `GET /api/v1/openmindai/av/jobs/{id}/download` returns the private ZIP, authenticated to the original account. Jobs require a Laravel queue worker, server-side NVIDIA_API_KEY, and Scheduler; results expire after 24h.

Provider redirects are never followed automatically. Only direct HTTPS S3 hosts ending in approved AWS S3 patterns may be fetched, without forwarding NVIDIA Bearer credentials, then ZIP structure is checked before private storage. Any bad redirect, API rejection, malformed ZIP, timeout or missing queue worker yields an explicit failed job; failed requests consume no completed app credit.

Do not claim live verification until NVIDIA actually accepts an authorized scene job and produces a ZIP. A configured Developer API Key only authorizes OpenMindAI Cloud, not NVIDIA's upstream service.
