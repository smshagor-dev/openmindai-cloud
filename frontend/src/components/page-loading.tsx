"use client";

import { useEffect, useState } from "react";

export default function PageLoading({ initial = false }: { initial?: boolean }) {
  const [ready, setReady] = useState(false);
  useEffect(() => { setReady(true); }, []);
  if (initial && ready) return null;
  return (
    <div className="page-loading" role="status" aria-live="polite" aria-label="Loading OpenMindAI">
      <div className="page-loading-mark"><img src="/openmindai-logo.png" width="48" height="48" alt="" /></div>
      <strong>OpenMindAI</strong>
      <span>Preparing your workspace…</span>
      <div className="page-loading-track" aria-hidden="true"><i /></div>
    </div>
  );
}
