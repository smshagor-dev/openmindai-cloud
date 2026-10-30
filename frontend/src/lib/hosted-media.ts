// Server-only hosted media bridge. Browser never receives third-party credentials.
import { readProviderJson } from "./provider-response.ts";
export type HostedKind = "transcription" | "speech" | "video" | "document_create";
type Work = { kind: HostedKind; prompt: string; input?: string; format?: "pdf" | "docx"; language?: string };
export type ProviderConfig = { apiKey: string; bridgeKey: string; chatModel?: string };
const ASR = "https://1598d209-5e27-4d3c-8079-4751568b1081.invocation.api.nvcf.nvidia.com/v1/audio/transcriptions";
const SPEECH = "https://877104f7-e885-42b9-8de8-f6e4c6303969.invocation.api.nvcf.nvidia.com/v1/audio/synthesize";
const VIDEO = "https://ai.api.nvidia.com/v1/cosmos/nvidia/cosmos3-nano";
const CHAT = "https://integrate.api.nvidia.com/v1/chat/completions";
// The hosted service documents these locale/voice pairs. Keep provider
// identifiers on the server, while the frontend only shows language names.
const SPEECH_VOICES: Record<string, string> = {
  "en-US": "Magpie-Multilingual.EN-US.Aria",
  "es-US": "Magpie-Multilingual.ES-US.Diego",
  "fr-FR": "Magpie-Multilingual.FR-FR.Louise",
};
export const hostedMediaEnabled = () =>
  !!process.env.NVIDIA_API_KEY && (process.env.FRONTEND_CHAT_BRIDGE_KEY?.length || 0) >= 32;
export const hostedDocumentEnabled = () =>
  (process.env.FRONTEND_CHAT_BRIDGE_KEY?.length || 0) >= 32;

