"use client";
import { useState } from "react";
import { Download } from "lucide-react";
import { api, type Row } from "@/lib/research";
const formats = ["docx", "pdf", "tex", "bib", "md"];
export function ResearchNotifications() {
  const [message, setMessage] = useState("");
  const [busy, setBusy] = useState(false);
  const [enabled, setEnabled] = useState(false);
  async function toggle() {
    setBusy(true);
    setMessage("");
    try {
      if (
        !("serviceWorker" in navigator) ||
        !("PushManager" in window) ||
        !("Notification" in window)
      )
        throw new Error(
          "This browser does not support web push. On iPhone or iPad, install OpenMindAI to your Home Screen first.",
        );
      const registration = await Promise.race([
        navigator.serviceWorker.ready,
        new Promise<never>((_, reject) =>
          setTimeout(
            () =>
              reject(
                new Error(
                  "App notifications are not ready. Reload and try again.",
                ),
              ),
            10000,
          ),
        ),
      ]);
      let subscription = await registration.pushManager.getSubscription();
      if (enabled) {
        if (subscription) {
          await api("push/subscriptions", "DELETE", {
            endpoint: subscription.endpoint,
          });
          await subscription.unsubscribe();
        }
        setEnabled(false);
        setMessage("Research notifications turned off on this device.");
        return;
      }
      const config = await api("push/key");
      if (!config.configured || !config.public_key)
        throw new Error("Research notifications are not available yet.");
      const permission = await Notification.requestPermission();
      if (permission !== "granted")
        throw new Error(
          "Notifications are blocked. You can enable them in your browser settings.",
        );
      const raw = atob(config.public_key.replace(/-/g, "+").replace(/_/g, "/"));
      const key = Uint8Array.from(raw, (c) => c.charCodeAt(0));
      subscription ||= await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: key,
      });
      await api("push/subscriptions", "POST", subscription.toJSON());
      setEnabled(true);
      setMessage(
        "You’ll be notified when your research files are ready, even after closing this tab.",
      );
    } catch (error) {
      setMessage(
        error instanceof Error
          ? error.message
          : "Could not change notifications.",
      );
    } finally {
      setBusy(false);
    }
  }
  return (
    <div className="rs-download-notifications">
      <button type="button" disabled={busy} onClick={() => void toggle()}>
        {busy
          ? "Updating notifications…"
          : enabled
            ? "Turn off research notifications"
            : "Notify me when files are ready"}
      </button>
      {message && <p role="status">{message}</p>}
    </div>
  );
}
export default function ResearchDownloads({
  data,
  editable = false,
  onChange,
}: {
  data: Row;
  editable?: boolean;
  onChange?: () => void;
}) {
  const [busy, setBusy] = useState(false),
    [error, setError] = useState("");
  const delivery = data.deliveries?.[0] as Row | undefined;
  const files: Row[] = delivery
    ? (data.exports || []).filter(
        (file: Row) => file.delivery_id === delivery.id,
      )
    : [];
  async function generate(retry = false) {
    setBusy(true);
    setError("");
    try {
      await api(
        `projects/${data.project.id}/deliveries${retry && delivery ? `/${delivery.id}/retry` : ""}`,
        "POST",
      );
      onChange?.();
    } catch (e) {
      setError(e instanceof Error ? e.message : "File generation failed.");
    } finally {
      setBusy(false);
    }
  }
  return (
    <section
      className="rs-card rs-research-downloads"
      aria-label="Research downloads"
    >
      <h3>Research downloads</h3>
      <p>
        DOCX, PDF, LaTeX, BibTeX and Markdown files are generated automatically
        when research completes.
      </p>
      {delivery && (
        <>
          <p>
            {delivery.draft
              ? "Draft · human review required"
              : "Approved manuscript"}
            {delivery.content_source === "proposal"
              ? " · generated proposal"
              : ""}
          </p>
          <div className="rs-download-links">
            {formats.map((format) => {
              const file = files.find((e) => e.format === format);
              const name =
                format === "tex"
                  ? "LaTeX ZIP"
                  : format === "bib"
                    ? "BibTeX"
                    : format.toUpperCase();
              return file?.status === "completed" ? (
                <a
                  key={format}
                  href={`/api/backend/research/projects/${data.project.id}/exports/${file.id}/download`}
                >
                  <Download size={14} aria-hidden="true" />
                  Download {name}
                </a>
              ) : (
                <span key={format}>
                  {name}: {file?.status || delivery.status}
                </span>
              );
            })}
          </div>
          {["partial", "failed"].includes(delivery.status) && editable && (
            <button disabled={busy} onClick={() => void generate(true)}>
              Retry failed files
            </button>
          )}
        </>
      )}
      {!delivery && data.run?.status === "completed" && editable && (
        <button disabled={busy} onClick={() => void generate()}>
          Generate all research files
        </button>
      )}
      {(data.exports || []).some((file: Row) => !file.delivery_id) && (
        <div className="rs-export-files">
          <h3>Your exports</h3>
          {(data.exports || [])
            .filter((file: Row) => !file.delivery_id)
            .slice(0, 5)
            .map((file: Row) => {
              const name = String(file.format).toUpperCase() + (file.draft ? " · draft" : "");
              return file.status === "completed" ? (
                <a
                  key={file.id}
                  href={`/api/backend/research/projects/${data.project.id}/exports/${file.id}/download`}
                >
                  <Download size={14} aria-hidden="true" />
                  Download {name}
                </a>
              ) : (
                <span key={file.id} className="rs-export-pending">
                  {name}: {file.status}
                </span>
              );
            })}
        </div>
      )}
      {error && <p role="alert">{error}</p>}
      <ResearchNotifications />
    </section>
  );
}
