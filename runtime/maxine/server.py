"""NVIDIA Maxine gRPC worker using the published NVIDIA-Maxine/nim-clients CLI.

Deploy this in a private, single-tenant Python environment. No user-provided
CLI paths, model IDs, hostnames, or function IDs are accepted.
"""
import base64
import binascii
import hmac
import os
from pathlib import Path
import subprocess
import sys
import tempfile

from fastapi import FastAPI, Header, HTTPException, Request
from fastapi.responses import JSONResponse

app = FastAPI(title="OpenMindAI Maxine Inference Worker", docs_url=None, redoc_url=None, openapi_url=None)

# Paths are relative to a separately installed, operator-reviewed NVIDIA
# official client checkout, not fetched or changed at request time.
SPECS = {
    "nvidia-background-noise-removal": {
        "script": "bnr/scripts/bnr.py",
        "function": "c95bfe76-c553-4975-bc18-8ef9d6e2666b",
        "input_option": "--input",
        "input_ext": ".wav",
        "output_ext": ".wav",
        "mime": "audio/wav",
        "max_bytes": 32000000,
        "extra": ["--streaming", "False"],
    },
    "nvidia-studio-voice": {
        "script": "studio-voice/scripts/studio_voice.py",
        "function": "3f0aeba3-6d91-4465-b8cc-cc2aef355186",
        "input_option": "--input",
        "input_ext": ".wav",
        "output_ext": ".wav",
        "mime": "audio/wav",
        "max_bytes": 32000000,
        "extra": [],
    },
    "nvidia-active-speaker-detection": {
        "script": "active-speaker-detection/scripts/active_speaker_detection.py",
        "function": "f286f937-05c4-454b-8312-fba67a2a6fa7",
        "input_option": "--video-input", "input_ext": ".mp4",
        "output_ext": ".mp4", "mime": "video/mp4",
        "max_bytes": 20000000, "extra": [],
    },
    "nvidia-synthetic-video-detector": {
        "script": "synthetic-video-detector/scripts/v1/synthetic-video-detector.py",
        "function": "847b6e53-0133-452d-ab85-d7acf3ace723",
        "input_option": "--video-input", "input_ext": ".mp4",
        "output_ext": ".csv", "mime": "text/csv",
        "max_bytes": 20000000, "extra": [],
    },
    "nvidia-3d-body-pose": {
        "script": "body-pose/scripts/body_pose.py",
        "function": "254b3621-bb66-4b7b-9c89-621c352b4d91",
        "input_option": "--video-input",
        "input_ext": ".mp4",
        "output_ext": ".json",
        "mime": "application/x-ndjson",
        "max_bytes": 20000000,
        "extra": [],
    },
}


def run_model(model: str, payload: bytes, *, root: Path, api_key: str, boxes: bytes | None = None, diarization: bytes | None = None) -> tuple[str, bytes]:
    spec = SPECS.get(model)
    if spec is None:
        raise ValueError("Unknown model")
    if len(payload) < 16 or len(payload) > spec["max_bytes"]:
        raise ValueError("Unsupported input size")
    if spec["input_ext"] == ".wav":
        if payload[:4] != b"RIFF" or payload[8:12] != b"WAVE":
            raise ValueError("Input must be a WAV file")
    elif payload[4:8] != b"ftyp":
        raise ValueError("Input must be an MP4 file")

    script = (root / spec["script"]).resolve()
    if not script.is_relative_to(root.resolve()) or not script.is_file():
        raise RuntimeError("Official NVIDIA client not installed")
    if len(api_key) < 12:
        raise RuntimeError("NVIDIA_API_KEY missing")

    with tempfile.TemporaryDirectory(prefix="maxine-") as temp:
        incoming = Path(temp) / ("input" + spec["input_ext"])
        outgoing = Path(temp) / ("output" + spec["output_ext"])
        incoming.write_bytes(payload)
        bbox_path = None
        if model == "nvidia-3d-body-pose":
            if not boxes or len(boxes) > 1048576:
                raise ValueError("Tracked bounding-box annotations required")
            bbox_path = Path(temp) / "bbox.txt"
            bbox_path.write_bytes(boxes)
        diarization_path = None
        if model == "nvidia-active-speaker-detection":
            if not diarization or len(diarization) > 1048576:
                raise ValueError("Speaker diarization JSON required")
            import json
            try:
                if not isinstance(json.loads(diarization), (dict, list)):
                    raise ValueError("Invalid diarization")
            except (ValueError, UnicodeError) as exc:
                raise ValueError("Invalid diarization") from exc
            diarization_path = Path(temp) / "diarization.json"
            diarization_path.write_bytes(diarization)
        cmd = [
            sys.executable,
            str(script),
            "--preview-mode",
            "--ssl-mode", "TLS",
            "--target", "grpc.nvcf.nvidia.com:443",
            "--function-id", spec["function"],
            "--api-key", api_key,
            spec["input_option"], str(incoming),
            *spec["extra"],
        ]
        if model == "nvidia-synthetic-video-detector":
            cmd += ["--save-csv", str(outgoing)]
        else:
            cmd += ["--output", str(outgoing)]
        if bbox_path:
            cmd += ["--bbox-input", str(bbox_path)]
        if diarization_path:
            cmd += ["--diarization-input", str(diarization_path), "--skip-audio"]
        try:
            subprocess.run(
                cmd, check=True, stdout=subprocess.DEVNULL,
                stderr=subprocess.DEVNULL, timeout=720,
                cwd=str(script.parent),
            )
        except (subprocess.TimeoutExpired, subprocess.CalledProcessError) as exc:
            raise RuntimeError("NVIDIA inference could not finish") from exc
        if not outgoing.is_file():
            raise RuntimeError("Expected NVIDIA output missing")
        result = outgoing.read_bytes()
        if not 12 <= len(result) <= 25165824:
            raise RuntimeError("Invalid NVIDIA output length")
        if spec["mime"] == "audio/wav" and (result[:4] != b"RIFF" or result[8:12] != b"WAVE"):
            raise RuntimeError("Invalid output WAV structure")
        if model == "nvidia-3d-body-pose":
            import json
            try:
                records = [json.loads(line) for line in result.decode("utf-8").splitlines() if line.strip()]
                if not records:
                    raise ValueError("Empty pose output")
            except (ValueError, UnicodeError) as exc:
                raise RuntimeError("Invalid pose JSON Lines output") from exc
        if model == "nvidia-active-speaker-detection" and result[4:8] != b"ftyp":
            raise RuntimeError("Invalid MP4 output")
        if model == "nvidia-synthetic-video-detector" and not result.startswith(b"index,probability\n"):
            raise RuntimeError("Invalid CSV output")
        return spec["mime"], result


