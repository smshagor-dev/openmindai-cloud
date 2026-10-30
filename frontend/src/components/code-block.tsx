"use client";
import { useState } from "react";
import Dialog from "./dialog";
import CodePreview from "./code-preview";

export default function CodeBlock({
  content,
  language = "code",
}: {
  content: string;
  language?: string;
}) {
  const [open, setOpen] = useState(false);
  const [preview, setPreview] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState("");
  const runnable = ["html", "svg"].includes(language.toLowerCase()) ||
    (language.toLowerCase() === "xml" && /<svg\b/i.test(content));
  async function copy() {
    try {
      await navigator.clipboard.writeText(content);
      setCopied(true);
    } catch {
      setError(
        "Copy is unavailable in this browser. Open the code to select it.",
      );
    }
  }
  const toolbar = (
    <div className="code-toolbar">
      <span>{language}</span>
      <div>
        <button type="button" onClick={() => void copy()}>
          {copied ? "Copied" : "Copy"}
        </button>
        <button
          type="button"
          onClick={() => {
            setOpen(true);
            setPreview(runnable);
          }}
        >
          Open{runnable ? " preview" : " code"}
        </button>
      </div>
    </div>
  );
  return (
    <section className="response-code">
      {toolbar}
      <CodePreview content={content} language={language} />
      {error && <small role="status">{error}</small>}
      {open && (
        <Dialog
          title={language + " preview"}
          onClose={() => setOpen(false)}
          wide
        >
          <div className="preview-actions">
            <button
              type="button"
              onClick={() => setPreview(false)}
              aria-pressed={!preview}
            >
              Code
            </button>
            {runnable && (
              <button
                type="button"
                onClick={() => setPreview(true)}
                aria-pressed={preview}
              >
                Preview
              </button>
            )}
            <button type="button" onClick={() => void copy()}>
              {copied ? "Copied" : "Copy code"}
            </button>
          </div>
          {preview ? (
            <iframe
              className="code-live-preview"
              title="Code preview"
              sandbox="allow-scripts"
              referrerPolicy="no-referrer"
              srcDoc={
                "<!doctype html><meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'\">" +
                content
              }
            />
          ) : (
            <CodePreview content={content} language={language} />
          )}
        </Dialog>
      )}
    </section>
  );
}
