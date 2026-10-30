"use client";
import WorkspaceWelcome from "@/components/workspace-welcome";
import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import {
  ArrowLeft,
  Box,
  Layers3,
  LoaderCircle,
  Moon,
  Plus,
  Sun,
  Trash2,
} from "lucide-react";
import { downloadScene, studioApi, type SavedScene } from "@/lib/studio-scene";
import { prepareStudioUpload, type StudioReference } from "@/lib/studio-upload";
import {
  workbenchApi,
  type Asset,
  type Capabilities,
} from "@/lib/studio-workbench";
import Workbench from "@/components/studio-workbench";
import SceneEditor from "@/components/studio-scene-editor";
import "./studio.css";
const AssetViewport = dynamic(
  () => import("@/components/studio-asset-viewport"),
  { ssr: false, loading: () => <p role="status">Loading mesh renderer…</p> },
);
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
  const [mode, setMode] = useState<"auto" | "object" | "architecture">("auto"),
    [prompt, setPrompt] = useState(""),
    [saved, setSaved] = useState<SavedScene | null>(null),
    [history, setHistory] = useState<History[]>([]),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(false),
    [error, setError] = useState(""),
    [references, setReferences] = useState<StudioReference[]>([]),
    [selectedUploads, setSelectedUploads] = useState<number[]>([]),
    [uploading, setUploading] = useState(false);
  const [capabilities, setCapabilities] = useState<Capabilities | null>(null),
    [engine, setEngine] = useState("auto"),
    [engineParams, setEngineParams] = useState<Record<string, unknown>>({}),
    [jobId, setJobId] = useState<string | null>(null),
    [selectedAsset, setSelectedAsset] = useState<Asset | null>(null),
    [light, setLight] = useState(false);
  const pending = useRef(false);
  async function refresh() {
    const [data, uploads] = await Promise.all([
      studioApi(),
      studioApi("/uploads"),
    ]);
    setHistory(data.data);
    setReferences(uploads.data);
  }
  useEffect(() => {
    try {
      setLight(localStorage.getItem("studio-theme") === "light");
    } catch {
      /* Storage can be disabled by the browser. */
    }
    refresh().catch((e) => setError(e.message));
    workbenchApi("/engines")
      .then(setCapabilities)
      .catch((e) => setError(e.message));
  }, []);
  async function generate(e: React.FormEvent) {
    e.preventDefault();
    if (pending.current) return;
    pending.current = true;
    setBusy(true);
    setError("");
    try {
      const route = await workbenchApi("/route", "POST", {
        mode,
        engine,
        prompt,
        upload_ids: selectedUploads,
      });
      let result;
      if (route.output === "scene") {
        result = await workbenchApi("/jobs/scene", "POST", {
          request_key: crypto.randomUUID(),
          mode: route.mode,
          prompt,
          upload_ids: selectedUploads,
        });
      } else {
        const image = references.find(
          (r) => selectedUploads.includes(r.id) && r.mime.startsWith("image/"),
        );
        if (!image)
          throw new Error("Select an image reference for this engine.");
        result = await workbenchApi("/jobs", "POST", {
          request_key: crypto.randomUUID(),
          engine: route.engine,
          operation: "generate",
          upload_id: image.id,
          params: engineParams,
        });
      }
      setJobId(result.id);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Generation failed.");
      pending.current = false;
    } finally {
      if (!pending.current) setBusy(false);
    }
  }
  async function open(id: number) {
    setLoading(true);
    setError("");
    try {
      const result: SavedScene = await studioApi(`/${id}`);
      setSaved(result);
      setSelectedAsset(null);
      setMode(result.mode);
      setPrompt(result.prompt);
      setSelectedUploads(result.upload_ids || []);
      setReferences((old) => [
        ...(result.uploads || []),
        ...old.filter(
          (file) => !result.uploads?.some((linked) => linked.id === file.id),
        ),
      ]);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not load scene.");
    } finally {
      setLoading(false);
    }
  }
  return (
    <main className={`studio-shell${light ? " studio-light" : ""}`}>
      <aside className="studio-sidebar">
        <a href="/" className="studio-back">
          <ArrowLeft size={16} /> OpenMind Cloud
        </a>
        <div className="studio-title">
          <Box size={24} /> 3D Studio
        </div>
        <button
          type="button"
          aria-label={light ? "Use dark theme" : "Use light theme"}
          onClick={() => {
            setLight(!light);
            try {
              localStorage.setItem("studio-theme", light ? "dark" : "light");
            } catch {
              /* Keep the in-memory preference. */
            }
          }}
        >
          {light ? <Moon size={16} /> : <Sun size={16} />}{" "}
          {light ? "Dark theme" : "Light theme"}
        </button>
        <button
          disabled={busy || loading}
          onClick={() => {
            setSaved(null);
            setSelectedAsset(null);
            setMode("auto");
            setPrompt("");
            setSelectedUploads([]);
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
      <section className={`studio-main ${saved ? "" : "studio-startup"}`}>
        {saved ? (
          <header>
            <span className="studio-eyebrow">OPENMIND CLOUD · 3D STUDIO</span>
            <h1>{saved.scene.title}</h1>
            <p>{saved.scene.description}</p>
          </header>
        ) : (
          <WorkspaceWelcome title="What would you like to create?" />
        )}
        {error && (
          <div role="alert" className="studio-error">
            {error}
          </div>
        )}
        {loading && <p role="status">Loading your scene…</p>}
        {selectedAsset ? (
          <AssetViewport key={selectedAsset.id} asset={selectedAsset} />
        ) : saved ? (
          <>
            <Viewport
              scene={saved.scene}
              architecture={saved.mode === "architecture"}
            />
            <SceneEditor
              key={saved.id}
              saved={saved}
              onChange={(scene) => setSaved({ ...saved, scene })}
              onSaved={setSaved}
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
        ) : null}
        <form className="studio-composer" onSubmit={generate}>
          <div className="studio-modes" role="group" aria-label="Scene type">
            {(["auto", "object", "architecture"] as const).map((value) => (
              <button
                type="button"
                key={value}
                aria-pressed={mode === value}
                disabled={busy}
                onClick={() => setMode(value)}
              >
                {value === "object" ? <Box size={17} /> : <Layers3 size={17} />}{" "}
                {value === "auto"
                  ? "Auto Detect"
                  : value === "object"
                    ? "3D Model"
                    : "3D Architecture"}
              </button>
            ))}
          </div>
          <label>
            Tools / engine
            <select
              value={engine}
              disabled={busy}
              onChange={(e) => {
                setEngine(e.target.value);
                setEngineParams({});
              }}
            >
              <option value="auto">Auto Detect engine</option>
              {capabilities?.data.map((item) => (
                <option
                  key={item.id}
                  value={item.id}
                  disabled={!item.available}
                >
                  {item.name}
                  {item.available ? "" : " — unavailable"}
                </option>
              ))}
            </select>
          </label>
          {capabilities?.data
            .find((item) => item.id === engine)
            ?.parameters?.map((parameter) => (
              <label key={parameter.id}>
                {parameter.label}
                {parameter.options ? (
                  <select
                    value={String(
                      engineParams[parameter.id] ?? parameter.default,
                    )}
                    onChange={(event) =>
                      setEngineParams((old) => ({
                        ...old,
                        [parameter.id]: parameter.options?.find(
                          (option) =>
                            String(option.value) === event.target.value,
                        )?.value,
                      }))
                    }
                  >
                    {parameter.options.map((option) => (
                      <option key={option.value} value={option.value}>
                        {option.label}
                      </option>
                    ))}
                  </select>
                ) : (
                  <input
                    type="number"
                    min={parameter.min}
                    max={parameter.max}
                    value={Number(
                      engineParams[parameter.id] ?? parameter.default,
                    )}
                    onChange={(event) =>
                      setEngineParams((old) => ({
                        ...old,
                        [parameter.id]: Number(event.target.value),
                      }))
                    }
                  />
                )}
              </label>
            ))}
          <label htmlFor="scene-prompt">
            {mode === "object"
              ? "Describe your object, materials and proportions"
              : mode === "architecture"
                ? "Describe your services, layers and connections"
                : "Describe your 3D model or architecture"}
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
          <section className="studio-references" aria-label="Scene references">
            <label className="studio-upload-label">
              {uploading
                ? "Reading and saving references…"
                : "Upload references"}
              <input
                type="file"
                multiple
                accept=".md,.markdown,.txt,.csv,.json,.doc,.docx,.pdf,.png,.jpg,.jpeg,.webp"
                disabled={busy || loading || uploading}
                onChange={async (e) => {
                  const input = e.currentTarget;
                  const files = Array.from(input.files || []);
                  if (selectedUploads.length + files.length > 5) {
                    setError("Select up to five references.");
                    input.value = "";
                    return;
                  }
                  setUploading(true);
                  setError("");
                  try {
                    for (const file of files) {
                      const data = await prepareStudioUpload(file);
                      const saved: StudioReference = await studioApi(
                        "/uploads",
                        "POST",
                        data,
                      );
                      setReferences((old) => [saved, ...old]);
                      setSelectedUploads((old) => [...old, saved.id]);
                    }
                  } catch (error) {
                    setError(
                      error instanceof Error ? error.message : "Upload failed.",
                    );
                  } finally {
                    setUploading(false);
                    input.value = "";
                  }
                }}
              />
            </label>
            <small>
              MD, documents, PDF and images · up to 3 MB each · originals stay
              private
            </small>
            {references.length > 0 && (
              <details>
                <summary>
                  Saved references ({selectedUploads.length} selected)
                </summary>
                {references.map((file) => (
                  <div className="studio-reference-row" key={file.id}>
                    <label>
                      <input
                        type="checkbox"
                        disabled={busy || uploading}
                        checked={selectedUploads.includes(file.id)}
                        onChange={(e) => {
                          if (e.target.checked && selectedUploads.length >= 5) {
                            setError("Select up to five references.");
                            return;
                          }
                          setSelectedUploads((old) =>
                            e.target.checked
                              ? [...old, file.id]
                              : old.filter((id) => id !== file.id),
                          );
                        }}
                      />
                      {file.name}
                    </label>
                    <a
                      href={`/api/backend/3d-scenes/uploads/${file.id}`}
                      download
                    >
                      Download original
                    </a>
                  </div>
                ))}
              </details>
            )}
            {selectedUploads.length > 0 && (
              <p>
                {references
                  .filter((file) => selectedUploads.includes(file.id))
                  .map((file) => file.name)
                  .join(" · ")}
              </p>
            )}
          </section>
          <div className="studio-submit">
            <small>Procedural models · Multilingual prompts · GLB export</small>
            <button
              type="submit"
              disabled={
                busy || loading || uploading || prompt.trim().length < 5
              }
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
        {!saved && (
          <div className="studio-examples">
            {examples[mode === "auto" ? "object" : mode].map((text) => (
              <button
                disabled={busy}
                key={text}
                onClick={() => setPrompt(text)}
              >
                {text}
              </button>
            ))}
          </div>
        )}
        <Workbench
          capabilities={capabilities}
          jobId={jobId}
          selectedAsset={selectedAsset}
          onAsset={setSelectedAsset}
          onScene={(id) => {
            open(id);
            refresh().catch((e) => setError(e.message));
          }}
          onBusy={(value) => {
            pending.current = value;
            setBusy(value);
          }}
        />
      </section>
    </main>
  );
}
