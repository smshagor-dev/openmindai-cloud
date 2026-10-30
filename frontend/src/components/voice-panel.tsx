"use client";
import { useEffect, useRef, useState } from "react";
import ScreenPreview from "./screen-preview";
import { api } from "@/lib/api";
import { audioAsWavBase64 } from "@/lib/audio-file";
export default function VoicePanel({
  onText,
}: {
  onText: (text: string) => void;
}) {
  const [available, setAvailable] = useState(false);
  const [muted, setMuted] = useState(false);
  const [phase, setPhase] = useState("");
  const discard = useRef(false);
  const epoch = useRef(0);
  const [recording, setRecording] = useState(false);
  const [busy, setBusy] = useState(false);
  const [transcript, setTranscript] = useState("");
  const [error, setError] = useState("");
  const [level, setLevel] = useState(0);
  const stream = useRef<MediaStream | null>(null);
  const recorder = useRef<MediaRecorder | null>(null);
  const controller = useRef<AbortController | null>(null);
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
  const audio = useRef<AudioContext | null>(null);
  const frame = useRef<number>(0);
  const lastMeterUpdate = useRef(0);
  const alive = useRef(true);
  function cleanup() {
    stream.current?.getTracks().forEach((t) => t.stop());
    stream.current = null;
    if (timer.current) clearTimeout(timer.current);
    cancelAnimationFrame(frame.current);
    if (audio.current && audio.current.state !== "closed")
      void audio.current.close().catch(() => {});
    audio.current = null;
  }
  useEffect(() => {
    alive.current = true;
    const c = new AbortController();
    api<{ media: string[] }>("capabilities", "GET", undefined, c.signal)
      .then((caps) => setAvailable(caps.media.includes("transcription")))
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => {
      alive.current = false;
      ++epoch.current;
      c.abort();
      controller.current?.abort();
      if (recorder.current?.state === "recording") recorder.current.stop();
      cleanup();
    };
  }, []);
  async function transcribe(blob: Blob) {
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    setPhase("Transcribing…");
    try {
      const input = await audioAsWavBase64(blob);
      if (c.signal.aborted) return;
      const result = await api<{ id: number; status: string; result_text: string | null }>(
        "media/transcription",
        "POST",
        { kind: "transcription", prompt: "", input },
        c.signal,
      );
      if (c.signal.aborted) return;
      if (result.status !== "complete" || !result.result_text?.trim()) {
        throw new Error("The recording could not be transcribed. Please try again.");
      }
      setTranscript(result.result_text);
      setPhase("Transcription ready.");
    } catch (e) {
      if (!c.signal.aborted && alive.current) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted && alive.current) setBusy(false);
    }
  }
  async function start() {
    setBusy(true);
    setPhase("Requesting microphone…");
    const request = ++epoch.current;
    discard.current = false;
    setMuted(false);
    setError("");
    try {
      if (
        !navigator.mediaDevices?.getUserMedia ||
        typeof MediaRecorder === "undefined"
      )
        throw new Error(
          "Microphone recording needs HTTPS and a supported browser.",
        );
      const media = await navigator.mediaDevices.getUserMedia({ audio: true });
      if (!alive.current || request !== epoch.current) {
        media.getTracks().forEach((t) => t.stop());
        return;
      }
      stream.current = media;
      const format = [
        "audio/webm;codecs=opus",
        "audio/webm",
        "audio/ogg;codecs=opus",
      ].find((x) => MediaRecorder.isTypeSupported(x));
      if (!format)
        throw new Error(
          "Use a browser supporting WebM or OGG audio recording.",
        );
      const r = new MediaRecorder(media, { mimeType: format });
      recorder.current = r;
      const parts: BlobPart[] = [];
      r.ondataavailable = (e) => {
        if (e.data.size) parts.push(e.data);
      };
      r.onstop = () => {
        cleanup();
        if (alive.current) {
          setRecording(false);
          setLevel(0);
          if (!discard.current)
            void transcribe(new Blob(parts, { type: format }));
          else setBusy(false);
        }
      };
      audio.current = new AudioContext();
      const analyser = audio.current.createAnalyser();
      audio.current.createMediaStreamSource(media).connect(analyser);
      const samples = new Uint8Array(analyser.fftSize);
      function draw() {
        analyser.getByteTimeDomainData(samples);
        let sum = 0;
        for (const v of samples) sum += (v - 128) ** 2;
        if (
          alive.current &&
          performance.now() - lastMeterUpdate.current > 100
        ) {
          lastMeterUpdate.current = performance.now();
          setLevel(Math.min(100, Math.sqrt(sum / samples.length) * 4));
        }
        frame.current = requestAnimationFrame(draw);
      }
      draw();
      r.start();
      setRecording(true);
      setBusy(false);
      timer.current = setTimeout(() => {
        if (r.state === "recording") r.stop();
      }, 110000);
    } catch (e) {
      if (!alive.current || request !== epoch.current) return;
      cleanup();
      setBusy(false);
      setError((e as Error).message);
    }
  }
  return (
    <section className="voice-panel">
      <h3>Voice input</h3>
      <p className="muted">
        Record up to 110 seconds, then transcribe securely using the configured speech service.
        Review the transcript before sending. This is turn-based input; use the
        Live workspace tab for continuous conversation. Background listening
        stops when you close that tab. Screen preview below sends a frame only
        when you explicitly choose it.
      </p>
      {!available && (
        <p role="status">
          An administrator must configure the speech transcription service.
        </p>
      )}
      {error && <p role="alert">{error}</p>}
      <div
        className="voice-meter"
        role="meter"
        aria-label="Microphone level"
        aria-valuenow={Math.round(level)}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <span style={{ width: `${recording ? level : 0}%` }} />
      </div>
      <div className="button-row">
        {recording ? (
          <button
            className="danger-button"
            onClick={() => {
              if (recorder.current?.state === "recording")
                recorder.current.stop();
            }}
          >
            Stop & transcribe
          </button>
        ) : (
          <button
            className="primary-button"
            disabled={!available || busy}
            onClick={() => void start()}
          >
            Start recording
          </button>
        )}
        {recording && (
          <>
            <button
              aria-pressed={muted}
              onClick={() => {
                const next = !muted;
                stream.current?.getAudioTracks().forEach((t) => {
                  t.enabled = !next;
                });
                setMuted(next);
              }}
            >
              {muted ? "Unmute microphone" : "Mute microphone"}
            </button>
            <button
              onClick={() => {
                discard.current = true;
                if (recorder.current?.state === "recording")
                  recorder.current.stop();
              }}
            >
              Discard recording
            </button>
          </>
        )}
        {busy && (
          <>
            <span role="status">{phase}</span>
            <button
              onClick={() => {
                ++epoch.current;
                controller.current?.abort();
                setBusy(false);
              }}
            >
              Stop waiting
            </button>
          </>
        )}
      </div>
      <label>
        Transcript
        <textarea
          rows={5}
          value={transcript}
          onChange={(e) => setTranscript(e.target.value)}
          maxLength={16000}
        />
      </label>
      <button
        className="secondary-button"
        disabled={!transcript.trim()}
        onClick={() => onText(transcript)}
      >
        Use transcript in chat
      </button>
      <ScreenPreview onText={onText} />
      <p className="muted">
        Recordings create a saved media task. Inputs are deleted after
        processing; transcript tasks remain until deleted from Library.
      </p>
    </section>
  );
}
