"""One-time-ticket VoiceChat relay for the official NVIDIA realtime WebSocket NIM.

Client -> this relay (temporary user ticket) -> private NIM /v1/realtime.
The user never receives upstream GPU/worker credentials or a global app secret.
"""
import asyncio
import base64
import binascii
import json
import os
import re
from urllib.parse import urlsplit

import httpx
import websockets
from fastapi import FastAPI, WebSocket

app = FastAPI(title="OpenMindAI VoiceChat Relay", docs_url=None, redoc_url=None, openapi_url=None)

TICKET = re.compile(r"^[0-9a-f]{64}$")
CLIENT_EVENTS = {
    "session.update",
    "input_audio_buffer.append",
    "conversation.item.create",
    "session.close",
}

def valid_worker_settings():
    base = os.environ.get("VOICECHAT_BACKEND_API_URL", "").rstrip("/")
    target = os.environ.get("VOICECHAT_NIM_WS_URL", "ws://127.0.0.1:9000/v1/realtime")
    key = os.environ.get("VOICECHAT_WORKER_KEY", "")
    backend = urlsplit(base)
    upstream = urlsplit(target)
    allowed_backend = backend.scheme == "https" or (
        backend.scheme == "http" and backend.hostname in ("127.0.0.1", "localhost")
    )
    allowed_nim = upstream.scheme == "wss" or (
        upstream.scheme == "ws" and upstream.hostname in ("127.0.0.1", "localhost")
    )
    if not (allowed_backend and allowed_nim and len(key) >= 32
            and upstream.path == "/v1/realtime"
            and not upstream.query and not upstream.username and not upstream.password
            and not backend.query and not backend.fragment):
        raise ValueError("Secure backend and private NIM configuration required")
    return base, target, key


def check_client_event(raw: str) -> str:
    if not isinstance(raw, str) or len(raw.encode("utf-8")) > 50000:
        raise ValueError("Input message exceeds the permitted size")
    try:
        event = json.loads(raw)
    except (ValueError, TypeError) as exc:
        raise ValueError("Invalid realtime JSON") from exc
    if not isinstance(event, dict) or event.get("type") not in CLIENT_EVENTS:
        raise ValueError("Unsupported realtime event")
    if event["type"] == "input_audio_buffer.append":
        encoded = event.get("audio", "")
        if not isinstance(encoded, str) or len(encoded) > 16384:
            raise ValueError("Audio chunk is too long")
        try:
            chunk = base64.b64decode(encoded, validate=True)
        except binascii.Error as exc:
            raise ValueError("Audio must be valid base64 PCM16") from exc
        if not chunk or len(chunk) % 2 != 0 or len(chunk) > 12288:
            raise ValueError("Audio is invalid or exceeds the chunk limit")
    if event["type"] == "session.update":
        session = event.get("session")
        if not isinstance(session, dict) or len(str(session.get("instructions", ""))) > 4096:
            raise ValueError("Invalid realtime session")
        # Tool execution is intentionally not exposed through this bridge.
        if session.get("tools", []) != []:
            raise ValueError("Realtime tool execution is not configured")
        for direction in ("input", "output"):
            fmt = session.get("audio", {}).get(direction, {}).get("format", {})
            if fmt and not (isinstance(fmt, dict) and fmt.get("type") == "audio/pcm"
                            and fmt.get("rate") == 24000):
                raise ValueError("Only 24kHz PCM16 mono is supported")
    if event["type"] == "conversation.item.create":
        item = event.get("item")
        if not isinstance(item, dict) or item.get("type") != "function_call_output":
            raise ValueError("Only response messages may be returned")
    return event["type"]


async def backend_call(client: httpx.AsyncClient, base: str, key: str, path: str, payload: dict):
    response = await client.post(
        base + path,
        json=payload,
        headers={"X-OpenMindAI-Voicechat-Worker": key},
        timeout=12.0,
        follow_redirects=False,
    )
    if response.status_code != 200:
        raise RuntimeError("VoiceChat authorization or callback failed")
    return response.json()


@app.get("/health")
async def health():
    try:
        valid_worker_settings()
    except ValueError:
        return {"status": "configuration_required"}
    return {"status": "configured"}


@app.websocket("/v1/realtime")
async def realtime(ws: WebSocket):
    ticket = ws.query_params.get("ticket", "")
    if not TICKET.fullmatch(ticket):
        await ws.close(code=1008)
        return

    try:
        base, target, key = valid_worker_settings()
    except ValueError:
        await ws.close(code=1013)
        return

    session_id = None
    produced_audio = False
    accepted = False
    async with httpx.AsyncClient() as client:
        try:
            # Laravel atomically consumes the ticket. The relay never needs
            # any user's long-lived Developer API Key.
            claimed = await backend_call(client, base, key, "/voicechat/worker/claim", {
                "ticket": ticket,
            })
            session_id = claimed["id"]
            seconds = min(900, max(1, int(claimed.get("max_seconds", 900))))
            async with websockets.connect(
                target, open_timeout=10, max_size=262144,
                ping_interval=20, ping_timeout=20,
            ) as model:
                await ws.accept()
                accepted = True

                async def from_client():
                    async for message in ws.iter_text():
                        event_type = check_client_event(message)
                        await model.send(message)
                        if event_type == "session.close":
                            return

                async def from_model():
                    nonlocal produced_audio
                    async for message in model:
                        if not isinstance(message, str) or len(message.encode("utf-8")) > 262144:
                            raise ValueError("Upstream event exceeds the response limit")
                        try:
                            data = json.loads(message)
                        except ValueError as exc:
                            raise ValueError("Malformed NVIDIA realtime event") from exc
                        if isinstance(data, dict) and data.get("type") == "response.output_audio.delta":
                            produced_audio = produced_audio or bool(data.get("delta"))
                        await ws.send_text(message)

                t1 = asyncio.create_task(from_client())
                t2 = asyncio.create_task(from_model())
                try:
                    done, pending = await asyncio.wait(
                        {t1, t2}, timeout=seconds, return_when=asyncio.FIRST_COMPLETED,
                    )
                    for task in pending:
                        task.cancel()
                    await asyncio.gather(*pending, return_exceptions=True)
                    for task in done:
                        if not task.cancelled():
                            task.result()
                finally:
                    for task in (t1, t2):
                        if not task.done():
                            task.cancel()
        except (RuntimeError, ValueError, httpx.HTTPError, websockets.exceptions.WebSocketException):
            if accepted:
                try:
                    await ws.close(code=1011)
                except RuntimeError:
                    pass
            else:
                await ws.close(code=1008)
        finally:
            if session_id:
                try:
                    await backend_call(client, base, key, "/voicechat/worker/finish", {
                        "id": session_id, "produced_audio": produced_audio,
                    })
                except (RuntimeError, ValueError, httpx.HTTPError):
                    # The Laravel scheduler marks abandoned sessions closed.
                    pass
