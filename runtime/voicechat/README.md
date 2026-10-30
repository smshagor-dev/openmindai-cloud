# Nemotron VoiceChat realtime secure relay

NVIDIA's official [Realtime API Reference](https://github.com/NVIDIA-NeMo/Speech/blob/nemotron-labs-voicechat/voicechat_realtime_instructions/api-reference.md) documents a WebSocket `/v1/realtime` endpoint with JSON `session.created`, `session.update`, base64 `input_audio_buffer.append` events and streamed `response.output_audio.delta`. The client uses 24 kHz PCM16 mono chunks (about 80 ms recommended). It is **not** compatible with synchronous chat completion or REST audio generation.

NVIDIA Nemotron 3 VoiceChat is in [Early Access](https://developer.nvidia.com/nemotron-voicechat-early-access) and needs a GPU-backed realtime NIM/approved container. A displayed Free Endpoint badge does not confer use of that model or grant access.

## Secure OpenMindAI workflow

A desktop user generates their own Developer API Key and obtains an **ephemeral, single-use connection ticket** using:
`POST https://cloud.openmindai.org/api/v1/openmindai/voicechat/sessions`, header `Authorization: Bearer USER_DEVELOPER_API_KEY`. The API responds 201 with `ws_url` (example `wss://voice.openmindai.org/v1/realtime?ticket=...`), ticket validity 60 seconds, audio format and session ID.

Connect with a WebSocket to that returned URL, then follow NVIDIA's exact session protocol. The relay claims the one-time ticket from Laravel using a private worker credential and opens the upstream private model WebSocket. The desktop **never receives** the NVIDIA API key or shared worker secret. Once claimed, the same ticket cannot be used again. Use only TLS (WSS); disable reverse-proxy query-string access logging and do not paste a returned URL in chat because it contains a short-lived credential.

`GET /api/v1/openmindai/voicechat/sessions/{id}` checks user-owned status. `DELETE` of the same path revokes the session record. Only sessions in which the NVIDIA model actually emitted audio consume one application request credit; the callback is idempotent. The model's registered active state does not imply NVIDIA provider verification.

## Private relay deployment

Deploy the realtime VoiceChat NIM from [NVIDIA's deployment guide](https://github.com/NVIDIA-NeMo/Speech/blob/nemotron-labs-voicechat/voicechat_realtime_instructions/deploy.md) on a GPU-capable server (not PHP-only shared hosting). Start the official server with its `/v1/realtime` endpoint, preferably bound to loopback. Run the separate Python relay in this repository:

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r runtime/voicechat/requirements.txt
export VOICECHAT_BACKEND_API_URL="https://cloud.openmindai.org/api/v1"
export VOICECHAT_NIM_WS_URL="ws://127.0.0.1:9000/v1/realtime"
export VOICECHAT_WORKER_KEY="<shared random server-only secret, at least 32 characters>"
uvicorn runtime.voicechat.server:app --host 127.0.0.1 --port 8780
```

Put a TLS reverse proxy in front of port 8780 at `wss://voice.openmindai.org/v1/realtime`, forwarding WebSocket upgrades and never logging query strings. Laravel must set `VOICECHAT_PUBLIC_WSS_URL=wss://voice.openmindai.org/v1/realtime` and `VOICECHAT_WORKER_KEY` to the same private credential. The Laravel worker callback endpoints use `X-OpenMindAI-Voicechat-Worker`; do **not** expose that credential to app users.

The relay validates incoming JSON, PCM16 chunk sizes, protocol and session instructions, disallows arbitrary tool execution, limits each session to 15 minutes, and relays model responses. The server bills one app request credit after an actual streamed model audio event; incomplete and failed sessions do not consume that success credit. Laravel Scheduler clears stale issued/claimed tickets and removes expired records.

**Limitations:** GitHub mock tests prove token issuance, one-time claim, access control, gateway event validation and billing behavior; they do not prove NVIDIA entitlement, deployed worker connectivity, end-to-end audio quality, voice latency or external TLS readiness. Only mark provider live-verified after a real upstream VoiceChat session on the deployed NVIDIA NIM.
