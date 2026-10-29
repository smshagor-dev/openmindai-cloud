"use client";
import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import {
  ArrowLeft,
  Box,
  Layers3,
  LoaderCircle,
  Plus,
  Trash2,
} from "lucide-react";
import { downloadScene, studioApi, type SavedScene } from "@/lib/studio-scene";
import "./studio.css";
const Viewport = dynamic(() => import("@/components/studio-viewport"), {
  ssr: false,
  loading: () => <p role="status">Loading 3D renderer…</p>,
});
type History = { id: number; title: string; mode: "object" | "architecture" };
const examples = {
  object: [
    "A four-wheel delivery robot with a blue cargo compartment, front cameras and a green status light.",
    "A modern reading chair with a curved back, wooden legs and a matching side table.",
  ],
  architecture: [
    "Users → Next.js frontend → API gateway. Gateway connects to auth, Laravel and AI service. Laravel connects to PostgreSQL and Redis. AI service connects to hosted inference. A queue connects Laravel to workers.",
    "Temperature sensors → IoT gateway → message broker → stream processor → time-series database → monitoring dashboard.",
  ],
};
export default function StudioPage() {
  const [mode, setMode] = useState<"object" | "architecture">("object"),
    [prompt, setPrompt] = useState(""),
    [saved, setSaved] = useState<SavedScene | null>(null),
    [history, setHistory] = useState<History[]>([]),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(false),
    [error, setError] = useState("");
  const pending = useRef(false);
  async function refresh() {
    const data = await studioApi();
    setHistory(data.data);
  }
  useEffect(() => {
    refresh().catch((e) => setError(e.message));
  }, []);
  async function generate(e: React.FormEvent) {
    e.preventDefault();
    if (pending.current) return;
    pending.current = true;
    setBusy(true);
    setError("");
    try {
      const result = await studioApi("", "POST", { mode, prompt });
      setSaved(result);
      await refresh();
    } catch (e) {
      setError(e instanceof Error ? e.message : "Generation failed.");
    } finally {
      pending.current = false;
      setBusy(false);
    }
  }
  async function open(id: number) {
    setLoading(true);
    setError("");
    try {
      const result: SavedScene = await studioApi(`/${id}`);
      setSaved(result);
      setMode(result.mode);
      setPrompt(result.prompt);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load scene.");
    } finally {
      setLoading(false);
    }
  }
  return (
    <main className="studio-shell">
      <aside className="studio-sidebar">
        <a href="/" className="studio-back">
          <ArrowLeft size={16} /> OpenMind Cloud
        </a>
        <h1>
          <Box size={24} /> 3D Studio
        </h1>
        <button
          disabled={busy || loading}
          onClick={() => {
            setSaved(null);
            setPrompt("");
            setError("");
          }}
        >
          <Plus size={16} /> New scene
        </button>
        <a href="/research-studio">Research Studio ↗</a>
        <h2>Your scenes</h2>
        {!history.length && (
          <p className="studio-muted">Generated scenes will appear here.</p>
        )}
        <div className="studio-history">
          {history.map((item) => (
            <div
              key={item.id}
              className={saved?.id === item.id ? "active" : ""}
            >
              <button disabled={busy || loading} onClick={() => open(item.id)}>
                {item.mode === "object" ? (
                  <Box size={15} />
                ) : (
                  <Layers3 size={15} />
                )}
                <span>{item.title}</span>
              </button>
              <button
                disabled={busy || loading}
                aria-label={`Delete ${item.title}`}
                onClick={async () => {
                  if (!window.confirm(`Delete “${item.title}”?`)) return;
                  setLoading(true);
                  try {
                    await studioApi(`/${item.id}`, "DELETE");
                    if (saved?.id === item.id) setSaved(null);
                    await refresh();
                  } catch (e) {
                    setError(e instanceof Error ? e.message : "Delete failed.");
                  } finally {
                    setLoading(false);
                  }
                }}
              >
                <Trash2 size={14} />
              </button>
            </div>
          ))}
        </div>
      </aside>
      <section className="studio-main">
        <header>
          <span className="studio-eyebrow">OPENMIND CLOUD · 3D STUDIO</span>
          <h2>
            {saved ? saved.scene.title : "Turn an idea into a new dimension."}
          </h2>
          <p>
            {saved
              ? saved.scene.description
              : "Describe an object or map a system. Explore the result in an interactive 3D workspace."}
          </p>
        </header>
        {error && (
          <div role="alert" className="studio-error">
            {error}
          </div>
        )}
        {loading && <p role="status">Loading your scene…</p>}
        {saved ? (
          <>
            <Viewport
              scene={saved.scene}
              architecture={saved.mode === "architecture"}
            />
            <div className="studio-meta">
              <span>
                {saved.scene.nodes.length} parts · {saved.scene.edges.length}{" "}
                connections
              </span>
              <button
                onClick={() =>
                  downloadScene(
                    JSON.stringify(saved.scene, null, 2),
                    "openmind-scene.json",
                    "application/json",
                  )
                }
              >
                Download scene JSON
              </button>
            </div>
          </>
        ) : (
          <section className="studio-empty">
            <div className="studio-orb">
              <Box size={72} strokeWidth={1} />
            </div>
            <h3>Your idea, in three dimensions</h3>
            <p>
              Build with procedural shapes or explore connected services in
              space.
            </p>
            <div className="studio-examples">
              {examples[mode].map((text) => (
                <button
                  disabled={busy}
                  key={text}
                  onClick={() => setPrompt(text)}
                >
                  {text}
                </button>
              ))}
            </div>
          </section>
        )}
        <form className="studio-composer" onSubmit={generate}>
          <div className="studio-modes" role="group" aria-label="Scene type">
            {(["object", "architecture"] as const).map((value) => (
              <button
                type="button"
                key={value}
                aria-pressed={mode === value}
                disabled={busy}
                onClick={() => setMode(value)}
              >
                {value === "object" ? <Box size={17} /> : <Layers3 size={17} />}{" "}
                {value === "object" ? "3D Object" : "Architecture"}
              </button>
            ))}
          </div>
          <label htmlFor="scene-prompt">
            {mode === "object"
              ? "Describe your object, materials and proportions"
              : "Describe your services, layers and connections"}
          </label>
          <textarea
            id="scene-prompt"
            value={prompt}
            onChange={(e) => setPrompt(e.target.value)}
            minLength={5}
            maxLength={8000}
            required
            disabled={busy}
            placeholder="What would you like to create?"
            rows={3}
          />
          <div className="studio-submit">
            <small>Procedural models · Multilingual prompts · GLB export</small>
            <button
              type="submit"
              disabled={busy || loading || prompt.trim().length < 5}
            >
              {busy ? (
                <>
                  <LoaderCircle className="studio-spin" size={18} /> Generating
                  scene…
                </>
              ) : (
                "Generate scene →"
              )}
            </button>
          </div>
          {busy && (
            <p role="status" aria-live="polite">
              Building your scene with OpenMind Cloud. It will appear after
              generation and validation; this can take a few minutes.
            </p>
          )}
          <small className="studio-muted">
            Each generation creates a new saved scene. Models use basic shapes;
            dimensions are illustrative.
          </small>
        </form>
      </section>
    </main>
  );
}
