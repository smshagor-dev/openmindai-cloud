"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  ArrowLeft,
  BookOpen,
  CheckCircle2,
  ChevronRight,
  FlaskConical,
  Menu,
  Microscope,
  Moon,
  PanelRight,
  Plus,
  Sun,
  X,
} from "lucide-react";
import {
  api,
  Row,
  views,
  languages,
  parseReferences,
  pretty,
  safeUrl,
} from "@/lib/research";
import AcademicEditor from "./editor";
import type { JSONContent } from "@tiptap/core";
import "@fontsource/noto-serif-bengali";
import "@fontsource/noto-naskh-arabic";
import "@fontsource/noto-serif-devanagari";
import "@fontsource/noto-serif-sc";
const readable = (x: unknown) => (typeof x === "string" ? x : pretty(x));
const statusLabels: Record<string, Record<string, string>> = {
  bn: {
    running: "চলছে",
    pending: "অপেক্ষায়",
    queued: "সারিতে",
    completed: "সম্পন্ন",
    failed: "ব্যর্থ",
    paused: "বিরত",
    cancelled: "বাতিল",
  },
  ru: {
    running: "Выполняется",
    pending: "Ожидание",
    completed: "Завершено",
    failed: "Ошибка",
  },
  ar: {
    running: "قيد التنفيذ",
    pending: "انتظار",
    completed: "مكتمل",
    failed: "فشل",
  },
  hi: {
    running: "चल रहा है",
    pending: "प्रतीक्षा",
    completed: "पूर्ण",
    failed: "विफल",
  },
  zh: {
    running: "运行中",
    pending: "等待中",
    completed: "已完成",
    failed: "失败",
  },
};
function Data({ value }: { value: unknown }) {
  return (
    <pre className="rs-data" dir="auto">
      {readable(value)}
    </pre>
  );
}
function SourceLink({
  url,
  children,
}: {
  url: unknown;
  children: React.ReactNode;
}) {
  const safe = safeUrl(url);
  return safe ? (
    <a href={safe} target="_blank" rel="noopener noreferrer">
      {children}
    </a>
  ) : (
    <span>{children}</span>
  );
}
export default function ResearchStudio() {
  const [projects, setProjects] = useState<Row[]>([]);
  const [id, setId] = useState<number | null>(null);
  const [data, setData] = useState<Row | null>(null);
  const [view, setView] = useState<string>("Overview");
  const [events, setEvents] = useState<Row[]>([]);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [busy, setBusy] = useState(false);
  const [creating, setCreating] = useState(false);
  const [title, setTitle] = useState("");
  const [prompt, setPrompt] = useState("");
  const [progressLanguage, setProgressLanguage] = useState("auto");
  const [outputLanguage, setOutputLanguage] = useState("auto");
  const [drawer, setDrawer] = useState(false);
  const [assistant, setAssistant] = useState(true);
  const [light, setLight] = useState(false);
  const [question, setQuestion] = useState("");
  const [selection, setSelection] = useState("");
  const [editing, setEditing] = useState(
    "Improve clarity while preserving terminology, citations and equations.",
  );
  const [sourceId, setSourceId] = useState<number | null>(null);
  const [source, setSource] = useState<Row | null>(null);
  const [pdf, setPdf] = useState("");
  const [refs, setRefs] = useState("");
  const [compare, setCompare] = useState<number[]>([]);
  const [comment, setComment] = useState("");
  const [invite, setInvite] = useState("");
  const [role, setRole] = useState("reviewer");
  const [shareConsent, setShareConsent] = useState(false);
  const [uploadConsent, setUploadConsent] = useState(false);
  const [gate, setGate] = useState("manuscript");
  const [override, setOverride] = useState(false);
  const [reason, setReason] = useState("");
  const [expTitle, setExpTitle] = useState("Reproducible experiment");
  const [code, setCode] = useState(
    "from pathlib import Path\nprint('Run only after approving this exact code.')\nPath('/work/output/result.txt').write_text('Actual experiment output')\n",
  );
  const [datasetIds, setDatasetIds] = useState<number[]>([]);
  const [seed, setSeed] = useState(42);
  const [executionConsent, setExecutionConsent] = useState(false);
  const [format, setFormat] = useState("docx");
  const [draft, setDraft] = useState(true);
  const [revisions, setRevisions] = useState<Row[]>([]);
  const [invitations, setInvitations] = useState<Row[]>([]);
  const [proposal, setProposal] = useState<Row | null>(null);
  const [settings, setSettings] = useState<Row>({});
  const dirty = useRef(false);
  const currentId = useRef<number | null>(id);
  currentId.current = id;
  const cursor = useRef(0);
  const loading = useRef<number | null>(null);
  const initial = useRef(true);
  const dataRef = useRef(data);
  dataRef.current = data;
  const list = useCallback(async () => {
    const [p, i] = await Promise.all([api("projects"), api("invitations")]);
    setProjects(p.data || []);
    setInvitations(i);
  }, []);
  const refresh = useCallback(async (project: number) => {
    if (loading.current === project) return;
    loading.current = project;
    try {
      const value = await api(`projects/${project}`);
      if (currentId.current === project) {
        setData(value);
        if (!dataRef.current || dataRef.current.project.id !== project)
          setSettings(value.project.settings);
      }
    } finally {
      if (loading.current === project) loading.current = null;
    }
  }, []);
  useEffect(() => {
    void list().catch((e) => setError(e.message));
    const u = new URL(window.location.href);
    const project = Number(u.searchParams.get("project"));
    if (project > 0) setId(project);
    const selected = u.searchParams.get("view");
    if (selected && views.includes(selected as (typeof views)[number]))
      setView(selected);
    setLight(localStorage.getItem("research-theme") === "light");
    setAssistant(window.innerWidth > 1180);
  }, [list]);
  useEffect(() => {
    if (!id) return;
    setData(null);
    dataRef.current = null;
    setEvents([]);
    cursor.current = 0;
    initial.current = true;
    setSourceId(null);
    setSource(null);
    void refresh(id).catch((e) => setError(e.message));
  }, [id, refresh]);
  useEffect(() => {
    const u = new URL(window.location.href);
    if (id) u.searchParams.set("project", String(id));
    else u.searchParams.delete("project");
    u.searchParams.set("view", view);
    window.history.replaceState(null, "", u);
  }, [id, view]);
  useEffect(() => {
    if (!id) return;
    let disposed = false;
    const abort = new AbortController();
    const poll = async () => {
      try {
        const response = await fetch(
          `/api/backend/research/projects/${id}/events?after=${cursor.current}`,
          { cache: "no-store", signal: abort.signal },
        );
        if (!response.ok)
          throw new Error(`Activity connection failed (${response.status}).`);
        const text = await response.text();
        if (disposed) return;
        const incoming: Row[] = [];
        for (const line of text.split("\n")) {
          if (line.startsWith("data: ")) {
            const row = JSON.parse(line.slice(6));
            if (row.id > cursor.current) {
              cursor.current = row.id;
              incoming.push(row);
            }
          }
        }
        if (incoming.length) {
          setEvents((old) => [...old, ...incoming].slice(-150));
          await refresh(id);
        }
      } catch (e) {
        if (!disposed) setError((e as Error).message);
      }
      if (!disposed) timer = setTimeout(poll, 3000);
    };
    let timer: ReturnType<typeof setTimeout> | undefined;
    void poll();
    return () => {
      disposed = true;
      abort.abort();
      if (timer) clearTimeout(timer);
    };
  }, [id, refresh]);
  useEffect(() => {
    if (!id || !sourceId) {
      setSource(null);
      setPdf("");
      return;
    }
    let active = true;
    let url = "";
    setSource(null);
    setPdf("");
    api(`projects/${id}/sources/${sourceId}`)
      .then(async (s) => {
        if (!active) return;
        setSource(s);
        const artifact = dataRef.current?.artifacts?.find(
          (a: Row) =>
            a.kind === "pdf" && a.sha256 === s.metadata?.upload_sha256,
        );
        if (artifact) {
          const r = await fetch(
            `/api/backend/research/projects/${id}/artifacts/${artifact.id}/download`,
          );
          if (!r.ok) throw new Error("Cannot open private PDF.");
          url = URL.createObjectURL(await r.blob());
          if (active) setPdf(url);
          else URL.revokeObjectURL(url);
        }
      })
      .catch((e) => {
        if (active) setError(e.message);
      });
    return () => {
      active = false;
      if (url) URL.revokeObjectURL(url);
    };
  }, [
    id,
    sourceId,
    data?.sources?.find((s: Row) => s.id === sourceId)?.content_hash,
  ]);
  useEffect(() => {
    if (view === "History" && id)
      void api(`projects/${id}/revisions`)
        .then(setRevisions)
        .catch((e) => setError(e.message));
  }, [view, id, data?.document?.version]);
  const act = async (action: () => Promise<unknown>, success = "Saved") => {
    if (busy) return;
    setBusy(true);
    setError("");
    setNotice("");
    try {
      await action();
      setNotice(success);
      if (currentId.current) await refresh(currentId.current);
      await list();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };
  const choose = (project: number | null) => {
    if (dirty.current) {
      setError("Save or back up your manuscript before switching projects.");
      return;
    }
    setId(project);
    setCreating(false);
    setProposal(null);
    setView("Overview");
    setDrawer(false);
    setError("");
  };
  const create = async () => {
    if (dirty.current) {
      setError("Save your current manuscript first.");
      return;
    }
    await act(async () => {
      const p = await api("projects", "POST", {
        title,
        prompt,
        progress_language: progressLanguage,
        output_language: outputLanguage,
      });
      setId(p.id);
      setCreating(false);
      setTitle("");
      setPrompt("");
    }, "Project created");
  };
  const component = (agent: string, input: Row = {}) =>
    void act(
      () => api(`projects/${id}/components`, "POST", { agent, input }),
      "Component queued",
    );
  const upload = async (file: File, kind: string) => {
    if (!uploadConsent) {
      setError("Confirm permission to use this file.");
      return;
    }
    await act(async () => {
      if (file.size > 8_000_000) throw new Error("Upload limit is 8 MB.");
      const bytes = new Uint8Array(await file.arrayBuffer());
      let binary = "";
      for (let i = 0; i < bytes.length; i += 32768)
        binary += String.fromCharCode(...bytes.subarray(i, i + 32768));
      await api(`projects/${id}/uploads`, "POST", {
        kind,
        name: file.name,
        data: btoa(binary),
        authorized: true,
        ...(kind === "pdf" && sourceId ? { source_id: sourceId } : {}),
      });
    }, "Uploaded privately; PDFs are parsed by the queue worker.");
  };
  const saveProposal = () =>
    void act(async () => {
      if (dirty.current)
        throw new Error("Save or back up your local edits first.");
      if (!proposal) throw new Error("No proposal selected.");
      const d = await api(`projects/${id}/document`, "PUT", {
        content: proposal.content,
        version: proposal.base_version,
      });
      setData((old) => (old ? { ...old, document: d } : old));
      setProposal(null);
    }, "Proposal accepted as a new revision");
  const p = data?.project;
  const editable = p && ["owner", "editor"].includes(p.role);
  const reviewers = p && ["owner", "editor", "reviewer"].includes(p.role);
  const tasks: Row[] = data?.tasks || [];
  const sources: Row[] = data?.sources || [];
  const findings: Row[] = data?.findings || [];
  const artifacts: Row[] = data?.artifacts || [];
  const done = tasks.filter((t) => t.status === "completed").length;
  const latest = (kind: string) => findings.find((f) => f.kind === kind)?.data;
  const lang = String(
    data?.run?.plan?.language || p?.settings?.progress_language || "",
  );
  const label = (status: string) =>
    statusLabels[lang.toLowerCase().slice(0, 2)]?.[status] || status;
  const fileInput = (kind: string, accept: string) => (
    <label className="rs-file">
      Upload {kind}
      <input
        type="file"
        accept={accept}
        disabled={!editable || busy}
        onChange={(e) => {
          if (e.target.files?.[0]) void upload(e.target.files[0], kind);
          e.target.value = "";
        }}
      />
    </label>
  );
  return (
    <main
      className={`rs ${light ? "rs-light" : ""}`}
      data-research-version="2026-10-25-r3"
    >
      <header className="rs-header">
        <a href="/research-studio" className="rs-brand">
          <Microscope />
          <span>
            OpenMind <b>Research Studio</b>
          </span>
        </a>
        <div>
          <a href="/">
            <ArrowLeft size={16} />
            <span>Cloud</span>
          </a>
          <button
            aria-label="Toggle theme"
            onClick={() => {
              setLight(!light);
              localStorage.setItem("research-theme", !light ? "light" : "dark");
            }}
          >
            {light ? <Moon size={18} /> : <Sun size={18} />}
          </button>
          <button
            aria-label="Toggle activity panel"
            onClick={() => setAssistant(!assistant)}
          >
            <PanelRight size={18} />
          </button>
          <button
            className="rs-mobile"
            aria-label="Open project navigation"
            onClick={() => setDrawer(!drawer)}
          >
            <Menu size={18} />
          </button>
        </div>
      </header>
      {drawer && (
        <button
          className="rs-overlay"
          aria-label="Close navigation"
          onClick={() => setDrawer(false)}
        />
      )}
      <aside className={`rs-nav ${drawer ? "open" : ""}`}>
        <button
          className="rs-primary"
          onClick={() => {
            if (dirty.current) {
              setError("Save current manuscript first.");
              return;
            }
            setCreating(true);
            setDrawer(false);
          }}
        >
          <Plus size={17} />
          New research
        </button>
        <button onClick={() => choose(null)}>
          <BookOpen size={17} />
          Research dashboard
        </button>
        <h2>Projects</h2>
        <div className="rs-projects">
          {projects.map((x) => (
            <button
              className={id === x.id ? "selected" : ""}
              key={x.id}
              onClick={() => choose(x.id)}
            >
              {x.title}
            </button>
          ))}
        </div>
        {id && (
          <>
            <h2>Workspace</h2>
            {views.map((v) => (
              <button
                key={v}
                aria-current={view === v ? "page" : undefined}
                onClick={() => {
                  setView(v);
                  setDrawer(false);
                }}
              >
                {v}
                <ChevronRight size={13} />
              </button>
            ))}
          </>
        )}
        <p>
          Evidence first.
          <br />
          Human decisions recorded.
        </p>
      </aside>
      <div className={`rs-main ${assistant && id ? "with-assistant" : ""}`}>
        {error && (
          <div className="rs-error" role="alert">
            {error}
            <button aria-label="Dismiss error" onClick={() => setError("")}>
              ×
            </button>
            {error.includes("sign in") && <a href="/">Sign in through Cloud</a>}
          </div>
        )}
        {notice && (
          <div className="rs-notice" role="status">
            {notice}
          </div>
        )}
        {creating ? (
          <section className="rs-card rs-new">
            <span className="rs-eyebrow">New research project</span>
            <h1>Start with a question.</h1>
            <p>
              Your original language, research constraints and source evidence
              stay with the project.
            </p>
            <label>
              Project title
              <input
                value={title}
                maxLength={200}
                onChange={(e) => setTitle(e.target.value)}
              />
            </label>
            <label>
              Research prompt
              <textarea
                dir="auto"
                rows={8}
                value={prompt}
                maxLength={16000}
                onChange={(e) => setPrompt(e.target.value)}
                placeholder="বাংলায়, English বা অন্য ভাষায় আপনার গবেষণার বিষয়, লক্ষ্য এবং সীমাবদ্ধতা লিখুন…"
              />
            </label>
            <div className="rs-grid">
              <label>
                Progress language
                <select
                  value={progressLanguage}
                  onChange={(e) => setProgressLanguage(e.target.value)}
                >
                  {languages.map((l) => (
                    <option key={l}>{l}</option>
                  ))}
                </select>
              </label>
              <label>
                Manuscript language
                <select
                  value={outputLanguage}
                  onChange={(e) => setOutputLanguage(e.target.value)}
                >
                  {languages.map((l) => (
                    <option key={l}>{l}</option>
                  ))}
                </select>
              </label>
            </div>
            <button
              className="rs-primary"
              disabled={busy || !title.trim() || !prompt.trim()}
              onClick={() => void create()}
            >
              Create research project
            </button>
            <button onClick={() => setCreating(false)}>Cancel</button>
          </section>
        ) : !id ? (
          <section>
            <span className="rs-eyebrow">AI Research Studio</span>
            <h1>Research with a traceable foundation.</h1>
            <p>
              Plan investigations, compare literature and build a manuscript
              with evidence and human review.
            </p>
            <div className="rs-dashboard">
              {projects.map((x) => (
                <button
                  className="rs-card"
                  key={x.id}
                  onClick={() => choose(x.id)}
                >
                  <h3>{x.title}</h3>
                  <span className="rs-status">{x.status}</span>
                  <p dir="auto">{x.prompt.slice(0, 180)}</p>
                </button>
              ))}
            </div>
            {!projects.length && (
              <div className="rs-card">Create your first project to begin.</div>
            )}
            {invitations.length > 0 && (
              <section className="rs-card">
                <h2>Project invitations</h2>
                {invitations.map((i) => (
                  <p key={i.id}>
                    {i.title} · {i.role}{" "}
                    <button
                      onClick={() =>
                        void act(() =>
                          api(`invitations/${i.id}/accept`, "POST", {}),
                        )
                      }
                    >
                      Accept invitation
                    </button>
                  </p>
                ))}
              </section>
            )}
          </section>
        ) : !data ? (
          <div role="status">Loading research project…</div>
        ) : (
          <>
            <div className="rs-project-header">
              <span className="rs-eyebrow">{view}</span>
              <h1 dir="auto">{p.title}</h1>
              <p>
                Progress language:{" "}
                {data.run?.plan?.language || p.settings.progress_language} ·
                Manuscript:{" "}
                {data.run?.plan?.output_language || p.settings.output_language}{" "}
                · Role: {p.role}
              </p>
              <span className="rs-status">{p.status}</span>
              <div className="rs-progress-row">
                <div>
                  <strong>
                    {done} / {tasks.length}
                  </strong>{" "}
                  tasks completed
                  <progress
                    aria-label="Completed research tasks"
                    value={done}
                    max={tasks.length || 1}
                  />
                </div>
                <div className="rs-actions">
                  <button
                    disabled={
                      !editable ||
                      busy ||
                      ["running", "paused"].includes(data.run?.status)
                    }
                    onClick={() =>
                      void act(
                        () => api(`projects/${id}/runs`, "POST", {}),
                        "Research queued",
                      )
                    }
                  >
                    Start research
                  </button>
                  {["pause", "resume", "cancel"].map((action) => (
                    <button
                      key={action}
                      disabled={
                        !editable ||
                        busy ||
                        !data.run ||
                        (action === "resume"
                          ? !["paused", "failed", "cancelled"].includes(
                              data.run.status,
                            )
                          : data.run.status !== "running")
                      }
                      onClick={() =>
                        void act(
                          () =>
                            api(`projects/${id}/control`, "POST", { action }),
                          action + " requested",
                        )
                      }
                    >
                      {action}
                    </button>
                  ))}
                </div>
              </div>
            </div>
            {view === "Overview" && (
              <>
                <div className="rs-grid rs-stats">
                  {[
                    ["Sources", sources.length],
                    ["Evidence", data.evidence.length],
                    ["Experiments", data.experiments.length],
                  ].map(([name, value]) => (
                    <div className="rs-card" key={name}>
                      <span>{name}</span>
                      <strong>{value}</strong>
                    </div>
                  ))}
                </div>
                <section className="rs-card">
                  <h2>Research request</h2>
                  <p dir="auto">{p.prompt}</p>
                  <h3>Interpreted plan</h3>
                  {data.run?.plan ? (
                    <Data value={data.run.plan} />
                  ) : (
                    <p>
                      Start research to queue actual intent interpretation and
                      planning.
                    </p>
                  )}
                </section>
                <section className="rs-card">
                  <h2>Validation gates</h2>
                  {Object.entries(data.integrity.gates).map(
                    ([name, passed]) => (
                      <p key={name}>
                        {passed ? "✓" : "○"} {name}{" "}
                        {data.integrity.overrides.includes(name) &&
                          "— human override"}
                      </p>
                    ),
                  )}
                </section>
              </>
            )}
            {view === "Live agents" && (
              <>
                <div className="rs-agent-grid">
                  {tasks.map((t) => (
                    <article
                      key={t.id}
                      className={`rs-card rs-agent ${t.status === "running" ? "active" : ""}`}
                    >
                      <span className="rs-status">{label(t.status)}</span>
                      <h3>{t.agent}</h3>
                      <p>{t.key}</p>
                      {t.checkpoint?.index > 0 && (
                        <p>{t.checkpoint.index} work units completed</p>
                      )}
                      {t.error_code && (
                        <p className="rs-error-text">
                          {t.error_code} · {t.errors} failed attempts
                        </p>
                      )}
                      <details>
                        <summary>Task details and dependencies</summary>
                        <p>
                          Depends on:{" "}
                          {data.dependencies
                            .filter((d: Row) => d.task_id === t.id)
                            .map(
                              (d: Row) =>
                                tasks.find((x) => x.id === d.depends_on)?.key ||
                                d.depends_on,
                            )
                            .join(", ") || "No dependencies"}
                        </p>
                        <Data value={t.input} />
                      </details>
                    </article>
                  ))}
                </div>
                {!tasks.length && (
                  <p>
                    No agent is executing. Start research to create the task
                    graph.
                  </p>
                )}
              </>
            )}
            {view === "Web activity" && (
              <section className="rs-card">
                <h2>Actual retrieval timeline</h2>
                <p>
                  Events come from index queries, licensed source retrieval and
                  DOI verification. Browser snapshots are not simulated.
                </p>
                {events
                  .filter((e) => e.type.startsWith("browsing."))
                  .reverse()
                  .map((e) => (
                    <article className="rs-event" key={e.id}>
                      <time>{new Date(e.created_at).toLocaleString()}</time>
                      <strong>
                        {e.type} · {e.data.provider || e.data.domain}
                      </strong>
                      <p dir="auto">
                        {e.data.query || e.data.title || e.data.status}
                      </p>
                      {e.data.url && (
                        <SourceLink url={e.data.url}>{e.data.url}</SourceLink>
                      )}
                      {e.data.error_code && <p>{e.data.error_code}</p>}
                    </article>
                  ))}
              </section>
            )}
            {(view === "Literature" || view === "Citations") && (
              <>
                <section className="rs-card">
                  <h2>
                    {view === "Citations"
                      ? "Reference library"
                      : "Literature explorer"}
                  </h2>
                  <p>Import CSL JSON or BibTeX. Imports start unverified.</p>
                  <textarea
                    rows={5}
                    aria-label="Reference import"
                    value={refs}
                    onChange={(e) => setRefs(e.target.value)}
                    placeholder="Paste BibTeX or CSL JSON"
                  />
                  <button
                    disabled={!editable || busy || !refs.trim()}
                    onClick={() =>
                      void act(async () => {
                        await api(`projects/${id}/sources`, "POST", {
                          records: parseReferences(refs),
                        });
                        setRefs("");
                      })
                    }
                  >
                    Import references
                  </button>
                  <button
                    disabled={!editable || busy}
                    onClick={() => component("verification")}
                  >
                    Verify reference metadata
                  </button>
                  <button
                    disabled={!editable || busy}
                    onClick={() => component("literature")}
                  >
                    Analyze project sources
                  </button>
                </section>
                <div className="rs-source-list">
                  {sources.map((s) => (
                    <article className="rs-card" key={s.id}>
                      <div className="rs-source-heading">
                        <input
                          aria-label={"Compare " + s.title}
                          type="checkbox"
                          checked={compare.includes(s.id)}
                          onChange={(e) =>
                            setCompare((old) =>
                              e.target.checked
                                ? [...old, s.id]
                                : old.filter((x) => x !== s.id),
                            )
                          }
                        />
                        <h3 dir="auto">{s.title}</h3>
                        <span className="rs-status">{s.verification}</span>
                      </div>
                      <p>
                        {s.year || "Date unknown"} ·{" "}
                        {s.venue || "Venue not supplied"}
                      </p>
                      <SourceLink url={s.url || "https://doi.org/" + s.doi}>
                        {s.doi || s.url || "No source link"}
                      </SourceLink>
                      <p>
                        <button
                          onClick={() => {
                            setSourceId(s.id);
                            setView("PDF reader");
                          }}
                        >
                          Read / chat
                        </button>
                      </p>
                    </article>
                  ))}
                </div>
                {compare.length > 1 && (
                  <section className="rs-card">
                    <h2>Literature comparison</h2>
                    <div className="rs-table-scroll">
                      <table>
                        <thead>
                          <tr>
                            <th>Source</th>
                            <th>Verification</th>
                            <th>Extracted analysis</th>
                          </tr>
                        </thead>
                        <tbody>
                          {sources
                            .filter((s) => compare.includes(s.id))
                            .map((s) => (
                              <tr key={s.id}>
                                <td>{s.title}</td>
                                <td>{s.verification}</td>
                                <td>
                                  <Data
                                    value={(
                                      latest("literature")?.analyses || []
                                    ).filter((a: Row) => a.source_id === s.id)}
                                  />
                                </td>
                              </tr>
                            ))}
                        </tbody>
                      </table>
                    </div>
                    <p>
                      Only actual extracted analyses are shown; an empty cell
                      means analysis is pending.
                    </p>
                  </section>
                )}
              </>
            )}
            {view === "PDF reader" && (
              <>
                <section className="rs-card">
                  <label>
                    Selected source
                    <select
                      value={sourceId || ""}
                      onChange={(e) =>
                        setSourceId(Number(e.target.value) || null)
                      }
                    >
                      <option value="">New PDF upload / choose source</option>
                      {sources.map((s) => (
                        <option key={s.id} value={s.id}>
                          {s.title}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="rs-check">
                    <input
                      type="checkbox"
                      checked={uploadConsent}
                      onChange={(e) => setUploadConsent(e.target.checked)}
                    />
                    I have permission to use and process this file.
                  </label>
                  {fileInput("pdf", ".pdf")}
                  <p>
                    Upload a licensed/authorized PDF. Scanned pages require an
                    external OCR workflow.
                  </p>
                </section>
                {source && (
                  <section className="rs-card">
                    <h2 dir="auto">{source.title}</h2>
                    <p>
                      Parsing:{" "}
                      {source.metadata.parse_status || "Not an uploaded PDF"} ·{" "}
                      {source.metadata.pages || "Unknown"} pages
                    </p>
                    {pdf && (
                      <iframe
                        title="Private source PDF"
                        src={pdf}
                        className="rs-pdf"
                      />
                    )}
                    <details open>
                      <summary>Extracted source text</summary>
                      <div className="rs-source-text" dir="auto">
                        {source.text ||
                          "No extracted text yet. Check processing events or worker configuration."}
                      </div>
                    </details>
                  </section>
                )}
              </>
            )}
            {view === "Research gaps" && (
              <section className="rs-card">
                <h2>Evidence-supported research gaps</h2>
                <button
                  disabled={!editable || busy}
                  onClick={() => component("gaps")}
                >
                  Analyze gaps
                </button>
                {latest("gaps") ? (
                  <Data value={latest("gaps")} />
                ) : (
                  <p>
                    Run investigations before interpreting gaps. Corpus gaps do
                    not establish global novelty.
                  </p>
                )}
                <h3>Independent investigations</h3>
                {findings
                  .filter((f) => f.kind === "investigation")
                  .map((f) => (
                    <Data key={f.id} value={f.data} />
                  ))}
              </section>
            )}
            {view === "Experiments" && (
              <>
                <section className="rs-card">
                  <h2>Isolated Python experiments</h2>
                  <p>
                    Execution requires the configured private Docker worker and
                    explicit approval of each exact code/dataset/seed hash.
                  </p>
                  {latest("experiments") && (
                    <details>
                      <summary>Agent experiment plans</summary>
                      <Data value={latest("experiments")} />
                      {latest("experiments")?.plans?.map(
                        (plan: Row, i: number) => (
                          <button
                            key={i}
                            onClick={() => {
                              setExpTitle(plan.title);
                              setCode(plan.code);
                            }}
                            disabled={!editable}
                          >
                            Review plan: {plan.title}
                          </button>
                        ),
                      )}
                    </details>
                  )}
                  <label>
                    Experiment title
                    <input
                      value={expTitle}
                      onChange={(e) => setExpTitle(e.target.value)}
                    />
                  </label>
                  <label>
                    Python code
                    <textarea
                      className="rs-code"
                      rows={12}
                      value={code}
                      onChange={(e) => setCode(e.target.value)}
                    />
                  </label>
                  <label>
                    Random seed
                    <input
                      type="number"
                      min={0}
                      value={seed}
                      onChange={(e) => setSeed(Number(e.target.value))}
                    />
                  </label>
                  <label className="rs-check">
                    <input
                      type="checkbox"
                      checked={uploadConsent}
                      onChange={(e) => setUploadConsent(e.target.checked)}
                    />
                    I have permission to use this dataset.
                  </label>
                  {fileInput("dataset", ".csv")}
                  <fieldset>
                    <legend>Authorized dataset inputs</legend>
                    {artifacts
                      .filter((a) => a.kind === "dataset")
                      .map((a) => (
                        <label className="rs-check" key={a.id}>
                          <input
                            type="checkbox"
                            checked={datasetIds.includes(a.id)}
                            onChange={(e) =>
                              setDatasetIds((old) =>
                                e.target.checked
                                  ? [...old, a.id]
                                  : old.filter((x) => x !== a.id),
                              )
                            }
                          />
                          {a.name} → /work/input/dataset-{a.id}.csv
                        </label>
                      ))}
                  </fieldset>
                  <button
                    disabled={!editable || busy}
                    onClick={() =>
                      void act(
                        () =>
                          api(`projects/${id}/experiments`, "POST", {
                            title: expTitle,
                            code,
                            seed,
                            dataset_ids: datasetIds,
                          }),
                        "Experiment saved for review",
                      )
                    }
                  >
                    Save experiment for approval
                  </button>
                  <label className="rs-check">
                    <input
                      type="checkbox"
                      checked={executionConsent}
                      onChange={(e) => setExecutionConsent(e.target.checked)}
                    />
                    I authorize execution of the reviewed saved code with its
                    listed datasets and seed.
                  </label>
                </section>
                {data.experiments.map((x: Row) => (
                  <section key={x.id} className="rs-card">
                    <h3>
                      {x.title} <span className="rs-status">{x.status}</span>
                    </h3>
                    <p>
                      Code/dataset/seed approval hash:{" "}
                      <code>{x.code_hash}</code>
                    </p>
                    <details>
                      <summary>Review exact saved code and manifest</summary>
                      <Data
                        value={{
                          code: x.code,
                          seed: x.seed,
                          dataset_ids: x.dataset_ids,
                        }}
                      />
                    </details>
                    <button
                      disabled={
                        p.role !== "owner" ||
                        busy ||
                        !executionConsent ||
                        x.status !== "draft"
                      }
                      onClick={() =>
                        void act(
                          () =>
                            api(
                              `projects/${id}/experiments/${x.id}/execute`,
                              "POST",
                              { hash: x.code_hash, authorized: true },
                            ),
                          "Approved experiment queued",
                        )
                      }
                    >
                      Authorize this exact experiment
                    </button>
                    {x.result && <Data value={x.result} />}
                    <div>
                      {artifacts
                        .filter((a) => a.experiment_id === x.id)
                        .map((a) => (
                          <a
                            key={a.id}
                            href={`/api/backend/research/projects/${id}/artifacts/${a.id}/download`}
                          >
                            {a.name} ({a.bytes} bytes)
                          </a>
                        ))}
                    </div>
                  </section>
                ))}
              </>
            )}
            {view === "Manuscript" && (
              <>
                <section className="rs-card">
                  <div className="rs-actions">
                    <button
                      disabled={!editable || busy}
                      onClick={() => component("editor")}
                    >
                      Draft / revise with agent
                    </button>
                    <button
                      disabled={!editable || busy}
                      onClick={() => component("manuscript")}
                    >
                      Build latest draft proposal
                    </button>
                    <button
                      disabled={!editable || !latest("manuscript")}
                      onClick={() => setProposal(latest("manuscript"))}
                    >
                      Review manuscript proposal
                    </button>
                  </div>
                  <label className="rs-check">
                    <input
                      type="checkbox"
                      checked={uploadConsent}
                      onChange={(e) => setUploadConsent(e.target.checked)}
                    />
                    I have permission to use this figure.
                  </label>
                  {fileInput("figure", "image/png,image/jpeg")}
                </section>
                <AcademicEditor
                  key={id}
                  projectId={id}
                  document={data.document}
                  sources={sources}
                  artifacts={artifacts}
                  style={p.settings.citation_style}
                  readonly={!editable}
                  onDirty={(value) => {
                    dirty.current = value;
                  }}
                  onSelection={setSelection}
                  onSaved={(doc) =>
                    setData((old) => (old ? { ...old, document: doc } : old))
                  }
                />
                <section className="rs-card">
                  <h3>Selection editing and continuation</h3>
                  <p>
                    {selection
                      ? selection.slice(0, 300)
                      : "Select manuscript text to request a reviewable revision, or leave selection empty to continue from current document."}
                  </p>
                  <label>
                    Editing instruction
                    <input
                      value={editing}
                      onChange={(e) => setEditing(e.target.value)}
                    />
                  </label>
                  <button
                    disabled={!editable || busy}
                    onClick={() =>
                      component("selection", {
                        selection:
                          selection ||
                          JSON.stringify(data.document.content).slice(0, 10000),
                        instruction: editing,
                      })
                    }
                  >
                    Suggest revision / continuation
                  </button>
                  {latest("selection") && (
                    <>
                      <Data value={latest("selection")} />
                      <button
                        onClick={() =>
                          void navigator.clipboard
                            .writeText(latest("selection").suggestion)
                            .then(() =>
                              setNotice(
                                "Suggestion copied; paste it into the manuscript after review.",
                              ),
                            )
                            .catch(() =>
                              setError(
                                "Clipboard unavailable. Select and copy the suggestion manually.",
                              ),
                            )
                        }
                      >
                        Copy reviewed suggestion
                      </button>
                    </>
                  )}
                </section>
              </>
            )}
            {proposal && (
              <section className="rs-card rs-proposal">
                <h2>Review proposed document replacement</h2>
                <p>
                  Proposal base version {proposal.base_version}; current version{" "}
                  {data.document.version}. Accepting creates a new revision.
                  Your current draft must be saved first.
                </p>
                <Data value={proposal.content} />
                <button
                  disabled={
                    !editable ||
                    busy ||
                    proposal.base_version !== data.document.version
                  }
                  onClick={saveProposal}
                >
                  Accept reviewed proposal
                </button>
                <button onClick={() => setProposal(null)}>
                  Dismiss proposal
                </button>
              </section>
            )}
            {view === "Quality review" && (
              <>
                <section className="rs-card">
                  <h2>Quality and integrity</h2>
                  <button
                    disabled={!editable || busy}
                    onClick={() => component("quality")}
                  >
                    Review actual saved manuscript
                  </button>
                  <button
                    disabled={!editable || busy}
                    onClick={() => component("integrity")}
                  >
                    Audit validation gates
                  </button>
                  {latest("quality") && <Data value={latest("quality")} />}
                  <Data value={data.integrity} />
                  <p>
                    Similarity checks cover project sources only. A human
                    override is recorded separately from verification.
                  </p>
                </section>
                <section className="rs-card">
                  <h2>Review source evidence</h2>
                  {data.evidence.map((e: Row) => (
                    <article key={e.id} className="rs-evidence">
                      <p dir="auto">{e.claim}</p>
                      <blockquote dir="auto">{e.quote}</blockquote>
                      <p>
                        Source #{e.source_id} · {e.status}
                      </p>
                      {["supported", "unsupported", "uncertain"].map(
                        (status) => (
                          <button
                            key={status}
                            disabled={!reviewers || busy}
                            onClick={() =>
                              void act(() =>
                                api(`projects/${id}/evidence/${e.id}`, "PUT", {
                                  status,
                                }),
                              )
                            }
                          >
                            {status}
                          </button>
                        ),
                      )}
                    </article>
                  ))}
                </section>
                <section className="rs-card">
                  <h2>Record a human decision</h2>
                  <label>
                    Gate
                    <select
                      value={gate}
                      onChange={(e) => setGate(e.target.value)}
                    >
                      {["evidence", "experiment", "manuscript"].map((g) => (
                        <option key={g}>{g}</option>
                      ))}
                    </select>
                  </label>
                  <label className="rs-check">
                    <input
                      type="checkbox"
                      checked={override}
                      onChange={(e) => setOverride(e.target.checked)}
                    />
                    Explicit override of a failed gate (remains auditable)
                  </label>
                  <label>
                    Decision reason
                    <textarea
                      value={reason}
                      onChange={(e) => setReason(e.target.value)}
                    />
                  </label>
                  <button
                    disabled={
                      !["owner", "reviewer"].includes(p.role) ||
                      busy ||
                      reason.trim().length < 10
                    }
                    onClick={() =>
                      void act(() =>
                        api(`projects/${id}/approvals`, "POST", {
                          gate,
                          override,
                          reason,
                          hash: data.integrity.hash,
                        }),
                      )
                    }
                  >
                    Record decision for current content
                  </button>
                </section>
              </>
            )}
            {view === "Collaboration" && (
              <>
                <section className="rs-card">
                  <h2>Project collaborators</h2>
                  {data.members.map((m: Row) => (
                    <p key={m.id}>
                      {m.name} · {m.role} ·{" "}
                      {m.accepted_at ? "Accepted" : "Pending"}{" "}
                      <button
                        disabled={p.role !== "owner" || busy}
                        onClick={() =>
                          void act(() =>
                            api(`projects/${id}/members/${m.id}`, "DELETE", {}),
                          )
                        }
                      >
                        Revoke access
                      </button>
                    </p>
                  ))}
                  <label>
                    Existing Cloud user email
                    <input
                      type="email"
                      value={invite}
                      onChange={(e) => setInvite(e.target.value)}
                    />
                  </label>
                  <label>
                    Role
                    <select
                      value={role}
                      onChange={(e) => setRole(e.target.value)}
                    >
                      {["editor", "reviewer", "viewer"].map((r) => (
                        <option key={r}>{r}</option>
                      ))}
                    </select>
                  </label>
                  <label className="rs-check">
                    <input
                      type="checkbox"
                      checked={shareConsent}
                      onChange={(e) => setShareConsent(e.target.checked)}
                    />
                    I authorize sharing this project with the selected person.
                  </label>
                  <button
                    disabled={p.role !== "owner" || busy || !shareConsent}
                    onClick={() =>
                      void act(
                        () =>
                          api(`projects/${id}/members`, "POST", {
                            email: invite,
                            role,
                            authorized: true,
                          }),
                        "Invitation recorded; recipient must accept",
                      )
                    }
                  >
                    Invite collaborator
                  </button>
                  <p>
                    Invitations appear in the recipient’s Studio dashboard; no
                    email is sent automatically.
                  </p>
                  {latest("collaboration") && (
                    <Data value={latest("collaboration")} />
                  )}
                </section>
                <section className="rs-card">
                  <h2>Comments and review annotations</h2>
                  <textarea
                    value={comment}
                    onChange={(e) => setComment(e.target.value)}
                    placeholder="Comment on selected text, a section or the project"
                  />
                  <button
                    disabled={!reviewers || busy || !comment.trim()}
                    onClick={() =>
                      void act(async () => {
                        await api(`projects/${id}/comments`, "POST", {
                          body: comment,
                          anchor: selection.slice(0, 200) || null,
                        });
                        setComment("");
                      })
                    }
                  >
                    Add comment
                  </button>
                  {data.comments.map((c: Row) => (
                    <article key={c.id} className="rs-event">
                      <strong>
                        User #{c.user_id} ·{" "}
                        {new Date(c.created_at).toLocaleString()}
                      </strong>
                      {c.anchor && <blockquote>{c.anchor}</blockquote>}
                      <p dir="auto">{c.body}</p>
                    </article>
                  ))}
                </section>
              </>
            )}
            {view === "Export" && (
              <section className="rs-card">
                <h2>Journal formatting and export</h2>
                <p>
                  Set journal/layout/citation preferences in Settings. Final
                  exports require current integrity gates; draft files are
                  labeled.
                </p>
                <label>
                  Format
                  <select
                    value={format}
                    onChange={(e) => setFormat(e.target.value)}
                  >
                    {["docx", "pdf", "tex", "bib", "md"].map((f) => (
                      <option key={f}>{f}</option>
                    ))}
                  </select>
                </label>
                <label className="rs-check">
                  <input
                    type="checkbox"
                    checked={draft}
                    onChange={(e) => setDraft(e.target.checked)}
                  />
                  Draft export with unresolved checks disclosed
                </label>
                <button
                  disabled={
                    !editable || busy || (!draft && !data.integrity.passed)
                  }
                  onClick={() =>
                    void act(async () => {
                      if (dirty.current)
                        throw new Error(
                          "Save manuscript changes before exporting.",
                        );
                      await api(`projects/${id}/exports`, "POST", {
                        format,
                        draft,
                      });
                    }, "Frozen manuscript revision queued for validated export")
                  }
                >
                  Generate export
                </button>
                {data.exports.map((e: Row) => (
                  <article className="rs-event" key={e.id}>
                    <strong>
                      {e.format.toUpperCase()} · {e.status} ·{" "}
                      {e.draft ? "Draft" : "Reviewed final"}
                    </strong>
                    {e.status === "completed" && (
                      <a
                        href={`/api/backend/research/projects/${id}/exports/${e.id}/download`}
                      >
                        Download validated file ({e.bytes} bytes)
                      </a>
                    )}
                    {e.error_code && <p>{e.error_code}</p>}
                  </article>
                ))}
              </section>
            )}
            {view === "History" && (
              <>
                <section className="rs-card">
                  <h2>Immutable manuscript revisions</h2>
                  {revisions.map((r) => (
                    <details key={r.id}>
                      <summary>
                        Version {r.version} · {r.kind} · User #{r.actor_id} ·{" "}
                        {r.created_at}
                      </summary>
                      <Data value={r.content} />
                      <button
                        disabled={!editable}
                        onClick={() =>
                          setProposal({
                            content: r.content,
                            base_version: data.document.version,
                          })
                        }
                      >
                        Review restoring this revision
                      </button>
                    </details>
                  ))}
                </section>
                <section className="rs-card">
                  <h2>Human decisions</h2>
                  {data.approvals.map((a: Row) => (
                    <article key={a.id} className="rs-event">
                      <strong>
                        {a.gate} · {a.override ? "Human override" : "Approved"}{" "}
                        · User #{a.user_id}
                      </strong>
                      <p>{a.reason}</p>
                      <code>{a.content_hash}</code>
                    </article>
                  ))}
                </section>
              </>
            )}
            {view === "Settings" && (
              <section className="rs-card">
                <h2>Project settings</h2>
                {["progress_language", "output_language"].map((key) => (
                  <label key={key}>
                    {key.replaceAll("_", " ")}
                    <select
                      value={settings[key] || "auto"}
                      onChange={(e) =>
                        setSettings({ ...settings, [key]: e.target.value })
                      }
                    >
                      {languages.map((l) => (
                        <option key={l}>{l}</option>
                      ))}
                    </select>
                  </label>
                ))}
                <div className="rs-grid">
                  {["from_year", "to_year"].map((key) => (
                    <label key={key}>
                      {key.replaceAll("_", " ")}
                      <input
                        type="number"
                        min={1900}
                        max={2100}
                        value={settings[key] || ""}
                        onChange={(e) =>
                          setSettings({
                            ...settings,
                            [key]: Number(e.target.value),
                          })
                        }
                      />
                    </label>
                  ))}
                </div>
                <label>
                  Citation style
                  <select
                    value={settings.citation_style || "apa"}
                    onChange={(e) =>
                      setSettings({
                        ...settings,
                        citation_style: e.target.value,
                      })
                    }
                  >
                    {[
                      "apa",
                      "ieee",
                      "acm",
                      "vancouver",
                      "chicago",
                      "harvard",
                    ].map((s) => (
                      <option key={s}>{s}</option>
                    ))}
                  </select>
                </label>
                <label>
                  Journal layout
                  <select
                    value={settings.journal || "generic"}
                    onChange={(e) =>
                      setSettings({ ...settings, journal: e.target.value })
                    }
                  >
                    {[
                      "generic",
                      "ieee",
                      "acm",
                      "springer",
                      "elsevier",
                      "custom",
                    ].map((s) => (
                      <option key={s}>{s}</option>
                    ))}
                  </select>
                </label>
                <div className="rs-grid">
                  {["font_size", "margin_mm", "columns"].map((key) => (
                    <label key={key}>
                      {key.replaceAll("_", " ")}
                      <input
                        type="number"
                        value={
                          settings[key] ||
                          { font_size: 11, margin_mm: 25, columns: 1 }[
                            key as "font_size"
                          ]
                        }
                        onChange={(e) =>
                          setSettings({
                            ...settings,
                            [key]: Number(e.target.value),
                          })
                        }
                      />
                    </label>
                  ))}
                </div>
                <details>
                  <summary>Workload model selection</summary>
                  <p>
                    Slugs must refer to enabled, API-ready models with a
                    successful provider verification. This form cannot enable
                    models.
                  </p>
                  {[
                    "planning",
                    "literature",
                    "investigation",
                    "gaps",
                    "experiments",
                    "editor",
                    "quality",
                    "question",
                    "selection",
                  ].map((agent) => (
                    <label key={agent}>
                      {agent}
                      <input
                        value={settings.models?.[agent] || ""}
                        placeholder="Use server default"
                        onChange={(e) => {
                          const models = { ...settings.models };
                          if (e.target.value) models[agent] = e.target.value;
                          else delete models[agent];
                          setSettings({ ...settings, models });
                        }}
                      />
                    </label>
                  ))}
                </details>
                <button
                  disabled={!editable || busy}
                  onClick={() =>
                    void act(() =>
                      api(`projects/${id}/settings`, "PUT", settings),
                    )
                  }
                >
                  Save project settings
                </button>
                <p>
                  Publisher presets are independently defined formatting aids;
                  verify the target journal’s current requirements.
                </p>
              </section>
            )}
          </>
        )}
      </div>
      {assistant && id && (
        <aside className="rs-assistant">
          <div className="rs-asst-heading">
            <h2>Research assistant</h2>
            <button
              aria-label="Close activity panel"
              onClick={() => setAssistant(false)}
            >
              <X size={15} />
            </button>
          </div>
          <label>
            Project or selected source question
            <textarea
              rows={4}
              value={question}
              dir="auto"
              onChange={(e) => setQuestion(e.target.value)}
            />
          </label>
          <button
            disabled={!editable || busy || !question.trim()}
            onClick={() =>
              component("question", {
                question,
                ...(sourceId ? { source_id: sourceId } : {}),
              })
            }
          >
            Ask using project evidence
          </button>
          {latest("question") && (
            <div className="rs-answer" dir="auto">
              <p>{latest("question").answer}</p>
              <small>{latest("question").uncertainty}</small>
              <p>Sources: {latest("question").source_ids?.join(", ")}</p>
            </div>
          )}
          <h2>Live execution trail</h2>
          <div className="rs-timeline">
            {events
              .slice(-35)
              .reverse()
              .map((e) => (
                <article key={e.id}>
                  <strong>{e.type.replaceAll(".", " ")}</strong>
                  <p dir="auto">
                    {e.data.query ||
                      e.data.title ||
                      e.data.agent ||
                      e.data.stage ||
                      e.data.error_code ||
                      e.data.status}
                  </p>
                  <time>{new Date(e.created_at).toLocaleTimeString()}</time>
                </article>
              ))}
            {!events.length && <p>No execution events received yet.</p>}
          </div>
        </aside>
      )}
    </main>
  );
}
