"use client";

import { useState } from "react";
import { api } from "@/lib/api";

export default function ShareSnapshot({
  endpoint,
  description = "Anyone with the link can read a snapshot of this conversation for 30 days.",
}: {
  endpoint: string;
  description?: string;
}) {
  const [url, setUrl] = useState("");
  const [copied, setCopied] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function createLink() {
    setBusy(true);
    setError("");
    try {
      const { token } = await api<{ token: string }>(endpoint, "POST");
      setUrl(new URL(`/share/${token}`, window.location.origin).href);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <>
      <p className="muted">{description} Review private information before sharing.</p>
      {url ? (
        <>
          <label className="share-url">
            Public link
            <input value={url} readOnly onFocus={(e) => e.target.select()} />
          </label>
          <button
            type="button"
            className="secondary-button"
            onClick={async () => {
              try {
                await navigator.clipboard.writeText(url);
                setCopied(true);
              } catch {
                setError("Select the public link and copy it manually.");
              }
            }}
          >
            {copied ? "Copied" : "Copy link"}
          </button>
        </>
      ) : (
        <button
          type="button"
          className="secondary-button"
          disabled={busy}
          onClick={() => void createLink()}
        >
          {busy ? "Creating link…" : "Create public link"}
        </button>
      )}
      {error && <p className="feedback error" role="alert">{error}</p>}
    </>
  );
}
