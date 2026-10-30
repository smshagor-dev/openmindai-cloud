"use client";

import { useEffect, useState } from "react";
import { ArrowRight, Cpu, Search } from "lucide-react";
import Link from "next/link";
import { api, Model } from "@/lib/api";

export default function Catalog() {
  const [models, setModels] = useState<Model[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [query, setQuery] = useState("");
  useEffect(() => { api<{data: Model[]}>("developer/catalog")
    .then((x) => setModels(x.data))
    .catch((e) => setError(e.message)).finally(() => setLoading(false)); }, []);
  const visible = models.filter((m) =>
    (m.name + " " + m.id + " " + (m.description || "")).toLowerCase().includes(query.toLowerCase()));
  return <main className="page">
    <div className="page-heading"><div><div className="eyebrow">HOSTED INFERENCE</div><h1>OpenMindAI coding agents</h1><p>Access OpenMindAI coding assistants for code generation, debugging, and development workflows.</p></div><span className="label-chip"><Cpu size={14}/> {models.length} coding agents</span></div>
    {error && <p className="alert error" role="alert">{error}</p>}
    <div className="catalog-controls"><label className="search"><Search size={18}/><input value={query} onChange={(e) => setQuery(e.target.value)} placeholder="Search models and capabilities…" aria-label="Search models"/></label></div>
    {loading ? <p className="empty" role="status">Loading model catalog…</p> : error ? <p className="empty">Model catalog is unavailable. Reload the page to try again.</p> : visible.length === 0 ? <p className="empty">No models match your search.</p> : <div className="model-grid">{visible.map((m) => <article className="panel model-card" key={m.id}><div className="model-top"><span className="model-icon"><Cpu size={19}/></span><span className={"model-status " + (m.available ? "active" : "idle")}>{m.available ? "Integration ready" : "Not configured"}</span></div><h3>OpenMindAI</h3><code>{m.id}</code><p>{m.description || "OpenMindAI coding assistance."}</p><div className="model-footer"><span>Coding agent · {m.operation}</span><span>{m.available ? "Ready to connect" : "Not configured"}</span></div></article>)}</div>}
    <div className="notice-line">Coding agents use OpenMindAI names. Availability reflects server configuration, not a guarantee of successful inference. <Link href="/docs">View integration guide <ArrowRight size={15}/></Link></div>
  </main>;
}
