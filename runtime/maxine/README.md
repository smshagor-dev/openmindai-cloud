# NVIDIA Maxine gRPC worker

Use this **separate Python 3.10+ service**, not PHP shared hosting, to run the official NVIDIA hosted gRPC clients:

- Background Noise Removal: `bnr/scripts/bnr.py`, hosted function `c95bfe76-c553-4975-bc18-8ef9d6e2666b`.
- Studio Voice: `studio-voice/scripts/studio_voice.py`, hosted function `3f0aeba3-6d91-4465-b8cc-cc2aef355186`.
- 3D Body Pose: `body-pose/scripts/body_pose.py`, hosted function `254b3621-bb66-4b7b-9c89-621c352b4d91`.

The commands, target `grpc.nvcf.nvidia.com:443`, inputs and output contracts come from the official NVIDIA Build API documentation. You must install the reviewed [NVIDIA-Maxine/nim-clients](https://github.com/NVIDIA-Maxine/nim-clients) checkout and its **model-specific requirements.txt** into this worker's Python environment. Always pin the checkout commit and package versions in production.

Suggested setup on a worker-capable Linux machine:

```bash
git clone https://github.com/NVIDIA-Maxine/nim-clients.git /opt/nim-clients
# Review/pin a stable commit before production deployment.
python3 -m venv .venv
. .venv/bin/activate
pip install -r runtime/maxine/requirements.txt
pip install -r /opt/nim-clients/bnr/requirements.txt
pip install -r /opt/nim-clients/studio-voice/requirements.txt
pip install -r /opt/nim-clients/body-pose/requirements.txt
export NVIDIA_NIM_CLIENTS_ROOT=/opt/nim-clients
export NVIDIA_API_KEY="your-server-only-NVIDIA-key"
export NVIDIA_MAXINE_WORKER_KEY="random-server-only-secret-with-at-least-32-chars"
uvicorn runtime.maxine.server:app --host 127.0.0.1 --port 8773
```

On separate infrastructure, place a TLS reverse proxy with authentication in front of the sidecar and set Laravel's `NVIDIA_MAXINE_WORKER_URL` to that trusted HTTPS origin. Laravel requires `NVIDIA_MAXINE_WORKER_KEY` (same 32+ char secret). Never expose the worker to arbitrary browsers or publish NVIDIA credentials; its request body holds private user media. Access to the worker and OS process list must be restricted, because NVIDIA's official gRPC scripts receive their API key as a CLI argument.

Laravel provides `POST /api/v1/openmindai/maxine/{slug}/jobs` with a binary multipart `file`, and `GET /api/v1/openmindai/maxine/jobs/{id}` / `.../{id}/download` with a **per-user Developer API Key**. WAV must be RIFF/WAVE, and MP4 must contain an `ftyp` box. A queued request needs Laravel database jobs + scheduler, can be polled and is downloaded only by the original authenticated user; completed jobs consume one application credit. Media output expires after 24 hours. The bridge cannot be called through a browser or from the desktop directly.

NVIDIA's provider entitlement, remote gRPC uptime, and model compatibility require a real end-to-end invocation on the deployed bridge. No implementation or mocked CI test alone is evidence of `Verified live` provider access.

**Body Pose contract:** Upload an additional `bbox_file` alongside the MP4: NVIDIA requires tracked-body bounding boxes. Its official Python client writes JSON Lines to `.json`, not Arrow. Results are served with `application/x-ndjson`. See the official `body-pose/scripts/config.py` for the required annotation layout.

## Hosted video workflows

**Active Speaker Detection:** `active-speaker-detection/scripts/active_speaker_detection.py`, gRPC function `f286f937-05c4-454b-8312-fba67a2a6fa7`. Upload `file` as MP4 and `diarization_file` as nonempty JSON, using `POST /api/v1/openmindai/maxine/nvidia-active-speaker-detection/jobs`. The bridge uses NVIDIA `--skip-audio` mode, extracting audio embedded in the video. The output is a private annotated MP4.

**Synthetic Video Detector:** NVIDIA's **v1** preview client `synthetic-video-detector/scripts/v1/synthetic-video-detector.py` uses hosted function `847b6e53-0133-452d-ab85-d7acf3ace723`; its v2 interface differs. Upload an MP4 to `/api/v1/openmindai/maxine/nvidia-synthetic-video-detector/jobs`. Output is private `text/csv` with frame indices and synthetic probabilities. **The NVIDIA account must have private-access approval**; the presence of API code does not grant entitlement.

Install the official `active-speaker-detection/requirements.txt` and `synthetic-video-detector/requirements.txt` in the worker environment. No automatic NVIDIA live tests are executed against either model until permission is confirmed.
