"use client";
import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import { documentFile } from "@/lib/preview-file";
const DocumentPreview = dynamic(() => import("./document-preview"));

export default function LibraryMediaThumbnail({id, mime, title}: {id:number;mime:string|null;title:string}) {
  const element = useRef<HTMLDivElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [error, setError] = useState(false);
  const image = ["image/png", "image/jpeg", "image/webp"].includes(mime || "");
  const document = ["application/pdf", "application/vnd.openxmlformats-officedocument.wordprocessingml.document"].includes(mime || "");
  useEffect(() => {
    if (!document || !element.current) return;
    const controller = new AbortController();
    setFile(null); setError(false);
    const observer = new IntersectionObserver(entries => {
      if (!entries.some(entry => entry.isIntersecting)) return;
      observer.disconnect();
      void (async () => {
        try {
          const response = await fetch(`/api/backend/media/${id}/output`, {signal:controller.signal,cache:"no-store"});
          if (!response.ok || Number(response.headers.get("content-length")) > 8_000_000) throw new Error("Preview unavailable");
          const blob = await response.blob();
          if (blob.size > 8_000_000) throw new Error("Preview too large");
          const previewFile = documentFile(blob, mime!);
          if (!previewFile) throw new Error("Unsupported document");
          if (!controller.signal.aborted) setFile(previewFile);
        } catch {if (!controller.signal.aborted) setError(true);}
      })();
    }, {rootMargin:"80px"});
    observer.observe(element.current);
    return () => {observer.disconnect(); controller.abort();};
  }, [id, mime, document]);
  if (!image && !document) return null;
  return <div className="library-media-thumbnail" ref={element}>
    {image && !error && <img src={`/api/backend/media/${id}/output`} alt={title || "Generated image"} loading="lazy" onError={() => setError(true)} />}
    {document && file && <DocumentPreview file={file} compact />}
    {document && !file && !error && <span role="status">Loading document preview…</span>}
    {error && <span>Preview unavailable. Use Preview to retry.</span>}
  </div>;
}
