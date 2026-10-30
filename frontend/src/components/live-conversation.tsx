"use client";
import { useEffect, useRef, useState } from "react";
import { api, ApiError, streamChat, type Model, type Message } from "@/lib/api";
import { VoiceActivity, takeSentence } from "@/lib/live-voice";
import { audioAsWavBase64 } from "@/lib/audio-file";
import { playAudioUntilFinished } from "@/lib/audio-playback";
import ResponseMarkdown from "./response-markdown";
const encode = (blob: Blob) =>
  new Promise<string>((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(String(reader.result).split(",")[1]);
    reader.onerror = () => reject(new Error("Cannot read recording."));
    reader.readAsDataURL(blob);
  });
export default function LiveConversation({
  onOpen,
}: {
  onOpen: (id: number) => void;
}) {
  const [available, setAvailable] = useState(false),
    [hostedVoice, setHostedVoice] = useState(false),
    [generatedSpeech, setGeneratedSpeech] = useState(false),
    [speechAvailable, setSpeechAvailable] = useState(false),
    [vision, setVision] = useState(false),
    [models, setModels] = useState<Model[]>([]),
    [model, setModel] = useState(0),
    [consent, setConsent] = useState(false);
  const [active, setActive] = useState(false),
    [starting, setStarting] = useState(false),
    [muted, setMuted] = useState(false),
    [camera, setCamera] = useState(false),
    [phase, setPhase] = useState(""),
    [error, setError] = useState(""),
    [level, setLevel] = useState(0),
    [caption, setCaption] = useState("");
  const [messages, setMessages] = useState<Message[]>([]),
    [conversation, setConversation] = useState<number | null>(null);
  const mounted = useRef(true),
    epoch = useRef(0),
    session = useRef(""),
    activeRef = useRef(false),
    mic = useRef<MediaStream | null>(null),
    display = useRef<MediaStream | null>(null),
    video = useRef<HTMLVideoElement | null>(null),
    audio = useRef<AudioContext | null>(null),
    recorder = useRef<MediaRecorder | null>(null);
  const frameTimer = useRef<ReturnType<typeof setInterval> | null>(null),
    vadTimer = useRef<ReturnType<typeof setInterval> | null>(null),
    request = useRef<AbortController | null>(null),
    generation = useRef<AbortController | null>(null),
    activeMessage = useRef<{ cid: number; id: number } | null>(null),
    cid = useRef<number | null>(null),
    processing = useRef(false),
    queue = useRef<Blob[]>([]),
    cameraWork = useRef<Promise<void> | null>(null),
    cameraAbort = useRef<AbortController | null>(null),
    visual = useRef<{ text: string; at: number } | null>(null),
    speaking = useRef(false),
    playbackEpoch = useRef(0),
    generatedPlayer = useRef<HTMLAudioElement | null>(null),
    generatedAbort = useRef<AbortController | null>(null),
    generatedUrl = useRef<string | null>(null),
    mode = useRef(0),
    vad = useRef(new VoiceActivity());
  useEffect(() => {
    mounted.current = true;
    const c = new AbortController();
    Promise.all([
      api<{ live_voice: boolean; media: string[] }>(
        "capabilities",
        "GET",
        undefined,
        c.signal,
      ).then((v) => {
        setAvailable(v.live_voice || v.media.includes("transcription"));
        setHostedVoice(!v.live_voice && v.media.includes("transcription"));
        setSpeechAvailable(v.media.includes("speech"));
        setVision(v.live_voice && v.media.includes("vision"));
      }),
      api<Model[]>("models", "GET", undefined, c.signal).then((v) => {
        setModels(v);
        setModel(v[0]?.id || 0);
      }),
    ]).catch((e) => {
      if (!c.signal.aborted) setError(e.message);
    });
    return () => {
      mounted.current = false;
      c.abort();
      stop();
    };
  }, []);
  function stopVideo() {
    cameraAbort.current?.abort();
    if (frameTimer.current) clearInterval(frameTimer.current);
    frameTimer.current = null;
    display.current?.getTracks().forEach((t) => t.stop());
    display.current = null;
    if (video.current) video.current.srcObject = null;
    visual.current = null;
    if (mounted.current) {
      setCamera(false);
      setCaption("");
    }
  }
  function interrupt() {
    ++playbackEpoch.current;
    if (typeof speechSynthesis !== "undefined") speechSynthesis.cancel();
    generatedAbort.current?.abort();
    generatedAbort.current = null;
    generatedPlayer.current?.pause();
    generatedPlayer.current = null;
    if (generatedUrl.current) URL.revokeObjectURL(generatedUrl.current);
    generatedUrl.current = null;
    speaking.current = false;
    generation.current?.abort();
    const current = activeMessage.current;
    if (current)
      void api(
        `conversations/${current.cid}/messages/${current.id}/stop`,
        "POST",
        {},
      ).catch(() => {});
  }
  function stop() {
    ++epoch.current;
    activeRef.current = false;
    interrupt();
    request.current?.abort();
    stopVideo();
    if (vadTimer.current) clearInterval(vadTimer.current);
    vadTimer.current = null;
    if (recorder.current?.state === "recording") recorder.current.stop();
    recorder.current = null;
    mic.current?.getTracks().forEach((t) => t.stop());
    mic.current = null;
    if (audio.current && audio.current.state !== "closed")
      void audio.current.close().catch(() => {});
    audio.current = null;
    queue.current = [];
    processing.current = false;
    vad.current.reset();
    const key = session.current;
    session.current = "";
    if (key) void api("live/stop", "POST", { session: key }).catch(() => {});
    if (mounted.current) {
      setActive(false);
      setStarting(false);
      setMuted(false);
      setLevel(0);
      setPhase("Session ended");
    }
  }
  function speak(text: string, version: number) {
    if (!text.trim() || version !== epoch.current || !activeRef.current) return;
    const playback = playbackEpoch.current;
    const utterance = new SpeechSynthesisUtterance(text.replace(/[`*_#]/g, ""));
    utterance.lang = /[\u0980-\u09ff]/.test(text)
      ? "bn-BD"
      : navigator.language;
    utterance.onstart = () => {
      if (playback === playbackEpoch.current && version === epoch.current)
        speaking.current = true;
    };
    utterance.onend = () => {
      if (playback === playbackEpoch.current && !speechSynthesis.pending)
        speaking.current = false;
    };
    utterance.onerror = () => {
      if (playback === playbackEpoch.current) speaking.current = false;
    };
    speechSynthesis.speak(utterance);
  }
  async function playGeneratedSpeech(text: string, version: number) {
    if (!text.trim() || version !== epoch.current || !activeRef.current) return;
    const cleaned = text.replace(/[`*_#]/g, "").trim();
    // This voice is English-only; use the browser voice for other languages
    // instead of sending unsupported text to the hosted speech service.
    if (cleaned.length > 1000 || Array.from(cleaned).some((letter) => letter.codePointAt(0)! > 127)) {
      speak(cleaned, version);
      return;
    }
    const playback = playbackEpoch.current;
    const controller = new AbortController();
    generatedAbort.current = controller;
    let url: string | null = null;
    let player: HTMLAudioElement | null = null;
    let fallback = false;
    try {
      const result = await api<{ id: number }>(
        "media/speech", "POST", { kind: "speech", prompt: cleaned },
        controller.signal,
      );
      if (controller.signal.aborted || playback !== playbackEpoch.current ||
          version !== epoch.current) return;

      const response = await fetch("/api/backend/media/" + result.id + "/output", {
        cache: "no-store", signal: controller.signal,
      });
      if (!response.ok || response.headers.get("content-type")?.split(";")[0] !== "audio/wav")
        throw new Error("Generated audio could not be loaded.");
      const blob = await response.blob();
      if (!blob.size || blob.size > 20000000)
        throw new Error("Invalid generated audio.");
      if (controller.signal.aborted || playback !== playbackEpoch.current ||
          version !== epoch.current) return;

      url = URL.createObjectURL(blob);
      generatedUrl.current = url;
      player = new Audio(url);
      generatedPlayer.current = player;
      speaking.current = true;
      await playAudioUntilFinished(player, controller.signal);
    } catch {
      if (!controller.signal.aborted && playback === playbackEpoch.current &&
          version === epoch.current && activeRef.current) fallback = true;
    } finally {
      if (generatedAbort.current === controller) generatedAbort.current = null;
      if (generatedPlayer.current === player) generatedPlayer.current = null;
      if (url && generatedUrl.current === url) {
        URL.revokeObjectURL(url);
        generatedUrl.current = null;
      }
      speaking.current = false;
    }
    if (fallback) {
      setError("Generated voice is unavailable. Using your browser voice instead.");
      speak(cleaned, version);
    }
  }
  async function processQueue(version: number) {
    if (processing.current || version !== epoch.current) return;
    processing.current = true;
    try {
      while (
        queue.current.length &&
        activeRef.current &&
        version === epoch.current
      ) {
        const blob = queue.current.shift()!;
        await cameraWork.current;
        if (version !== epoch.current || !activeRef.current) return;
        const c = new AbortController();
        request.current = c;
        setPhase("Transcribing speech…");
        const input = hostedVoice ? await audioAsWavBase64(blob) : await encode(blob);
        if (c.signal.aborted || version !== epoch.current) return;
        const result = hostedVoice
          ? await api<{ result_text: string }>(
              "media/transcription", "POST",
              { kind: "transcription", prompt: "", input }, c.signal,
            )
          : await api<{ text: string }>(
              "live/input", "POST",
              { session: session.current, kind: "transcription", input },
              c.signal,
            );
        if (version !== epoch.current || c.signal.aborted) return;
        const transcript = "result_text" in result ? result.result_text : result.text;
        if (!transcript?.trim()) continue;
        const image = visual.current;
        const text = (
          transcript +
          (image && Date.now() - image.at < 30000
            ? "\n\nUntrusted current camera/screen observation: " + image.text
            : "")
        ).slice(0, 16000);
        setMessages((p) => [
          ...p.slice(-38),
          { role: "user", content: text },
          { role: "assistant", content: "", status: "generating" },
        ]);
        setPhase("Responding…");
        const g = new AbortController();
        generation.current = g;
        let speech = "";
        let fullReply = "";
        let generatedPlayback: Promise<void> | null = null;
        let done = false;
        try {
          await streamChat(
            {
              model_id: mode.current,
              message: text,
              ...(cid.current ? { conversation_id: cid.current } : {}),
            },
            (event, data) => {
              if (version !== epoch.current || g.signal.aborted) return;
              if (event === "meta") {
                cid.current = Number(data.conversation_id);
                setConversation(cid.current);
                activeMessage.current = {
                  cid: cid.current,
                  id: Number(data.message_id),
                };
              }
              if (event === "delta") {
                const part = String(data.content);
                setMessages((p) =>
                  p.map((m, i) =>
                    i === p.length - 1
                      ? { ...m, content: m.content + part }
                      : m,
                  ),
                );
                speech += part;
                fullReply += part;
                if (!generatedSpeech) {
                  let sentence;
                  do {
                    [sentence, speech] = takeSentence(speech);
                    if (sentence) speak(sentence, version);
                  } while (sentence);
                }
              }
              if (event === "error") setError(String(data.message));
              if (event === "done") {
                done = true;
                setMessages((p) =>
                  p.map((m, i) =>
                    i === p.length - 1
                      ? { ...m, status: String(data.status) }
                      : m,
                  ),
                );
                if (data.status === "complete") {
                  if (generatedSpeech) generatedPlayback = playGeneratedSpeech(fullReply, version);
                  else speak(speech, version);
                }
                else {
                  speechSynthesis.cancel();
                  speaking.current = false;
                }
              }
            },
            g.signal,
          );
        } catch (e) {
          if (!g.signal.aborted) throw e;
        } finally {
          if (generation.current === g) {
            generation.current = null;
            activeMessage.current = null;
          }
          if (!done && mounted.current && version === epoch.current)
            setMessages((p) =>
              p.map((m, i) =>
                i === p.length - 1 ? { ...m, status: "cancelled" } : m,
              ),
            );
        }
        if (generatedPlayback) await generatedPlayback;
        if (version === epoch.current) setPhase("Listening…");
      }
    } catch (e) {
      if (version === epoch.current && activeRef.current) {
        setError((e as Error).message);
        setPhase("Listening…");
        if (e instanceof ApiError && [401, 403, 410, 429].includes(e.status))
          stop();
      }
    } finally {
      if (version === epoch.current) processing.current = false;
      if (queue.current.length && version === epoch.current)
        void processQueue(version);
    }
  }
  async function start() {
    if (starting || activeRef.current) return;
    const version = ++epoch.current;
    setStarting(true);
    setError("");
    try {
      if (
        !navigator.mediaDevices?.getUserMedia ||
        !window.MediaRecorder ||
        !window.speechSynthesis
      )
        throw new Error(
          "Live voice requires HTTPS, microphone recording and browser speech playback.",
        );
      mode.current = model;
      const ctx = new AudioContext();
      audio.current = ctx;
      await ctx.resume();
      if (version !== epoch.current || !mounted.current) return;
      const media = await navigator.mediaDevices.getUserMedia({
        audio: {
          echoCancellation: true,
          noiseSuppression: true,
          autoGainControl: true,
        },
      });
      if (version !== epoch.current) {
        media.getTracks().forEach((t) => t.stop());
        return;
      }
      mic.current = media;
      const c = new AbortController();
      request.current = c;
      if (!hostedVoice) {
        const live = await api<{ session: string; vision: boolean }>(
          "live/start", "POST", { consent }, c.signal,
        );
        if (version !== epoch.current) {
          void api("live/stop", "POST", { session: live.session }).catch(() => {});
          return;
        }
        session.current = live.session;
        setVision(live.vision);
      }
      activeRef.current = true;
      setActive(true);
      setMuted(false);
      setMessages([]);
      cid.current = null;
      setConversation(null);
      setPhase("Listening…");
      const format = [
        "audio/webm;codecs=opus",
        "audio/webm",
        "audio/ogg;codecs=opus",
      ].find((f) => MediaRecorder.isTypeSupported(f));
      if (!format)
        throw new Error("This browser cannot record WebM/OGG audio.");
      const analyser = ctx.createAnalyser();
      ctx.createMediaStreamSource(media).connect(analyser);
      const samples = new Float32Array(analyser.fftSize);
      let parts: BlobPart[] = [];
      let heard = false;
      let began = performance.now();
      let closing = false;
      const record = () => {
        if (!activeRef.current || version !== epoch.current) return;
        parts = [];
        heard = false;
        closing = false;
        began = performance.now();
        const r = new MediaRecorder(media, {
          mimeType: format,
          audioBitsPerSecond: 48000,
        });
        recorder.current = r;
        r.ondataavailable = (e) => {
          if (e.data.size) parts.push(e.data);
        };
        r.onerror = () => {
          if (version === epoch.current) {
            setError("Microphone recorder failed.");
            stop();
          }
        };
        r.onstop = () => {
          if (!activeRef.current || version !== epoch.current) return;
          const blob = new Blob(parts, { type: format });
          if (heard && blob.size) {
            if (queue.current.length >= 2) {
              setError(
                "Speech arrived faster than the model can respond. Please pause briefly.",
              );
            } else queue.current.push(blob);
            void processQueue(version);
          }
          record();
        };
        r.start(250);
      };
      record();
      vadTimer.current = setInterval(() => {
        if (!activeRef.current) return;
        analyser.getFloatTimeDomainData(samples);
        let sum = 0;
        for (const v of samples) sum += v * v;
        const rms = Math.sqrt(sum / samples.length);
        setLevel(Math.min(100, rms * 1200));
        const change = vad.current.update(rms, performance.now());
        if (change === "start") {
          heard = true;
          if (generation.current || speaking.current || speechSynthesis.pending)
            interrupt();
        }
        if (vad.current.speaking) heard = true;
        if (
          (change === "end" || performance.now() - began > 20000) &&
          !closing &&
          recorder.current?.state === "recording"
        ) {
          closing = true;
          recorder.current.stop();
        }
      }, 80);
    } catch (e) {
      if (version === epoch.current) {
        setError((e as Error).message);
        stop();
      }
    } finally {
      if (mounted.current && version === epoch.current) setStarting(false);
    }
  }
  async function startVideo(screen: boolean) {
    const version = epoch.current;
    try {
      const media = screen
        ? await navigator.mediaDevices.getDisplayMedia({
            video: { width: 640, height: 360 },
            audio: false,
          })
        : await navigator.mediaDevices.getUserMedia({
            video: { width: 640, height: 360, facingMode: "user" },
            audio: false,
          });
      if (!activeRef.current || version !== epoch.current) {
        media.getTracks().forEach((t) => t.stop());
        return;
      }
      stopVideo();
      display.current = media;
      setCamera(true);
      if (video.current) {
        video.current.srcObject = media;
        await video.current.play();
      }
      if (
        !activeRef.current ||
        version !== epoch.current ||
        display.current !== media
      )
        return;
      media.getVideoTracks()[0].onended = () => {
        if (display.current === media) stopVideo();
      };
      const observe = async () => {
        if (
          cameraWork.current ||
          processing.current ||
          queue.current.length ||
          !video.current?.videoWidth ||
          !activeRef.current
        )
          return;
        const canvas = document.createElement("canvas");
        canvas.width = 640;
        canvas.height = Math.max(
          1,
          Math.round(
            (640 * video.current.videoHeight) / video.current.videoWidth,
          ),
        );
        if (canvas.height > 720) canvas.height = 720;
        canvas
          .getContext("2d")
          ?.drawImage(video.current, 0, 0, canvas.width, canvas.height);
        const c = new AbortController();
        cameraAbort.current = c;
        const job = (async () => {
          try {
            const v = await api<{ text: string }>(
              "live/input",
              "POST",
              {
                session: session.current,
                kind: "vision",
                input: canvas.toDataURL("image/jpeg", 0.65).split(",")[1],
              },
              c.signal,
            );
            if (version === epoch.current && !c.signal.aborted) {
              visual.current = { text: v.text, at: Date.now() };
              setCaption(v.text);
            }
          } catch (e) {
            if (!c.signal.aborted && version === epoch.current) {
              setError((e as Error).message);
              if (
                e instanceof ApiError &&
                [401, 403, 410, 429].includes(e.status)
              )
                stop();
            }
          }
        })();
        cameraWork.current = job;
        await job;
        if (cameraWork.current === job) cameraWork.current = null;
      };
      void observe();
      frameTimer.current = setInterval(() => void observe(), 5000);
    } catch (e) {
      if (version === epoch.current) {
        stopVideo();
        setError((e as Error).message);
      }
    }
  }
  return (
    <section className="collaboration-panel">
      <h3>Live voice and video</h3>
      <p className="muted">
        Continuous listening with automatic speech turns, streamed answers and
        browser speech playback. Speak to interrupt. Camera/screen observations
        update about every five seconds when the private engine is free. This
        uses a speech → text → model pipeline, not a native duplex audio/video
        model.
      </p>
      {!available && (
        <p role="status">
          Configure speech transcription to start. Optional vision requires a separate runtime.
        </p>
      )}
      {error && <p role="alert">{error}</p>}
      <label>
        <input
          type="checkbox"
          checked={consent}
          disabled={active || starting}
          onChange={(e) => setConsent(e.target.checked)}
        />
        Send detected speech to the configured transcription service and optional
        camera/screen frames to the private vision runtime. Audio may be
        processed by an external provider. Save transcripts and responses in
        my chat history. Answer playback uses browser speech synthesis.
      </label>
      {speechAvailable && (
        <label>
          <input type="checkbox" checked={generatedSpeech} disabled={active || starting}
            onChange={e => setGeneratedSpeech(e.target.checked)} />
          Use generated voice for English replies (may take longer and uses a media request).
        </label>
      )}
      <p className="muted">
        Use headphones for reliable interruption. Daily chat limits apply to
        each spoken question; the live session has a separate daily allowance.
        Raw recordings/frames are not saved by the app.
      </p>
      <div className="button-row">
        {!active ? (
          <button
            disabled={!available || !consent || !model || starting}
            onClick={() => void start()}
          >
            {starting ? "Connecting…" : "Start live conversation"}
          </button>
        ) : (
          <>
            <button onClick={stop}>End conversation</button>
            <button
              aria-pressed={muted}
              onClick={() => {
                const next = !muted;
                mic.current?.getAudioTracks().forEach((t) => {
                  t.enabled = !next;
                });
                setMuted(next);
                vad.current.reset();
              }}
            >
              {muted ? "Unmute microphone" : "Mute microphone"}
            </button>
            <button onClick={interrupt}>Interrupt response</button>
            {vision && !camera && (
              <>
                <button onClick={() => void startVideo(false)}>
                  Share camera continuously
                </button>
                <button onClick={() => void startVideo(true)}>
                  Share screen continuously
                </button>
              </>
            )}
            {camera && <button onClick={stopVideo}>Stop video sharing</button>}
          </>
        )}
        {starting && <button onClick={stop}>Cancel connection</button>}
        {conversation && (
          <button disabled={active} onClick={() => onOpen(conversation)}>
            Open saved chat
          </button>
        )}
      </div>
      <p role="status" aria-live="polite">
        {phase}
      </p>
      <div
        className="voice-meter"
        role="meter"
        aria-label="Live microphone level"
        aria-valuenow={Math.round(level)}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <span style={{ width: `${level}%` }} />
      </div>
      <video
        ref={video}
        autoPlay
        muted
        playsInline
        hidden={!camera}
        className="live-camera"
      />
      {caption && (
        <details>
          <summary>Latest visual observation</summary>
          <p>{caption}</p>
        </details>
      )}
      <div aria-live="off">
        {messages.map((m, i) => (
          <article key={i}>
            <strong>{m.role === "user" ? "You" : "Openmindai"}</strong>
            <ResponseMarkdown content={m.content} />
            {m.status === "cancelled" && <small>Interrupted</small>}
          </article>
        ))}
      </div>
    </section>
  );
}
