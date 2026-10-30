import pathlib
import subprocess
import tempfile
from unittest.mock import patch

from runtime.maxine.server import SPECS, run_model


def fake_wave():
    return b"RIFF" + b"\x00" * 4 + b"WAVE" + b"x" * 40


def test_models_derive_commands_only_from_allowlist():
    with tempfile.TemporaryDirectory() as base:
        root = pathlib.Path(base)
        for spec in SPECS.values():
            script = root / spec["script"]
            script.parent.mkdir(parents=True, exist_ok=True)
            script.write_text("pass\n")
        for slug in ("nvidia-background-noise-removal", "nvidia-studio-voice"):
            with patch("runtime.maxine.server.subprocess.run") as run:
                def complete(cmd, **kwargs):
                    path = pathlib.Path(cmd[cmd.index("--output") + 1])
                    path.write_bytes(fake_wave())
                run.side_effect = complete
                mime, result = run_model(slug, fake_wave(), root=root, api_key="test-server-key")
                assert mime == "audio/wav"
                assert result == fake_wave()
                cmd = run.call_args.args[0]
                assert "grpc.nvcf.nvidia.com:443" in cmd
                assert SPECS[slug]["function"] in cmd
                assert run.call_args.kwargs["timeout"] == 720


def test_arbitrary_model_and_wrong_mime_fail():
    with tempfile.TemporaryDirectory() as base:
        root = pathlib.Path(base)
        for slug in ("nvidia-arbitrary-host", "../some-model"):
            try:
                run_model(slug, fake_wave(), root=root, api_key="test-server-key")
                raise AssertionError("Unrecognized model unexpectedly accepted")
            except ValueError:
                pass
        try:
            run_model("nvidia-3d-body-pose", fake_wave(), root=root, api_key="test-server-key")
            raise AssertionError("WAV passed as video")
        except ValueError:
            pass


def test_body_pose_uses_explicit_bbox_and_json_lines():
    with tempfile.TemporaryDirectory() as directory:
        root = pathlib.Path(directory)
        script = root / SPECS["nvidia-3d-body-pose"]["script"]
        script.parent.mkdir(parents=True, exist_ok=True)
        script.write_text("pass\n")
        video = b"\x00\x00\x00\x18ftypisom" + b"x" * 20
        with patch("runtime.maxine.server.subprocess.run") as execute:
            def finish(args, **kwargs):
                assert "--bbox-input" in args
                pathlib.Path(args[args.index("--output") + 1]).write_bytes(b'{"frame":0,"poses":[]}\n')
            execute.side_effect = finish
            mime, output = run_model("nvidia-3d-body-pose", video,
                root=root, api_key="test-provider-key", boxes=b"1\n0 0 1 2 3 4\n")
            assert mime == "application/x-ndjson"
            assert b'"frame":0' in output


def test_svd_preview_uses_official_v1_client_csv_output():
    with tempfile.TemporaryDirectory() as directory:
        root = pathlib.Path(directory)
        spec = SPECS["nvidia-synthetic-video-detector"]
        script = root / spec["script"]
        script.parent.mkdir(parents=True, exist_ok=True)
        script.write_text("pass\\n")
        video = bytes([0, 0, 0, 24]) + b"ftypisom" + b"x" * 20
        with patch("runtime.maxine.server.subprocess.run") as execute:
            def finish(args, **kwargs):
                assert "--save-csv" in args
                assert "--output" not in args
                pathlib.Path(args[args.index("--save-csv") + 1]).write_bytes(b"index,probability" + bytes([10]) + b"0,0.450000" + bytes([10]))
            execute.side_effect = finish
            mime, data = run_model(
                "nvidia-synthetic-video-detector", video,
                root=root, api_key="test-provider-key",
            )
            assert mime == "text/csv"
            assert b"0,0.45" in data


def test_active_speaker_uses_video_and_diarization():
    with tempfile.TemporaryDirectory() as directory:
        root = pathlib.Path(directory)
        spec = SPECS["nvidia-active-speaker-detection"]
        script = root / spec["script"]
        script.parent.mkdir(parents=True, exist_ok=True)
        script.write_text("pass\\n")
        video = bytes([0, 0, 0, 24]) + b"ftypisom" + b"x" * 20
        with patch("runtime.maxine.server.subprocess.run") as execute:
            def finish(args, **kwargs):
                assert "--diarization-input" in args
                assert "--skip-audio" in args
                pathlib.Path(args[args.index("--output") + 1]).write_bytes(video)
            execute.side_effect = finish
            mime, data = run_model(
                "nvidia-active-speaker-detection", video, root=root,
                api_key="test-provider-key", diarization=b'{"segments":[]}',
            )
            assert mime == "video/mp4"
            assert data == video
