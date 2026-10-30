"use client";
import { useEffect, useRef, useState } from "react";
import { api, type Page } from "@/lib/api";
import { audioAsWavBase64 } from "@/lib/audio-file";
import ArtifactPreviews, { type Artifact } from "./artifact-previews";
import DocumentPreview from "./document-preview";
import { documentFile } from "@/lib/preview-file";
import Dialog from "./dialog";
import CreationProgress from "./creation-progress";
import ResponseMarkdown from "./response-markdown";

type Kind =
  | "image"
  | "vision"
  | "transcription"
  | "speech"
  | "video"
  | "document"
  | "document_create"
  | "code";
type Task = {
  id: number;
  kind: Kind;
  prompt: string;
  status: string;
  progress_percent?: number | null;
  progress_stage?: string | null;
  result_text: string | null;
  output_mime: string | null;
  error: string | null;
  artifacts?: Artifact[];
};
const labels: Record<Kind, string> = {
  code: "Python run",
  document: "Read PDF or DOCX",
  document_create: "Create PDF or Word document",
  image: "Create image",
  vision: "Ask about an image",
  transcription: "Transcribe audio",
  speech: "Create speech",
  video: "Create short video",
};

export default function MediaDialog({
  onClose,
  onUse,
  initialKind = "image",
}: {
  initialKind?: Kind;
  onClose: () => void;
  onUse: (text: string) => void;
}) {
  const [kinds, setKinds] = useState<Kind[]>([]);
  const [kind, setKind] = useState<Kind>(initialKind);
  const [format, setFormat] = useState<"pdf" | "docx">("docx");
  const [speechLanguage, setSpeechLanguage] = useState("en-US");
  const [imageMode, setImageMode] = useState<"generate" | "edit" | "reference" | "inpaint" | "outpaint">("generate");
  const [imageRatio, setImageRatio] = useState("1:1");
  const [imageCount, setImageCount] = useState(1);
  const [imageSeed, setImageSeed] = useState(0);
  const [imageFile, setImageFile] = useState<File | null>(null);
  const [maskFile, setMaskFile] = useState<File | null>(null);
  const [imageConfig, setImageConfig] = useState<{image_editing:boolean;image_masking:boolean;ratios:string[];native_resolution:string} | null>(null);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [page, setPage] = useState(1);
  const [pages, setPages] = useState(1);
  const [selected, setSelected] = useState<number | null>(null);
  const [prompt, setPrompt] = useState("");
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState("");
  const [busy, setBusy] = useState(false);
  const [artifacts, setArtifacts] = useState<Artifact[]>([]);
  const [previewFile, setPreviewFile] = useState<File | undefined>();
  const [preview, setPreview] = useState("");
  const [revision, setRevision] = useState(0);
  const controller = useRef<AbortController | null>(null);
  const task = tasks.find((t) => t.id === selected);
  const needsFile =
    kind === "vision" || kind === "transcription" || kind === "document";

  useEffect(() => () => controller.current?.abort(), []);
  useEffect(() => {
    const c = new AbortController();
    api<{image_editing:boolean;image_masking:boolean;ratios:string[];native_resolution:string}>("media/image-capabilities", "GET", undefined, c.signal)
      .then(setImageConfig).catch(() => {});
    return () => c.abort();
  }, []);
  useEffect(() => {
    const c = new AbortController();
    api<{ media: Kind[] }>("capabilities", "GET", undefined, c.signal)
      .then((caps) => {
        setKinds((caps.media || []).filter((k) => String(k) !== "code"));
        const first = caps.media?.find((k) => String(k) !== "code");
        if (caps.media?.includes(initialKind)) setKind(initialKind);
        else if (first) setKind(first);
      })
      .catch((e) => {
        if (!c.signal.aborted) setError(e.message);
      });
    return () => c.abort();
  }, []);
  useEffect(() => {
    const c = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    async function load() {
      try {
        const result = await api<Page<Task>>(
          `media?page=${page}`,
          "GET",
          undefined,
          c.signal,
        );
        if (c.signal.aborted) return;
        setTasks(result.data);
        setPages(result.last_page);
        if (result.data.some((t) => ["queued", "running"].includes(t.status)))
          timer = setTimeout(load, 4000);
      } catch (e) {
        if (!c.signal.aborted) setError((e as Error).message);
      }
    }
    void load();
    return () => {
      c.abort();
      clearTimeout(timer);
    };
  }, [page, revision]);
  useEffect(() => {
    const c = new AbortController();
    setPreviewFile(undefined);
    let url = "";
    setPreview("");
    setArtifacts([]);
    if (task?.status === "complete" && task.kind === "code")
      api<Task>(`media/${task.id}`, "GET", undefined, c.signal)
        .then((t) => setArtifacts(t.artifacts || []))
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    if (task?.status === "complete" && task.output_mime) {
      fetch(`/api/backend/media/${task.id}/output`, {
        signal: c.signal,
        cache: "no-store",
      })
        .then(async (response) => {
          if (!response.ok)
            throw new Error("Unable to load this private result.");
          const blob = await response.blob();
          if (!c.signal.aborted) {
            setPreviewFile(documentFile(blob, task.output_mime || blob.type));
            url = URL.createObjectURL(blob);
            setPreview(url);
          }
        })
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    }
    return () => {
      c.abort();
      if (url) URL.revokeObjectURL(url);
    };
  }, [task?.id, task?.status, task?.output_mime]);

  async function encodeImage(file: File, mask = false) {
    if (file.size > 4_000_000 || file.size === 0) throw new Error("Image must be 4 MB or smaller.");
    if (!["image/png", ...(mask ? [] : ["image/jpeg"])].includes(file.type))
      throw new Error(mask ? "Upload a PNG mask." : "Upload a PNG or JPEG reference image.");
    const value = await new Promise<string>((resolve, reject) => {
      const reader = new FileReader();
      reader.onload = () => resolve(String(reader.result));
      reader.onerror = () => reject(new Error("Unable to read image."));
      reader.readAsDataURL(file);
    });
    const expected = "data:" + file.type + ";base64,";
    if (!value.startsWith(expected)) throw new Error("Unsupported image input.");
    return value.slice(expected.length);
  }

  async function create() {
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    setError("");
    try {
      let input: string | undefined;
      if (needsFile) {
        if (!file || file.size > 8_000_000)
          throw new Error(
            "Choose a file up to 8 MB. Audio must be at most 2 minutes.",
          );
        input = kind === "transcription" ? await audioAsWavBase64(file) : await new Promise<string>((resolve, reject) => {
          const reader = new FileReader();
          reader.onload = () => resolve(String(reader.result).split(",")[1]);
          reader.onerror = () => reject(new Error("Unable to read this file."));
          reader.readAsDataURL(file);
        });
      }
      if (c.signal.aborted) return;
      const imageData = kind === "image" && imageMode !== "generate" && imageFile
        ? await encodeImage(imageFile) : undefined;
      const maskData = kind === "image" && ["inpaint", "outpaint"].includes(imageMode) && maskFile
        ? await encodeImage(maskFile, true) : undefined;
      if (kind === "image" && imageMode !== "generate" && !imageData)
        throw new Error("Choose a reference image to edit.");
      if (kind === "image" && ["inpaint", "outpaint"].includes(imageMode) && !maskData)
        throw new Error("Choose a PNG mask.");
      const result = await api<Task & {ids?:number[]}>(
        ["image", "transcription", "speech", "video", "document_create"].includes(kind)
          ? "media/" + kind
          : "media",
        "POST",
        { kind, prompt, input,
          ...(kind === "document_create" ? { format } : {}),
          ...(kind === "speech" ? { language: speechLanguage } : {}),
          ...(kind === "image" ? {mode:imageMode, aspect_ratio:imageRatio, count:imageCount, seed:imageSeed,
            ...(imageData ? {image:imageData} : {}), ...(maskData ? {mask:maskData} : {})} : {}),
        },
        c.signal,
      );
      if (!c.signal.aborted) {
        setSelected(result.id);
        setPage(1);
        setRevision((r) => r + 1);
        setFile(null);
      }
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  async function remove(id: number) {
    const c = new AbortController();
    controller.current = c;
    setBusy(true);
    setError("");
    try {
      await api(`media/${id}`, "DELETE", undefined, c.signal);
      if (!c.signal.aborted) {
        setSelected(null);
        setRevision((r) => r + 1);
      }
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  return (
    <Dialog title="Media studio" onClose={onClose} wide>
      <p className="muted">
        Generated files are saved privately in your library. Image prompts are
        processed by an external AI service when image creation is enabled.
        Uploaded inputs are removed after processing. Saved results remain
        until you delete them.
      </p>
      {error && (
        <p role="alert" className="form-error">
          {error}
        </p>
      )}
      {kinds.length ? (
        <form
          className="media-form"
          onSubmit={(e) => {
            e.preventDefault();
            void create();
          }}
        >
          <label>
            Tool
            <select
              value={kind}
              onChange={(e) => {
                setKind(e.target.value as Kind);
                setFile(null);
              }}
              disabled={busy}
            >
              {kinds.map((k) => (
                <option key={k} value={k}>
                  {labels[k]}
                </option>
              ))}
            </select>
          </label>
          {kind === "speech" && (
            <label>
              Speech language
              <select value={speechLanguage} disabled={busy}
                onChange={(e) => setSpeechLanguage(e.target.value)}>
                <option value="en-US">English (United States)</option>
                <option value="es-US">Spanish (United States)</option>
                <option value="fr-FR">French (France)</option>
              </select>
            </label>
          )}
          {kind === "document_create" && (
            <label>
              File format
              <select value={format} onChange={(e) => setFormat(e.target.value as "pdf" | "docx")}>
                <option value="docx">Word (.docx)</option>
                <option value="pdf">PDF (.pdf)</option>
              </select>
            </label>
          )}
          {kind === "image" && (
            <>
              <label>Image operation
                <select value={imageMode} disabled={busy} onChange={(e) => setImageMode(e.target.value as typeof imageMode)}>
                  <option value="generate">Create from prompt</option>
                  {imageConfig?.image_editing && <option value="edit">Edit an uploaded image</option>}
                  {imageConfig?.image_editing && <option value="reference">Generate from a reference image</option>}
                  {imageConfig?.image_masking && <option value="inpaint">Inpaint selected area</option>}
                  {imageConfig?.image_masking && <option value="outpaint">Outpaint using mask</option>}
                </select>
              </label>
              {imageMode === "generate" && <label>Aspect ratio
                <select value={imageRatio} disabled={busy} onChange={(e) => setImageRatio(e.target.value)}>
                  {(imageConfig?.ratios || ["1:1","16:9","9:16","5:4","4:5","3:2","2:3"]).map(ratio => <option key={ratio} value={ratio}>{ratio}</option>)}
                </select>
              </label>}
              <label>Variations (separate provider requests)
                <select value={imageCount} disabled={busy} onChange={(e) => setImageCount(Number(e.target.value))}>
                  {[1,2,3,4].map(count => <option key={count} value={count}>{count}</option>)}
                </select>
              </label>
              <label>Seed (0 = random)
                <input type="number" min="0" max="4294967295" value={imageSeed} disabled={busy}
                  onChange={(e) => setImageSeed(Math.max(0,Number(e.target.value) || 0))} />
              </label>
              {imageMode !== "generate" && <label>Reference image (PNG/JPEG, up to 4 MB)
                <input type="file" accept="image/png,image/jpeg" disabled={busy} onChange={e => setImageFile(e.target.files?.[0] || null)} />
              </label>}
              {["inpaint","outpaint"].includes(imageMode) && <label>Mask (PNG, up to 4 MB)
                <input type="file" accept="image/png" disabled={busy} onChange={e => setMaskFile(e.target.files?.[0] || null)} />
              </label>}
              <p className="muted">Native pixel size is model-defined; only the aspect ratio is selectable. Each variation uses one credit upon successful generation.</p>
            </>
          )}
          <label>
            {kind === "speech" ? "Text to speak" : "Prompt"}
            <textarea
              value={prompt}
              onChange={(e) => setPrompt(e.target.value)}
              maxLength={kind === "speech" ? 1000 : 4000}
              required={kind !== "transcription" && kind !== "document"}
              rows={3}
              disabled={busy}
            />
          </label>
          {needsFile && (
            <label>
              {kind === "document"
                ? "Document (PDF or DOCX, up to 8 MB)"
                : kind === "vision"
                  ? "Image (PNG, JPEG, WebP; up to 16 MP)"
                  : "Audio (WAV, MP3, FLAC, OGG, WebM; up to 2 minutes)"}
              <input
                key={kind}
                type="file"
                accept={
                  kind === "document"
                    ? ".pdf,.docx"
                    : kind === "vision"
                      ? "image/png,image/jpeg,image/webp"
                      : "audio/*,.webm"
                }
                onChange={(e) => setFile(e.target.files?.[0] || null)}
                disabled={busy}
              />
              {file?.name}
            </label>
          )}
          <button className="primary-button" disabled={busy}>
            {busy ? "Submitting…" : "Start task"}
          </button>
        </form>
      ) : (
        <p role="status">
          No creation service is enabled yet. An administrator must configure
          the server-side API connection or a compatible private runtime.
        </p>
      )}
      <div className="media-history">
        <label>
          Saved tasks
          <select
            value={selected ?? ""}
            onChange={(e) => setSelected(Number(e.target.value) || null)}
          >
            <option value="">Select a task</option>
            {tasks.map((t) => (
              <option key={t.id} value={t.id}>
                {labels[t.kind]} · {t.status}{t.kind === "image" && t.status !== "failed" && typeof t.progress_percent === "number" ? ` · ${t.progress_percent}%` : ""} ·{" "}
                {t.prompt.slice(0, 45) || `#${t.id}`}
              </option>
            ))}
          </select>
        </label>
        <div className="media-pagination">
          <button
            disabled={page <= 1}
            onClick={() => {
              setPage((p) => p - 1);
              setSelected(null);
            }}
          >
            Previous
          </button>
          <span>
            {page} / {pages}
          </span>
          <button
            disabled={page >= pages}
            onClick={() => {
              setPage((p) => p + 1);
              setSelected(null);
            }}
          >
            Next
          </button>
        </div>
        {task && (
          <section aria-label="Task result" className="media-result">
            <p role="status">
              {labels[task.kind]} · {task.status}{task.kind === "image" && task.status === "complete" ? " · 100%" : ""}
            </p>
            <p>{task.prompt}</p>
            {task.kind === "image" && ["queued", "running"].includes(task.status) && (
              <CreationProgress kind="image" progress={task.progress_percent} stage={task.progress_stage} />
            )}
            {task.error && <p role="alert">{task.error}</p>}
            {!!artifacts.length && <ArtifactPreviews items={artifacts} />}
            {task.result_text && (
              <>
                <ResponseMarkdown content={task.result_text} />
                <button onClick={() => onUse(task.result_text!)}>
                  Use result in chat
                </button>
              </>
            )}
            {preview && ["image/png", "image/jpeg"].includes(task.output_mime || "") && (
              <img className="media-preview" src={preview} alt={task.prompt} />
            )}
            {preview && task.output_mime === "audio/wav" && (
              <audio controls src={preview} />
            )}
            {previewFile && <DocumentPreview file={previewFile} />}
            {preview && task.output_mime === "video/mp4" && (
              <video className="media-preview" controls src={preview} />
            )}
            {preview && (
              <a
                href={preview}
                download={`openmindai-${task.id}.${task.output_mime === "application/pdf" ? "pdf" : task.output_mime?.includes("wordprocessingml") ? "docx" : task.output_mime === "image/jpeg" ? "jpg" : task.kind === "image" ? "png" : task.kind === "speech" ? "wav" : task.kind === "code" ? "zip" : "mp4"}`}
              >
                Download result
              </a>
            )}
            <button
              disabled={busy}
              onClick={() => {
                if (
                  confirm(
                    "Delete this task and its private files? Running inference may finish, but its result will be discarded.",
                  )
                )
                  void remove(task.id);
              }}
            >
              Delete task & files
            </button>
          </section>
        )}
      </div>
    </Dialog>
  );
}
