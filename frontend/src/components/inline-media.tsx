"use client";
import CreationProgress from "./creation-progress";
import DocumentPreview from "./document-preview";
import ResponseMarkdown from "./response-markdown";
import { useEffect, useState } from "react";
import ArtifactPreviews, { type Artifact } from "./artifact-previews";
import { api } from "@/lib/api";
export default function InlineMedia({
  id,
  onText,
}: {
  id: number;
  onText: (text: string) => void;
}) {
  const [task, setTask] = useState<{
    id: number;
    kind: string;
    status: string;
    progress_percent?: number | null;
    progress_stage?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    result_text: string | null;
    output_mime: string | null;
    error: string | null;
    artifacts?: Artifact[];
  } | null>(null);
  const [error, setError] = useState("");
  const [refreshCount, setRefreshCount] = useState(0);
  const [checkedAt, setCheckedAt] = useState(Date.now());
  const [documentFile, setDocumentFile] = useState<File | null>(null);
  const [url, setUrl] = useState("");
  useEffect(() => {
    const c = new AbortController();
    let timer: ReturnType<typeof setTimeout>;
    async function load() {
      try {
        const t = await api<NonNullable<typeof task>>(
          `media/${id}`,
          "GET",
          undefined,
          c.signal,
        );
        if (c.signal.aborted) return;
        setTask(t);
        setError("");
        setCheckedAt(Date.now());
        if (["queued", "running"].includes(t.status))
          timer = setTimeout(load, 3000);
      } catch (e) {
        if (!c.signal.aborted) {
          setError((e as Error).message);
          // Transient polling failures must not erase the server's durable task.
          // Continue checking without restarting generation.
          timer = setTimeout(load, 6000);
        }
      }
    }
    void load();
    return () => {
      c.abort();
      clearTimeout(timer);
    };
  }, [id, refreshCount]);
  useEffect(() => {
    const c = new AbortController();
    let u = "";
    if (task?.status === "complete" && task.output_mime)
      fetch(`/api/backend/media/${id}/output`, {
        signal: c.signal,
        cache: "no-store",
      })
        .then(async (r) => {
          if (!r.ok) throw new Error("Media unavailable.");
          const blob = await r.blob();
          if (!c.signal.aborted) {
            if (["application/pdf", "application/vnd.openxmlformats-officedocument.wordprocessingml.document", "text/markdown", "text/plain", "application/msword"].includes(blob.type)) {
              const ext = blob.type === "application/pdf" ? "pdf" : blob.type.includes("wordprocessingml") ? "docx" : blob.type === "application/msword" ? "doc" : "md";
              setDocumentFile(new File([blob], `document.${ext}`, {type: blob.type}));
            }
            u = URL.createObjectURL(blob);
            setUrl(u);
          }
        })
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    return () => {
      c.abort();
      if (u) URL.revokeObjectURL(u);
    };
  }, [id, task?.status, task?.output_mime]);
  const active = !task || ["queued", "running"].includes(task.status) ||
    (task.status === "complete" && Boolean(task.output_mime) && !url);
  const age = task?.created_at ? checkedAt - Date.parse(task.created_at) : 0;
  const queuedTooLong = task?.kind === "image" && task.status === "queued" && age > 120_000;
  const runningTooLong = task?.kind === "image" && task.status === "running" && age > 900_000;
  if (active && !task?.error) {
    return (
      <div className="media-poll-status">
        <CreationProgress kind={task?.kind || "media"} preparing={task?.status === "complete"}
          progress={task?.progress_percent} stage={task?.progress_stage}
          taskId={task?.id ?? id} status={task?.status ?? null}/>
        {queuedTooLong && (
          <p className="media-queue-warning" role="status">
            Image task #{id} was accepted, but it is still waiting for a media worker.
            Check the Laravel media queue and its scheduled worker. Do not submit a duplicate request.
          </p>
        )}
        {runningTooLong && (
          <p className="media-queue-warning" role="status">
            Image task #{id} is taking unusually long. The provider may be delayed.
            Check the backend worker logs before trying again.
          </p>
        )}
        {error && (
          <div className="media-queue-warning" role="alert">
            The latest status check failed: {error}. Your request might still be running.
            <button type="button" onClick={() => { setError(""); setRefreshCount((count) => count + 1); }}>
              Check status again
            </button>
          </div>
        )}
      </div>
    );
  }
  return (
    <article className="inline-media manage-card creation-result">
      <strong>
        {task?.kind || "Media"} · {task?.status || "Loading"}
      </strong>
      {(error || task?.error) && <p role="alert">{error || task?.error}</p>}
      {task?.kind === "image" && <p className="muted">Task #{id} · {task.status}</p>}
      {url && ["image/png", "image/jpeg", "image/webp"].includes(task?.output_mime || "") && (
        <a href={url} target="_blank" rel="noopener noreferrer">
          <img
            className="media-preview"
            src={url}
            alt="Generated image; open full size"
          />
        </a>
      )}
      {documentFile && <DocumentPreview file={documentFile} />}
      {url && task?.output_mime === "audio/wav" && <audio controls src={url} />}{" "}
      {url && task?.output_mime === "video/mp4" && (
        <video className="media-preview" controls src={url} />
      )}{" "}
      {!!task?.artifacts?.length && <ArtifactPreviews items={task.artifacts} />}
      {task?.result_text && (
        <>
          <ResponseMarkdown content={task.result_text} />
          <button onClick={() => onText(task.result_text!)}>
            Use text in message
          </button>
        </>
      )}
      {url && (
        <a href={`/api/backend/media/${id}/output`} download>
          Download result
        </a>
      )}
      <small className="muted">
        Saved in Library.
      </small>
    </article>
  );
}
