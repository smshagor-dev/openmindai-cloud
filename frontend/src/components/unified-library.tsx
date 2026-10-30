"use client";
import { useEffect, useRef, useState } from "react";
import { api, type Page } from "@/lib/api";
import ArtifactPreviews, { type Artifact } from "./artifact-previews";
import DocumentPreview from "./document-preview";
import { documentFile } from "@/lib/preview-file";
import Dialog from "./dialog";
import LibraryMediaThumbnail from "./library-media-thumbnail";
type FileItem = { id: number; name: string; bytes: number };
type Media = {
  id: number;
  kind: string;
  prompt: string;
  status: string;
  output_mime: string | null;
  result_text: string | null;
};
export default function LibraryPanel({
  onText,
  onAttachment,
}: {
  onText: (text: string) => void;
  onAttachment: (file: FileItem) => void;
}) {
  const [files, setFiles] = useState<FileItem[]>([]);
  const [media, setMedia] = useState<Media[]>([]);
  const [query, setQuery] = useState("");
  const [filter, setFilter] = useState("all");
  const [page, setPage] = useState(1);
  const [last, setLast] = useState(1);
  const [error, setError] = useState("");
  const [preview, setPreview] = useState<{
    title: string;
    text?: string;
    url?: string;
    mime?: string;
    file?: File;
    artifacts?: Artifact[];
  } | null>(null);
  const [revision, setRevision] = useState(0);
  const [busy, setBusy] = useState(false);
  const action = useRef<AbortController | null>(null);
  useEffect(() => {
    const c = new AbortController();
    const timer = setTimeout(() => {
      Promise.all([
        api<Page<FileItem>>(
          `attachments?page=${page}&q=${encodeURIComponent(query)}`,
          "GET",
          undefined,
          c.signal,
        ),
        api<Page<Media>>(
          `media?page=${page}&q=${encodeURIComponent(query)}${!["all", "text"].includes(filter) ? `&kind=${filter}` : ""}`,
          "GET",
          undefined,
          c.signal,
        ),
      ])
        .then(([f, m]) => {
          setFiles(f.data);
          setMedia(m.data);
          setLast(
            Math.max(
              filter === "text"
                ? f.last_page
                : filter === "all"
                  ? Math.max(f.last_page, m.last_page)
                  : m.last_page,
              1,
            ),
          );
        })
        .catch((e) => {
          if (!c.signal.aborted) setError(e.message);
        });
    }, 250);
    return () => {
      c.abort();
      clearTimeout(timer);
    };
  }, [page, query, filter, revision]);
  useEffect(() => () => action.current?.abort(), []);
  useEffect(
    () => () => {
      if (preview?.url) URL.revokeObjectURL(preview.url);
    },
    [preview],
  );
  async function run(fn: (signal: AbortSignal) => Promise<void>) {
    action.current?.abort();
    const c = new AbortController();
    action.current = c;
    setBusy(true);
    setError("");
    try {
      await fn(c.signal);
    } catch (e) {
      if (!c.signal.aborted) setError((e as Error).message);
    } finally {
      if (!c.signal.aborted) setBusy(false);
    }
  }
  return (
    <>
      <h3>Library</h3>
      <div className="library-filters">
        <input
          aria-label="Search library"
          placeholder="Search files and media"
          value={query}
          onChange={(e) => {
            setQuery(e.target.value);
            setPage(1);
          }}
          maxLength={100}
        />
        <select
          aria-label="Library type"
          value={filter}
          onChange={(e) => {
            setFilter(e.target.value);
            setPage(1);
          }}
        >
          {[
            "all",
            "text",
            "image",
            "vision",
            "document",
            "document_create",
            "transcription",
            "speech",
            "video",
            "code",
          ].map((x) => (
            <option key={x} value={x}>{x === "document_create" ? "Generated documents" : x}</option>
          ))}
        </select>
      </div>
      {error && <p role="alert">{error}</p>}
      <div className="library-grid">
        {["all", "text"].includes(filter) &&
          files.map((f) => (
            <article className="manage-card" key={`f${f.id}`}>
              <strong>{f.name}</strong>
              <p>{f.bytes.toLocaleString()} bytes · Text file</p>
              <div className="button-row">
                <button
                  disabled={busy}
                  onClick={() =>
                    void run(async (signal) => {
                      const data = await api<{ content: string }>(
                        `attachments/${f.id}`,
                        "GET",
                        undefined,
                        signal,
                      );
                      setPreview({ title: f.name, text: data.content });
                    })
                  }
                >
                  Preview
                </button>
                <button disabled={busy} onClick={() => onAttachment(f)}>
                  Attach to chat
                </button>
                <button
                  disabled={busy}
                  onClick={() => {
                    if (confirm("Delete this library file?"))
                      void run(async (signal) => {
                        await api(
                          `attachments/${f.id}`,
                          "DELETE",
                          undefined,
                          signal,
                        );
                        setRevision((v) => v + 1);
                      });
                  }}
                >
                  Delete
                </button>
              </div>
            </article>
          ))}
        {filter !== "text" &&
          media.map((m) => (
            <article className="manage-card" key={`m${m.id}`}>
              {m.status === "complete" && <LibraryMediaThumbnail id={m.id} mime={m.output_mime} title={m.prompt} />}
              <strong>{m.prompt.slice(0, 100) || `${m.kind} #${m.id}`}</strong>
              <p>
                {m.kind} · {m.status}
              </p>
              <div className="button-row">
                <button
                  disabled={busy || m.status !== "complete"}
                  onClick={() =>
                    void run(async (signal) => {
                      if (m.output_mime === "application/zip") {
                        const detail = await api<{ artifacts?: Artifact[] }>(
                          `media/${m.id}`,
                          "GET",
                          undefined,
                          signal,
                        );
                        if (!signal.aborted)
                          setPreview({
                            title: m.prompt || m.kind,
                            text: m.result_text || "",
                            artifacts: detail.artifacts || [],
                          });
                      } else if (m.output_mime) {
                        const r = await fetch(
                          `/api/backend/media/${m.id}/output`,
                          { signal, cache: "no-store" },
                        );
                        if (!r.ok) throw new Error("Result unavailable.");
                        const blob = await r.blob();
                        if (!signal.aborted)
                          setPreview({
                            title: m.prompt || m.kind,
                            url: URL.createObjectURL(blob),
                            mime: m.output_mime,
                            file: documentFile(blob, m.output_mime),
                          });
                      } else
                        setPreview({
                          title: m.prompt || m.kind,
                          text: m.result_text || "",
                        });
                    })
                  }
                >
                  Preview
                </button>
                {m.result_text && (
                  <button onClick={() => onText(m.result_text!)}>
                    Use text in chat
                  </button>
                )}
                {m.output_mime && (
                  <a href={`/api/backend/media/${m.id}/output`} download>
                    Download
                  </a>
                )}
                <button
                  disabled={busy}
                  onClick={() => {
                    if (
                      confirm("Delete this media task and its private files?")
                    )
                      void run(async (signal) => {
                        await api(`media/${m.id}`, "DELETE", undefined, signal);
                        setRevision((v) => v + 1);
                      });
                  }}
                >
                  Delete
                </button>
              </div>
            </article>
          ))}
      </div>
      {!files.length && !media.length && (
        <p className="muted">No matching files or media.</p>
      )}
      <div className="button-row">
        <button disabled={page <= 1} onClick={() => setPage((v) => v - 1)}>
          Previous
        </button>
        <span>
          {page}/{last}
        </span>
        <button disabled={page >= last} onClick={() => setPage((v) => v + 1)}>
          Next
        </button>
      </div>
      {preview && (
        <Dialog
          title={preview.title.slice(0, 100)}
          onClose={() => setPreview(null)}
          wide
        >
          {!!preview.artifacts?.length && (
            <ArtifactPreviews items={preview.artifacts} />
          )}
          {preview.text !== undefined && (
            <pre className="file-text-preview">{preview.text}</pre>
          )}
          {preview.url && ["image/png", "image/jpeg", "image/webp"].includes(preview.mime || "") && (
            <img
              className="library-image-full"
              src={preview.url}
              alt={preview.title}
            />
          )}{" "}
          {preview.file && <DocumentPreview file={preview.file} />}
          {preview.url && preview.mime === "audio/wav" && (
            <audio controls src={preview.url} />
          )}{" "}
          {preview.url && preview.mime === "video/mp4" && (
            <video className="media-preview" controls src={preview.url} />
          )}
        </Dialog>
      )}
    </>
  );
}
