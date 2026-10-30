"use client";

import Link from "next/link";
import { useEffect, useState } from "react";
import { ArrowRight, ArrowUpRight, BookOpen, ChartColumnIncreasing, KeyRound, ShieldCheck } from "lucide-react";
import { api, Developer, KeyResponse, Usage } from "@/lib/api";

export default function Dashboard({ user }: { user: Developer }) {
  const [usage, setUsage] = useState<Usage | null>(null);
  const [keys, setKeys] = useState<KeyResponse | null>(null);
  const [error, setError] = useState("");
  const [loading, setLoading] = useState(true);
  useEffect(() => {
    Promise.all([api<Usage>("developer/usage"), api<KeyResponse>("developer/keys")])
      .then(([u, k]) => { setUsage(u); setKeys(k); }).catch((e) => setError(e.message)).finally(() => setLoading(false));
  }, []);
  const fraction = usage?.limit ? Math.min(100, Math.round((usage.used / usage.limit) * 100)) : 0;
  const max = Math.max(1, ...(usage?.daily.map((x) => x.used) || [1]));
  return <main className="page">
    <div className="page-heading"><div><div className="eyebrow">DEVELOPER OVERVIEW</div><h1>Welcome back, {user.name.split(" ")[0]}</h1><p>Manage your integrations and review the requests made with your account.</p></div><Link className="button primary" href="/keys">Manage API keys <ArrowRight size={17}/></Link></div>
    {error && <p className="alert error" role="alert">{error}</p>}
    {loading && <p className="subtle small-text" role="status">Loading current account usage…</p>}
    <div className="metrics">
      <article className="metric"><div className="metric-top"><span>Completed requests this month</span><ChartColumnIncreasing size={19}/></div><strong>{usage?.used.toLocaleString() ?? "—"}</strong><small>Application request credits</small></article>
      <article className="metric"><div className="metric-top"><span>Remaining allocation</span><ShieldCheck size={19}/></div><strong>{usage ? (usage.remaining === null ? "Not set" : usage.remaining.toLocaleString()) : "—"}</strong><small>{usage?.month || "Current month"}</small></article>
      <article className="metric"><div className="metric-top"><span>Developer API keys</span><KeyRound size={19}/></div><strong>{keys?.keys.length ?? "—"}</strong><small>Maximum 10 keys per account</small></article>
    </div>
    <div className="dashboard-grid">
      <section className="panel">
        <div className="panel-title"><div><h2>Request activity</h2><p>Recent 30 days</p></div><span className="label-chip">{usage?.month || "Monthly usage"}</span></div>
        <div className="bar-chart" role="img" aria-label="Daily completed API requests">
          {(usage?.daily.length ? usage.daily : [{ day: "No activity", used: 0 }]).map((item) =>
            <div className="bar-track" key={item.day} title={item.day + ": " + item.used + " requests"}><span style={{ height: Math.max(4, item.used / max * 100) + "%" }} /></div>
          )}
        </div>
        <p className="subtle small-text">Only successful completed API requests are counted. Requests before tracking was enabled are not reconstructed.</p>
      </section>
      <section className="panel">
        <div className="panel-title"><div><h2>Monthly allocation</h2><p>Account-level allowance</p></div></div>
        <div className="allocation"><div className="donut" style={{ background: usage?.limit ? "conic-gradient(#5bdfbd " + fraction + "%, #23334b 0)" : "#23334b" }}><div><strong>{usage?.limit == null ? "—" : fraction + "%"}</strong><small>used</small></div></div><div className="legend"><span><i className="legend-dot"/> Completed: {usage?.used ?? "—"}</span><span><i className="legend-dot dim"/> Remaining: {usage?.remaining ?? "Not configured"}</span><small>Admin-defined request credits, not provider billing tokens.</small></div></div>
      </section>
    </div>
    <div className="dashboard-grid">
      <section className="panel"><div className="panel-title"><h2>Coding agent activity</h2><Link href="/models">Explore agents <ArrowUpRight size={15}/></Link></div>{usage?.by_model.length ? usage.by_model.slice(0, 6).map((m) => <div className="usage-row" key={m.model + m.kind}><span><strong>OpenMindAI</strong><small>{m.kind}</small></span><b>{m.used} requests</b></div>) : <p className="empty">No completed requests recorded yet.</p>}</section>
      <section className="panel"><div className="panel-title"><h2>Quick start</h2><BookOpen size={19}/></div><div className="quick-links"><Link href="/keys"><KeyRound size={18}/><span><strong>Create an API key</strong><small>Generate a scoped credential for your app</small></span><ArrowRight size={16}/></Link><Link href="/docs"><BookOpen size={18}/><span><strong>Integrate an AI assistant</strong><small>Copy working JavaScript, Python or cURL examples</small></span><ArrowRight size={16}/></Link></div></section>
    </div>
  </main>;
}
