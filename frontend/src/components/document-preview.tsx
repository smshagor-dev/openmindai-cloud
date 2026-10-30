"use client";
import { useEffect, useState } from "react";
import ResponseMarkdown from "./response-markdown";
export default function DocumentPreview({ file, onAttach, compact = false }: { file: File; onAttach?: (file: File) => void; compact?: boolean }) {
  const [text, setText] = useState("");
  const [url, setUrl] = useState("");
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  const extension = file.name.split(".").pop()?.toLowerCase();
  useEffect(() => {
    let cancelled = false;
    const controller = new AbortController();
    const objectUrl = URL.createObjectURL(file);
    setUrl(objectUrl);
    setLoading(true);
    setText("");
    setError("");
    async function read() {
      try {
        if (file.size > 8_000_000)
          throw new Error("Preview supports files up to 8 MB.");
        let content = "";
        if (extension === "pdf") {
          const { extractPdfText } = await import("@/lib/pdf-text");
          content = await extractPdfText(new Uint8Array(await file.arrayBuffer()));
        } else if (extension === "docx") {
          const { unzipSync, strFromU8 } = await import("fflate");
          const files = unzipSync(new Uint8Array(await file.arrayBuffer()), {
            filter: (entry) =>
              entry.name === "word/document.xml" &&
              entry.originalSize <= 10_000_000,
          });
          const xml = files["word/document.xml"];
          if (!xml)
            throw new Error(
              "The Word document is invalid or too large to preview.",
            );
          const document = new DOMParser().parseFromString(
            strFromU8(xml),
            "application/xml",
          );
          if (document.getElementsByTagName("parsererror").length)
            throw new Error("The Word document is invalid.");
          content = Array.from(document.getElementsByTagNameNS("*", "p"))
            .map((p) =>
              Array.from(p.getElementsByTagNameNS("*", "t"))
                .map((t) => t.textContent || "")
                .join(""),
            )
            .join("\n\n");
        } else if (["md", "markdown", "txt", "csv"].includes(extension || "")) {
          content = await file.text();
        } else if (extension === "doc") {
          if (file.size > 3_000_000)
            throw new Error("Legacy Word preview supports files up to 3 MB.");
          const data = await new Promise<string>((resolve, reject) => {
            const reader = new FileReader();
            reader.onload = () => resolve(String(reader.result).split(",")[1]);
            reader.onerror = () =>
              reject(new Error("Unable to read document."));
            reader.readAsDataURL(file);
          });
          const response = await fetch("/api/document-preview", {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify({ data }),
            signal: controller.signal,
          });
          const result = await response.json();
          if (!response.ok)
            throw new Error(result.message || "Unable to preview document.");
          content = result.text;
        }
        if (!cancelled) setText(content.slice(0, compact ? 1200 : 250000));
      } catch (e) {
        if (!cancelled) setError((e as Error).message);
      } finally {
        if (!cancelled) setLoading(false);
      }
    }
    void read();
    return () => {
      cancelled = true;
      controller.abort();
      URL.revokeObjectURL(objectUrl);
    };
  }, [file, extension, compact]);
  return (
    <section className={"document-preview" + (compact ? " document-preview-compact" : "")} aria-label={file.name + " preview"}>
      {loading && <p role="status">Preparing preview…</p>}
      {error && <p role="alert">{error}</p>}
      {!compact && extension === "pdf" && url && (
        <iframe title={file.name} className="pdf-preview" src={url} />
      )}
      {["md", "markdown"].includes(extension || "") ? (
        <ResponseMarkdown content={text} />
      ) : (
        text && <pre className="file-text-preview">{text}</pre>
      )}
      {!loading && !error && text.trim() && onAttach && (
        <>
          <button className="primary-button"
            disabled={text.length > 50000 || new TextEncoder().encode(text).length > 100000}
            onClick={() => onAttach(new File([text], file.name.slice(0, 150) + ".txt", { type: "text/plain" }))}>
            Attach extracted text to message
          </button>
          {(text.length > 50000 || new TextEncoder().encode(text).length > 100000) &&
            <p role="alert">The extracted text exceeds the chat attachment limit. Split the document; no text was attached or silently omitted.</p>}
        </>
      )}
      {!compact && url && (
        <a href={url} download={file.name}>
          Download {file.name}
        </a>
      )}
    </section>
  );
}
