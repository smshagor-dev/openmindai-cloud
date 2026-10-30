import base64
import json

import pytest

from runtime.voicechat.server import TICKET, check_client_event, valid_worker_settings


def test_realtime_accepts_only_bounded_pcm16_audio():
    audio = base64.b64encode(bytes([0, 0]) * 1920).decode("ascii")
    assert check_client_event(json.dumps({
        "type": "input_audio_buffer.append", "event_id": "abc", "audio": audio,
    })) == "input_audio_buffer.append"
    with pytest.raises(ValueError):
        check_client_event(json.dumps({
            "type": "input_audio_buffer.append", "audio": "not-base64",
        }))
    with pytest.raises(ValueError):
        check_client_event(json.dumps({
            "type": "input_audio_buffer.append",
            "audio": base64.b64encode(b"x" * 13000).decode(),
        }))


def test_session_update_enforces_pcm_and_no_untrusted_tool_actions():
    assert check_client_event(json.dumps({
        "type": "session.update",
        "session": {
            "audio": {"input": {"format": {"type": "audio/pcm", "rate": 24000}}},
            "tools": [],
        },
    })) == "session.update"
    with pytest.raises(ValueError):
        check_client_event(json.dumps({
            "type": "session.update",
            "session": {"tools": [{"name": "run_command"}]},
        }))
    with pytest.raises(ValueError):
        check_client_event(json.dumps({
            "type": "session.update",
            "session": {"audio": {"input": {"format": {"type": "audio/pcm", "rate": 48000}}}},
        }))
    with pytest.raises(ValueError):
        check_client_event(json.dumps({"type": "unknown.command", "text": "x"}))


def test_worker_only_accepts_secure_backend_and_private_nim(monkeypatch):
    monkeypatch.setenv("VOICECHAT_BACKEND_API_URL", "https://cloud.openmindai.org/api/v1")
    monkeypatch.setenv("VOICECHAT_NIM_WS_URL", "ws://127.0.0.1:9000/v1/realtime")
    monkeypatch.setenv("VOICECHAT_WORKER_KEY", "s" * 40)
    base, target, secret = valid_worker_settings()
    assert base.endswith("/api/v1")
    assert target.endswith("/v1/realtime")
    assert len(secret) == 40

    monkeypatch.setenv("VOICECHAT_NIM_WS_URL", "ws://attacker.example/v1/realtime")
    with pytest.raises(ValueError):
        valid_worker_settings()
    monkeypatch.setenv("VOICECHAT_NIM_WS_URL", "ws://127.0.0.1:9000/v1/realtime")
    monkeypatch.setenv("VOICECHAT_WORKER_KEY", "short")
    with pytest.raises(ValueError):
        valid_worker_settings()


def test_ticket_format_is_single_use_compatible():
    assert TICKET.fullmatch("a" * 64)
    assert not TICKET.fullmatch("a" * 65)
    assert not TICKET.fullmatch("anything?ticket=abc")
