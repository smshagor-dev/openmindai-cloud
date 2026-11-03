"use client";
import { useState } from "react";
import { Check, Copy, Maximize2, Monitor, Smartphone } from "lucide-react";
import Dialog from "./dialog";
import CodePreview from "./code-preview";

// Sandboxed document for HTML/SVG previews: scripts run in an opaque origin
// with no network, forms or parent access.
const previewDocument = (content: string) =>
  "<!doctype html><meta http-equiv=\"Content-Security-Policy\" content=\"default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data: blob:; font-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'\"><meta name=\"viewport\" content=\"width=device-width, initial-scale=1\">" +
  content;

const COLLAPSED_LINES = 24;

export default function CodeBlock({
  content,
  language = "code",
}: {
  content: string;
  language?: string;
}) {
  const [open, setOpen] = useState(false);
  const [view, setView] = useState<"code" | "preview">("code");
  const [device, setDevice] = useState<"desktop" | "mobile">("desktop");
  const [expanded, setExpanded] = useState(false);
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState("");
  const runnable = ["html", "svg"].includes(language.toLowerCase()) ||
    (language.toLowerCase() === "xml" && /<svg\b/i.test(content));
  const lines = content.split("\n").length;
  const long = lines > COLLAPSED_LINES;
  async function copy() {
    try {
      await navigator.clipboard.writeText(content);
      setCopied(true);
      setTimeout(() => setCopied(false), 1800);
    } catch {
      setError(
        "Copy is unavailable in this browser. Open the code to select it.",
      );
    }
  }
  const viewToggle = runnable && (
    <div className="code-view-toggle" role="group" aria-label="Code view">
      <button type="button" aria-pressed={view === "code"} onClick={() => setView("code")}>Code</button>
      <button type="button" aria-pressed={view === "preview"} onClick={() => setView("preview")}>Preview</button>
    </div>
  );
  const deviceToggle = view === "preview" && (
    <div className="code-view-toggle code-device-toggle" role="group" aria-label="Preview width">
      <button type="button" aria-pressed={device === "desktop"} aria-label="Desktop width" title="Desktop width" onClick={() => setDevice("desktop")}><Monitor size={14} /></button>
      <button type="button" aria-pressed={device === "mobile"} aria-label="Mobile width" title="Mobile width" onClick={() => setDevice("mobile")}><Smartphone size={14} /></button>
    </div>
  );
  const copyButton = (
    <button type="button" className="code-icon-button" onClick={() => void copy()} aria-label={copied ? "Copied" : "Copy code"} title="Copy code">
      {copied ? <Check size={14} /> : <Copy size={14} />}
      <span>{copied ? "Copied" : "Copy"}</span>
    </button>
  );
  const preview = (height: string) => (
    <div className={`code-live-frame ${device}`}>
      <iframe
        className="code-live-preview"
        title={`${language} preview`}
        sandbox="allow-scripts"
        referrerPolicy="no-referrer"
        style={{ height }}
        srcDoc={previewDocument(content)}
      />
    </div>
  );
  return (
    <section className={`response-code${view === "preview" ? " previewing" : ""}`}>
      <div className="code-toolbar">
        <span className="code-language">{language}</span>
        <div>
          {viewToggle}
          {deviceToggle}
          {copyButton}
          <button type="button" className="code-icon-button" onClick={() => setOpen(true)} aria-label="Expand" title="Expand">
            <Maximize2 size={14} />
          </button>
        </div>
      </div>
      {view === "preview" ? (
        preview("min(420px, 60vh)")
      ) : (
        <div className={`code-body${long && !expanded ? " collapsed" : ""}`}>
          <CodePreview content={content} language={language} />
          {long && (
            <button type="button" className="code-expand" onClick={() => setExpanded((value) => !value)}>
              {expanded ? "Show less" : `Show all ${lines} lines`}
            </button>
          )}
        </div>
      )}
      {error && <small role="status">{error}</small>}
      {open && (
        <Dialog
          title={language}
          onClose={() => setOpen(false)}
          wide
        >
          <div className="preview-actions">
            {viewToggle}
            {deviceToggle}
            {copyButton}
          </div>
          {view === "preview" ? preview("min(70vh, 760px)") : <CodePreview content={content} language={language} />}
        </Dialog>
      )}
    </section>
  );
}
