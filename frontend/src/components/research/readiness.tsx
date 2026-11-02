"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { api, type Row } from "@/lib/research";

const workerLabels: Record<string, string> = {
  ready: "Available",
  setup_required: "Setup required",
  authorization_failed: "Worker credentials need attention",
  unreachable: "Cannot reach processing worker",
  unverified_runtime: "Worker runtime not verified",
  runtime_unavailable: "Processing runtime unavailable",
};

export default function ResearchReadiness() {
  const [status, setStatus] = useState<Row | null>(null);
  const [busy, setBusy] = useState(false);
  const [failed, setFailed] = useState(false);
  const generation = useRef(0);
  const check = useCallback(async () => {
    const sequence = ++generation.current;
    setBusy(true);
    setFailed(false);
    try {
      const result = await api("status");
      if (generation.current === sequence) setStatus(result);
    } catch {
      if (generation.current === sequence) setFailed(true);
    } finally {
      if (generation.current === sequence) setBusy(false);
    }
  }, []);
  useEffect(() => {
    void check();
    return () => {
      generation.current++;
    };
  }, [check]);

  return (
    <section
      className="rs-card"
      aria-label="Research infrastructure status"
      aria-busy={busy}
    >
      <h2>Infrastructure status</h2>
      {failed ? (
        <p role="alert">The status check could not be completed. Try again.</p>
      ) : status ? (
        <>
          <p>
            Reasoning:{" "}
            <strong>
              {status.reasoning.status === "ready"
                ? "Verified model configured"
                : "Model setup required"}
            </strong>
            {status.reasoning.uses_fallback && " · Verified fallback selected"}
          </p>
          <p>
            Processing worker:{" "}
            <strong>
              {workerLabels[status.worker.status] || "Unavailable"}
            </strong>
          </p>
          {status.worker.status !== "ready" && (
            <p>
              PDF analysis, experiments and exports need an available processing
              worker. Ask your workspace administrator to check its
              configuration.
            </p>
          )}
          <p>
            These checks use catalog verification and worker runtime
            availability. They do not execute a research workflow or verify its
            results.
          </p>
        </>
      ) : (
        <p role="status">Checking infrastructure…</p>
      )}
      <button type="button" disabled={busy} onClick={() => void check()}>
        {busy ? "Checking…" : "Refresh status"}
      </button>
    </section>
  );
}
