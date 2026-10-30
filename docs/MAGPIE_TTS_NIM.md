# NVIDIA Magpie ZeroShot Speech NIM integration

This model runs on **NVIDIA Speech NIM**, not an unrestricted NVIDIA Build trial URL. NVIDIA's latest [TTS NIM documentation](https://docs.nvidia.com/nim/speech/latest/get-started/tutorials/tts.html) provides `POST /v1/audio/synthesize` (multipart form) returning a WAV file, and the [NVIDIA Support Matrix](https://docs.nvidia.com/nim/speech/latest/reference/support-matrix/tts.html) confirms restricted access and GPU requirements.

You must obtain model entitlement from NVIDIA and deploy an approved GPU-hosted Speech NIM. Configure the Laravel server with `MAGPIE_TTS_NIM_URL=https://private-tts-worker.example` and `MAGPIE_TTS_NIM_TOKEN` (a separate random server-to-worker authentication token with >=32 characters, enforced by your TLS reverse proxy). It is **not** a user's Developer API Key. Never distribute the NVIDIA key, worker token or a global application secret inside OpenMind Studio binaries.

Create a generation with `POST /api/v1/openmindai/tts/generations`, with the user's `Authorization: Bearer DEVELOPER_API_KEY`. Use multipart form:
- `text`: 1–350 chars
- `language`: one of the supported Speech NIM language codes
- `voice`: `Magpie-ZeroShot-Multilingual.Female` or `Magpie-ZeroShot-Multilingual.Male` for built-in voices
- For optional voice cloning, use `voice=Magpie-ZeroShot-Multilingual`, attach `audio_prompt` in PCM mono 16-bit WAV at 22.05–96 kHz, and set `voice_consent=true`. Only upload a recording for which the speaker has authorized synthetic-voice use.

The API returns a 202 status and `status_path`. Poll `GET /api/v1/openmindai/tts/{id}` with the same Developer API Key, then download the private WAV at `GET /api/v1/openmindai/tts/{id}/download`.

The backend queue holds encrypted text, stores voice references only on private disk, deletes reference audio immediately after processing, validates output RIFF/WAVE, and uses completed-only credit accounting. An hourly Laravel Scheduler removes expired outputs after 24 hours. Configure a database-backed Laravel queue worker and scheduler on a worker-capable server; no self-hosted Speech NIM can run inside regular cPanel PHP shared hosting.

NVIDIA model access approval, remote NIM availability, output quality and production latency **remain to be verified with real provider calls**. Local and GitHub CI mock tests verify API lifecycle and safety only. Catalog `active=true` means registered and enabled, `available=true` means a backend inference route is implemented, and `provider_verified=true` requires a real recorded live test. NVIDIA approval cannot be bypassed by changing flags.