class WorkError extends Error {
  status: number;
  constructor(message: string, status = 503) {
    super(message);
    this.status = status;
  }
}
function decodeInput(encoded: unknown): Buffer {
  if (typeof encoded !== "string" || encoded.length > 11000000 ||
      !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded))
    throw new WorkError("Invalid input file.", 422);
  const result = Buffer.from(encoded, "base64");
  if (!result.length || result.length > 8000000)
    throw new WorkError("File exceeds the 8 MB limit.", 422);
  return result;
}
function mimeAudio(raw: Buffer): "audio/wav" {
  // The frontend always normalizes microphones and uploaded audio to PCM WAV.
  // Reject unsupported input *before* reserving a task or consuming quota.
  if (raw.length < 44 || raw.toString("ascii", 0, 4) !== "RIFF" ||
      raw.toString("ascii", 8, 12) !== "WAVE" ||
      raw.toString("ascii", 12, 16) !== "fmt " ||
      raw.readUInt16LE(20) !== 1 ||
      raw.readUInt16LE(22) !== 1 ||
      raw.readUInt32LE(24) !== 16000 ||
      raw.readUInt16LE(34) !== 16)
    throw new WorkError("Please provide 16 kHz mono PCM WAV audio.", 422);
  return "audio/wav";
}
async function readBounded(response: Response, limit: number): Promise<Buffer> {
  const declared = Number(response.headers.get("content-length") || "0");
  if (!Number.isFinite(declared) || declared > limit)
    throw new WorkError("The media response exceeds the size limit.", 502);
  if (!response.body) throw new WorkError("The media service returned an empty response.", 502);
  const reader = response.body.getReader();
  const buffers: Buffer[] = [];
  let total = 0;
  try {
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      total += value.byteLength;
      if (total > limit) {
        await reader.cancel();
        throw new WorkError("The media response exceeds the size limit.", 502);
      }
      buffers.push(Buffer.from(value));
    }
    return Buffer.concat(buffers, total);
  } finally {
    reader.releaseLock();
  }
}
async function readJson(response: Response, limit: number): Promise<any> {
  try {
    return await readProviderJson(response, limit);
  } catch {
    throw new WorkError("The media service returned an invalid or oversized response.", 502);
  }
}
function validMedia(raw: Buffer, kind: HostedKind) {
  if (!raw.length || raw.length > 20000000) throw new WorkError("Invalid media output.", 502);
  if (kind === "speech" &&
      !(raw.toString("ascii", 0, 4) === "RIFF" && raw.toString("ascii", 8, 12) === "WAVE"))
    throw new WorkError("Invalid generated audio.", 502);
  if (kind === "video" && raw.toString("ascii", 4, 8) !== "ftyp")
    throw new WorkError("Invalid generated video.", 502);
}
export async function hostedMediaRequest(
  payload: string, token: string, backend: string, signal: AbortSignal,
  options: { fetcher?: typeof fetch; config?: ProviderConfig } = {},
): Promise<Response> {
  const config = options.config || {
    apiKey: process.env.NVIDIA_API_KEY || "",
    bridgeKey: process.env.FRONTEND_CHAT_BRIDGE_KEY || "",
    chatModel: process.env.NVIDIA_MODEL || "nvidia/nemotron-3.5-lightning-30b-a3b",
  };
  if (config.bridgeKey.length < 32)
    return Response.json({ message: "Media services are not configured." }, { status: 503 });
  let job: Work;
  try { job = JSON.parse(payload); }
  catch { return Response.json({ message: "Invalid request." }, { status: 400 }); }
  if (!["transcription", "speech", "document_create", "video"].includes(job?.kind) ||
      typeof job.prompt !== "string" || job.prompt.length > 4000 ||
      (job.kind !== "transcription" && !job.prompt.trim()))
    return Response.json({ message: "Invalid media request." }, { status: 422 });
  if (job.kind !== "document_create" && !config.apiKey)
    return Response.json({ message: "This media engine is not configured." }, { status: 503 });
  if (job.kind === "document_create" && !["pdf", "docx"].includes(job.format || ""))
    return Response.json({ message: "Choose PDF or DOCX.", status: 422 }, { status: 422 });
  if (job.kind === "speech" && job.prompt.length > 1000)
    return Response.json({ message: "Speech text must be within 1000 characters." }, { status: 422 });
  // Reject unknown voices before creating a task or consuming daily quota.
  const speechLanguage = job.language ?? "en-US";
  if (job.kind === "speech" && !Object.hasOwn(SPEECH_VOICES, speechLanguage))
    return Response.json({ message: "This speech language is not available." }, { status: 422 });
  let input: Buffer | null = null;
  try {
    if (job.kind === "transcription") {
      input = decodeInput(job.input);
      mimeAudio(input);
    }
  } catch(e) {
    return Response.json({ message: (e as Error).message }, { status: 422 });
  }
  const fetcher = options.fetcher || fetch;
  const base = backend.replace(/\/$/, "");
  const headers = {
    Authorization: "Bearer " + token,
    "X-Chat-Bridge-Key": config.bridgeKey,
    "Content-Type": "application/json",
    Accept: "application/json",
  };
  const bridge = (path: string, data: object, timeout = 30000) =>
    fetcher(base + "/" + path, {
      method: "POST", body: JSON.stringify(data), headers, cache: "no-store",
      redirect: "error", signal: AbortSignal.timeout(timeout),
    });
  const invoke = async (url: string, body: BodyInit, type?: string, timeout = 110000) => {
    const backendDocument = job.kind === "document_create" && (!options.config || !config.apiKey);
    const response = await fetcher(backendDocument ? base + "/chat/provider" : url, {
      method: "POST", body,
      headers: {
        Authorization: "Bearer " + (backendDocument ? token : config.apiKey),
        ...(backendDocument ? { "X-Chat-Bridge-Key": config.bridgeKey } : {}),
        Accept: job.kind === "transcription" || job.kind === "document_create" || job.kind === "video" ? "application/json" : "audio/wav",
        ...(type ? { "Content-Type": type } : {}),
      },
      cache: "no-store", redirect: "error",
      signal: AbortSignal.any([signal, AbortSignal.timeout(timeout)]),
    });
    if (!response.ok) throw new WorkError(
      response.status === 429 ? "The media service is busy. Retry later." :
      response.status === 401 || response.status === 403 ? "Media model access is not enabled for this account." :
      "The media service could not complete this request.", response.status === 429 ? 429 : 503,
    );
    return response;
  };
  const providerModel = job.kind === "transcription" ? "endpoint/audio-transcriptions"
    : job.kind === "speech" ? "endpoint/audio-synthesize"
    : job.kind === "video" ? "nvidia/cosmos3-nano"
    : (config.chatModel || "nvidia/nemotron-3.5-lightning-30b-a3b");
  let taskId: number | null = null;
  try {
    const begin = await bridge("media/hosted/task/begin", { kind: job.kind, prompt: job.prompt, provider_model: providerModel });
    if (!begin.ok) {
      const status = begin.status === 401 ? 401 : begin.status === 403 ? 403 :
        begin.status === 422 ? 422 : begin.status === 429 ? 429 : 503;
      throw new WorkError(
        status === 401 ? "Your session expired. Please sign in again." :
        status === 403 ? "You do not have access to this media task." :
        status === 422 ? "This media request was rejected." :
        status === 429 ? "Daily media request limit reached." :
        "Cannot start a media task.",
        status,
      );
    }
    const task = await begin.json();
    if (!Number.isSafeInteger(task?.id) || task.id < 1) throw new WorkError("Invalid media task.");
    taskId = task.id;
    // An abandoned browser request must not begin costly provider inference.
    // Keep the cleanup bridge independent of this signal so it can mark failure.
    if (signal.aborted) throw new WorkError("Media request was cancelled.", 499);
    let completed: Record<string, string>;
    if (job.kind === "transcription") {
      const form = new FormData();
      form.set("file", new Blob([new Uint8Array(input!)], { type: "audio/wav" }), "recording.wav");
      form.set("language", "en-US");
      const response = await invoke(ASR, form);
      const reply = await readJson(response, 1000000);
      const text = typeof reply?.text === "string" ? reply.text :
        Array.isArray(reply?.segments) ? reply.segments.map((x: { text?: string }) => x.text || "").join(" ") : "";
      if (!text.trim() || text.length > 100000) throw new WorkError("The recording could not be transcribed.", 422);
      completed = { text: text.trim() };
    } else if (job.kind === "speech") {
      const form = new FormData();
      form.set("text", job.prompt);
      form.set("language", speechLanguage);
      form.set("voice", SPEECH_VOICES[speechLanguage]);
      form.set("encoding", "LINEAR_PCM");
      form.set("sample_rate_hz", "44100");
      const response = await invoke(SPEECH, form);
      const raw = await readBounded(response, 20000000);
      validMedia(raw, "speech");
      completed = { data: raw.toString("base64"), mime: "audio/wav" };
    } else if (job.kind === "video") {
      const response = await invoke(VIDEO, JSON.stringify({
        model_mode: "text2video", prompt: job.prompt,
        resolution: "480_16_9", num_frames: 25,
        num_inference_steps: 35, fps: 24, seed: 42,
      }), "application/json", 180000);
      const result = await readJson(response, 27000000);
      const encoded = result?.b64_video;
      if (typeof encoded !== "string" || encoded.length > 27000000 ||
          !/^[A-Za-z0-9+/]+={0,2}$/.test(encoded))
        throw new WorkError("No playable video was returned.", 502);
      const raw = Buffer.from(encoded, "base64");
      validMedia(raw, "video");
      completed = { data: raw.toString("base64"), mime: "video/mp4" };
    } else {
      const response = await invoke(CHAT, JSON.stringify({
        model: config.chatModel || "nvidia/nemotron-3.5-lightning-30b-a3b",
        messages: [
          { role: "system", content: "Draft complete, polished document content. Return plain text only; no commentary about file conversion. Keep paragraphs and headings clear." },
          { role: "user", content: job.prompt },
        ], stream: false, max_tokens: 4096,
      }), "application/json");
      const reply = await readJson(response, 1000000);
      const text = reply?.choices?.[0]?.message?.content;
      if (typeof text !== "string" || !text.trim()) throw new WorkError("Document generation returned no content.", 502);
      const { renderDocx, renderPdf } = await import("./document-files.ts");
      const raw = job.format === "docx" ? renderDocx(text) : renderPdf(text);
      completed = { data: raw.toString("base64"), mime: job.format === "docx"
        ? "application/vnd.openxmlformats-officedocument.wordprocessingml.document" : "application/pdf" };
    }
    if (signal.aborted) throw new WorkError("Media request was cancelled.", 499);
    const saved = await bridge("media/" + taskId + "/hosted/task/complete", { ...completed, provider_model: providerModel }, 45000);
    if (!saved.ok) {
      // Only propagate known actionable validation messages, never raw provider bodies.
      const detail = await saved.json().catch(() => null);
      if (detail?.message === "Document verification is unavailable.")
        throw new WorkError("The backend PHP ZIP extension is required to save DOCX files. Enable it or choose PDF.", 503);
    }
    if (!saved.ok) throw new WorkError(
      saved.status === 404 || saved.status === 409
        ? "This media task was deleted or cancelled."
        : `Media created but the backend could not save it (HTTP ${saved.status}).`,
      saved.status === 404 || saved.status === 409 ? 409 : 503,
    );
    const result = await saved.json();
    if (result.status !== "complete" || result.id !== taskId) throw new WorkError("Media was not saved.");
    return Response.json(result, { status: 201, headers: { "Cache-Control": "no-store" } });
  } catch (error) {
    if (taskId) { try { await bridge("media/" + taskId + "/hosted/task/fail", {}, 12000); } catch {} }
    const failure = error instanceof WorkError ? error : new WorkError("Media generation could not be completed.");
    return Response.json({ message: failure.message, ...(taskId ? { task_id: taskId } : {}) }, {
      status: failure.status, headers: { "Cache-Control": "no-store" },
    });
  }
}