@app.post("/infer")
async def infer(request: Request, authorization: str | None = Header(default=None)):
    worker_key = os.environ.get("NVIDIA_MAXINE_WORKER_KEY", "")
    if len(worker_key) < 32 or not authorization or not hmac.compare_digest(
        authorization, "Bearer " + worker_key
    ):
        raise HTTPException(status_code=401, detail="Unauthorized")
    # Maximum 32MB WAV or 20MB MP4, base64-encoded JSON; bound memory.
    if request.headers.get("content-length") and int(request.headers["content-length"]) > 44000000:
        raise HTTPException(status_code=413, detail="Payload too large")
    body = await request.body()
    if len(body) > 44000000:
        raise HTTPException(status_code=413, detail="Payload too large")
    try:
        import json
        data = json.loads(body)
        model = data["model"]
        spec = SPECS[model]
        encoded = data["data"]
        if not isinstance(encoded, str) or len(encoded) > 4 * ((spec["max_bytes"] + 2) // 3):
            raise ValueError("Input too large")
        binary = base64.b64decode(encoded, validate=True)
        boxes = None
        if model == "nvidia-3d-body-pose":
            raw_boxes = data.get("bbox")
            if not isinstance(raw_boxes, str) or len(raw_boxes) > 1500000:
                raise ValueError("Bounding-box file required")
            boxes = base64.b64decode(raw_boxes, validate=True)
        diarization = None
        if model == "nvidia-active-speaker-detection":
            raw_diarization = data.get("diarization")
            if not isinstance(raw_diarization, str) or len(raw_diarization) > 1500000:
                raise ValueError("Diarization file required")
            diarization = base64.b64decode(raw_diarization, validate=True)
        output_mime, output_bytes = run_model(
            model, binary, root=Path(os.environ.get("NVIDIA_NIM_CLIENTS_ROOT", "/opt/nim-clients")),
            api_key=os.environ.get("NVIDIA_API_KEY", ""), boxes=boxes,
            diarization=diarization,
        )
    except (ValueError, KeyError, TypeError, binascii.Error):
        raise HTTPException(status_code=422, detail="Invalid media request")
    except RuntimeError:
        raise HTTPException(status_code=502, detail="Inference temporarily unavailable")
    return JSONResponse({
        "mime": output_mime,
        "data": base64.b64encode(output_bytes).decode("ascii"),
    })


@app.get("/health")
def health(authorization: str | None = Header(default=None)):
    worker_key = os.environ.get("NVIDIA_MAXINE_WORKER_KEY", "")
    if len(worker_key) < 32 or not authorization or not hmac.compare_digest(
        authorization, "Bearer " + worker_key
    ):
        raise HTTPException(status_code=401, detail="Unauthorized")
    root = Path(os.environ.get("NVIDIA_NIM_CLIENTS_ROOT", "/opt/nim-clients")).resolve()
    return {"ready": bool(os.environ.get("NVIDIA_API_KEY")) and all(
        (root / spec["script"]).is_file() for spec in SPECS.values()
    )}
