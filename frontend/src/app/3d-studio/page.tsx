"use client";
import WorkspaceWelcome from "@/components/workspace-welcome";
import StudioFrame from "@/components/studio-frame";
import TypingIndicator from "@/components/typing-indicator";
import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import {
  Armchair,
  ArrowUp,
  Bot,
  Box,
  Cpu,
  Download,
  Layers3,
  Library,
  LoaderCircle,
  Network,
  Paperclip,
  Plus,
  SlidersHorizontal,
  SquarePen,
  Trash2,
  Wand2,
  X,
} from "lucide-react";
import { downloadScene, studioApi, type SavedScene } from "@/lib/studio-scene";
import { STUDIO_HANDOFF_KEY } from "@/lib/studio-intent";
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
type Mode = "auto" | "object" | "architecture";
const modes: { id: Mode; label: string; hint: string; icon: typeof Box }[] = [
  { id: "auto", label: "Auto detect", hint: "Pick model or architecture from your prompt", icon: Wand2 },
  { id: "object", label: "3D model", hint: "Objects, products and furniture", icon: Box },
  { id: "architecture", label: "3D architecture", hint: "Systems, services and data flows", icon: Layers3 },
];
const suggestions: { title: string; mode: Mode; icon: typeof Box; text: string }[] = [
  {
    title: "Delivery robot",
    mode: "object",
    icon: Bot,
    text: "A four-wheel delivery robot with a blue cargo compartment, front cameras and a green status light.",
  },
  {
    title: "Reading chair",
    mode: "object",
    icon: Armchair,
    text: "A modern reading chair with a curved back, wooden legs and a matching side table.",
  },
  {
    title: "Web app architecture",
    mode: "architecture",
    icon: Network,
    text: "Users → Next.js frontend → API gateway. Gateway connects to auth, Laravel and AI service. Laravel connects to PostgreSQL and Redis. AI service connects to hosted inference. A queue connects Laravel to workers.",
  },
  {
    title: "IoT pipeline",
    mode: "architecture",
    icon: Cpu,
    text: "Temperature sensors → IoT gateway → message broker → stream processor → time-series database → monitoring dashboard.",
  },
];
const signedOut = (message: string) => /sign in|unauthenticated/i.test(message);
export default function StudioPage() {
  const [mode, setMode] = useState<Mode>("auto"),
    [prompt, setPrompt] = useState(""),
    [saved, setSaved] = useState<SavedScene | null>(null),
    [history, setHistory] = useState<History[]>([]),
    [busy, setBusy] = useState(false),
    [loading, setLoading] = useState(false),
    [error, setError] = useState(""),
    [references, setReferences] = useState<StudioReference[]>([]),
    [selectedUploads, setSelectedUploads] = useState<number[]>([]),
    [uploading, setUploading] = useState(false),
    [forceNew, setForceNew] = useState(false);
  const [capabilities, setCapabilities] = useState<Capabilities | null>(null),
    [engine, setEngine] = useState("auto"),
    [engineParams, setEngineParams] = useState<Record<string, unknown>>({}),
    [jobId, setJobId] = useState<string | null>(null),
    [selectedAsset, setSelectedAsset] = useState<Asset | null>(null),
    [panel, setPanel] = useState<"create" | "assets">("create"),
    [interactive, setInteractive] = useState(false);
  const pending = useRef(false);
  const submission = useRef<{ payload: string; key: string } | null>(null);
  const fileRef = useRef<HTMLInputElement>(null);
  const toolsRef = useRef<HTMLDetailsElement>(null);
  const promptRef = useRef<HTMLTextAreaElement>(null);
  const handoffStart = useRef(false);
  async function refresh() {
    const [data, uploads] = await Promise.all([
      studioApi(),
      studioApi("/uploads"),
    ]);
    setHistory(data.data);
    setReferences(uploads.data);
  }
  // Signed-out visitors see the empty studio; the sidebar offers Log in.
  const reportLoad = (e: Error) => {
    if (!signedOut(e.message)) setError(e.message);
  };
  useEffect(() => {
    setInteractive(true);
    refresh().catch(reportLoad);
    workbenchApi("/engines").then(setCapabilities).catch(reportLoad);
    // A 3D request sent from chat starts here once, then the key is consumed.
    try {
      const handoff = sessionStorage.getItem(STUDIO_HANDOFF_KEY);
      sessionStorage.removeItem(STUDIO_HANDOFF_KEY);
      if (handoff && handoff.trim().length >= 5) {
        handoffStart.current = true;
        setPrompt(handoff.slice(0, 8000));
      }
    } catch { /* Storage unavailable: nothing was handed over. */ }
  }, []);
  useEffect(() => {
    if (!handoffStart.current || !prompt) return;
    handoffStart.current = false;
    void generate();
    // generate() reads the prompt from this render.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [prompt]);
  function closeTools() {
    toolsRef.current?.removeAttribute("open");
  }
  function newScene() {
    submission.current = null;
    setSaved(null);
    setSelectedAsset(null);
    setMode("auto");
    setPrompt("");
    setSelectedUploads([]);
    setError("");
    setPanel("create");
  }
  // With a scene open, the composer edits it; "New scene instead" opts out.
  const editing = Boolean(saved && !selectedAsset && !forceNew);
  async function generate(e?: React.FormEvent) {
    e?.preventDefault();
    if (pending.current || prompt.trim().length < 5) return;
    pending.current = true;
    setBusy(true);
    setError("");
    if (editing && saved) {
      try {
        const result: SavedScene = await studioApi(`/${saved.id}/edit`, "POST", {
          prompt,
          version: saved.version ?? 1,
        });
        setSaved(result);
        setPrompt("");
        await refresh();
      } catch (e) {
        setError(e instanceof Error ? e.message : "Could not update the scene.");
      } finally {
        pending.current = false;
        setBusy(false);
      }
      return;
    }
    const payload = JSON.stringify({
      mode,
      engine,
      prompt,
      selectedUploads,
      engineParams,
    });
    if (submission.current?.payload !== payload)
      submission.current = { payload, key: crypto.randomUUID() };
    const requestKey = submission.current.key;
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
          request_key: requestKey,
          mode: route.mode,
          prompt,
          upload_ids: selectedUploads,
        });
        if (result.scene) {
          const scene = result as SavedScene;
          setSaved(scene);
          setSelectedAsset(null);
          setMode(scene.mode);
          setPrompt("");
          setForceNew(false);
          pending.current = false;
          submission.current = null;
          await refresh();
          return;
        }
      } else {
        const image = references.find(
          (r) => selectedUploads.includes(r.id) && r.mime.startsWith("image/"),
        );
        if (!image)
          throw new Error("Add an image reference for this engine.");
        result = await workbenchApi("/jobs", "POST", {
          request_key: requestKey,
          engine: route.engine,
          operation: "generate",
          upload_id: image.id,
          params: engineParams,
        });
      }
      // Engine jobs report progress in the asset workbench.
      setJobId(result.id);
      setPanel("assets");
      submission.current = null;
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
    setPanel("create");
    try {
      const result: SavedScene = await studioApi(`/${id}`);
      setSaved(result);
      setSelectedAsset(null);
      setMode(result.mode);
      setPrompt("");
      setForceNew(false);
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
  async function remove(item: History) {
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
  }
  async function upload(files: File[]) {
    if (selectedUploads.length + files.length > 5) {
      setError("Add up to five references.");
      return;
    }
    setUploading(true);
    setError("");
    try {
      for (const file of files) {
        const data = await prepareStudioUpload(file);
        const stored: StudioReference = await studioApi("/uploads", "POST", data);
        setReferences((old) => [stored, ...old]);
        setSelectedUploads((old) => [...old, stored.id]);
      }
    } catch (e) {
      setError(e instanceof Error ? e.message : "Upload failed.");
    } finally {
      setUploading(false);
    }
  }
  const showingResult = Boolean(saved || selectedAsset);
  const activeMode = modes.find((m) => m.id === mode)!;
  const engineInfo = capabilities?.data.find((item) => item.id === engine);
  const selectedFiles = references.filter((file) =>
    selectedUploads.includes(file.id),
  );
  // One level up: an open mesh asset returns to its panel, the asset panel
  // returns to the scene view, and an open scene returns to the 3D Studio home.
  const back = selectedAsset
    ? { label: "Back", onClick: () => setSelectedAsset(null) }
    : panel === "assets"
      ? { label: "Back to 3D Studio", onClick: () => setPanel("create") }
      : saved
        ? { label: "Back to 3D Studio", onClick: newScene, disabled: busy || loading }
        : undefined;
  return (
    <StudioFrame
      active="3d"
      back={back}
      primary={{
        label: "New scene",
        icon: <SquarePen size={19} />,
        onClick: newScene,
        disabled: !interactive || busy || loading,
      }}
      topbarRight={
        saved && panel === "create" && !selectedAsset ? (
          <button
            className="icon-button"
            aria-label="Download scene JSON"
            title="Download scene JSON"
            onClick={() =>
              downloadScene(
                JSON.stringify(saved.scene, null, 2),
                "openmind-scene.json",
                "application/json",
              )
            }
          >
            <Download size={18} />
          </button>
        ) : undefined
      }
      sidebar={
        <>
          <nav className="sidebar-navigation studio-sidebar-tools" aria-label="3D Studio">
            <button
              className={`sidebar-action ${panel === "assets" ? "selected" : ""}`}
              aria-pressed={panel === "assets"}
              onClick={() => setPanel(panel === "assets" ? "create" : "assets")}
            >
              <Library size={19} />
              <span>Assets & workflows</span>
            </button>
          </nav>
          <div className="history-list">
            <span className="section-label">Your scenes</span>
            {!history.length ? (
              <p className="sidebar-empty">Generated scenes will appear here.</p>
            ) : (
              history.map((item) => (
                <div
                  key={item.id}
                  className={`history-item ${saved?.id === item.id && panel === "create" ? "active" : ""}`}
                >
                  <button
                    className="history-title"
                    title={item.title}
                    disabled={busy || loading}
                    onClick={() => open(item.id)}
                  >
                    {item.title}
                  </button>
                  <button
                    className="history-delete icon-button"
                    disabled={busy || loading}
                    aria-label={`Delete ${item.title}`}
                    onClick={() => void remove(item)}
                  >
                    <Trash2 size={15} />
                  </button>
                </div>
              ))
            )}
          </div>
        </>
      }
    >
      <div className={`studio-shell ${showingResult || panel === "assets" ? "" : "is-empty"}`}>
        {error && (
          <div role="alert" className="studio-error">
            <span>
              {error}
              {signedOut(error) && (
                <>
                  {" "}
                  <a href="/">Log in</a>
                </>
              )}
            </span>
            <button
              className="icon-button"
              aria-label="Dismiss error"
              onClick={() => setError("")}
            >
              <X size={15} />
            </button>
          </div>
        )}
        <section className="studio-assets" hidden={panel !== "assets"}>
          <header className="studio-page-header">
            <h1>Assets & workflows</h1>
            <p>Import meshes, run engine jobs and reuse your private 3D assets.</p>
          </header>
          {capabilities?.deployment?.scene_execution === "direct" &&
            !capabilities.worker.ready && (
              <p className="studio-muted">
                Procedural 3D and Architecture run here. Mesh generation and
                processing become available after a worker is connected.
              </p>
            )}
          <Workbench
            capabilities={capabilities}
            jobId={jobId}
            selectedAsset={selectedAsset}
            onAsset={(asset) => {
              setSelectedAsset(asset);
              if (asset) setPanel("create");
            }}
            onScene={(id) => {
              void open(id);
              refresh().catch(reportLoad);
            }}
            onBusy={(value) => {
              pending.current = value;
              setBusy(value);
            }}
          />
        </section>
        {panel === "create" && (
          <>
            {loading && (
              <p className="studio-muted" role="status">
                Loading your scene…
              </p>
            )}
            {selectedAsset ? (
              <div className="studio-result">
                <AssetViewport key={selectedAsset.id} asset={selectedAsset} />
              </div>
            ) : saved ? (
              <div className="studio-result">
                <header className="studio-page-header">
                  <h1>{saved.scene.title}</h1>
                  {saved.scene.description && <p>{saved.scene.description}</p>}
                </header>
                <Viewport
                  scene={saved.scene}
                  architecture={saved.mode === "architecture"}
                />
                <p className="studio-meta">
                  {saved.scene.nodes.length} parts · {saved.scene.edges.length}{" "}
                  connections
                </p>
                <details className="studio-edit">
                  <summary>Edit scene</summary>
                  <SceneEditor
                    key={saved.id}
                    saved={saved}
                    onChange={(scene) => setSaved({ ...saved, scene })}
                    onSaved={setSaved}
                  />
                </details>
              </div>
            ) : (
              <WorkspaceWelcome title="What would you like to create?" />
            )}
            <div className="studio-composer-area">
              <form className="composer" onSubmit={generate}>
                {saved && !selectedAsset && (
                  <div className="studio-edit-target">
                    <span>
                      {editing ? `Editing “${saved.scene.title}”` : "Creating a new scene"}
                    </span>
                    <button
                      type="button"
                      disabled={busy}
                      onClick={() => setForceNew(editing)}
                    >
                      {editing ? "New scene instead" : "Edit current scene"}
                    </button>
                  </div>
                )}
                <textarea
                  ref={promptRef}
                  aria-label="Describe your scene"
                  value={prompt}
                  onChange={(e) => setPrompt(e.target.value)}
                  minLength={5}
                  maxLength={8000}
                  rows={2}
                  disabled={!interactive || busy}
                  placeholder={
                    editing
                      ? "Describe what to change — e.g. make the body red and add an antenna"
                      : mode === "object"
                      ? "Describe an object, its materials and proportions"
                      : mode === "architecture"
                        ? "Describe services, layers and connections"
                        : saved
                          ? "Describe another model or architecture"
                          : "Describe a 3D model or architecture"
                  }
                  onKeyDown={(e) => {
                    if (e.key === "Enter" && !e.shiftKey && !e.nativeEvent.isComposing) {
                      e.preventDefault();
                      void generate();
                    }
                  }}
                />
                <input
                  ref={fileRef}
                  type="file"
                  multiple
                  hidden
                  accept=".md,.markdown,.txt,.csv,.json,.doc,.docx,.pdf,.png,.jpg,.jpeg,.webp"
                  onChange={(e) => {
                    const files = Array.from(e.currentTarget.files || []);
                    e.currentTarget.value = "";
                    if (files.length) void upload(files);
                  }}
                />
                {selectedFiles.length > 0 && (
                  <div className="attachment-chips" aria-label="References for this scene">
                    {selectedFiles.map((file) => (
                      <span key={file.id}>
                        <Paperclip size={12} />
                        {file.name}
                        <button
                          type="button"
                          aria-label={`Remove ${file.name}`}
                          disabled={busy}
                          onClick={() =>
                            setSelectedUploads((old) =>
                              old.filter((id) => id !== file.id),
                            )
                          }
                        >
                          <X size={13} />
                        </button>
                      </span>
                    ))}
                  </div>
                )}
                {busy && (
                  <p className="muted tool-progress" role="status" aria-live="polite">
                    <TypingIndicator label={editing ? "Updating your scene" : "Building your scene — this can take a few minutes"} />
                  </p>
                )}
                <div className="composer-toolbar">
                  <button
                    type="button"
                    className="icon-button"
                    disabled={!interactive || busy || loading || uploading}
                    aria-label="Add reference files"
                    title="Add references: documents, PDF or images up to 3 MB"
                    onClick={() => fileRef.current?.click()}
                  >
                    {uploading ? (
                      <LoaderCircle size={17} className="spin" />
                    ) : (
                      <Plus size={22} strokeWidth={1.8} />
                    )}
                  </button>
                  <details className="composer-tools" ref={toolsRef}>
                    <summary>
                      <SlidersHorizontal size={17} />
                      <span>Tools</span>
                    </summary>
                    <div className="studio-tools-menu" role="group" aria-label="Scene tools">
                      <p className="popover-heading">Scene type</p>
                      {modes.map((item) => (
                        <button
                          type="button"
                          key={item.id}
                          className={`welcome-tool-item ${mode === item.id ? "is-active" : ""}`}
                          aria-pressed={mode === item.id}
                          disabled={busy}
                          onClick={() => {
                            setMode(item.id);
                            closeTools();
                            promptRef.current?.focus();
                          }}
                        >
                          <item.icon size={20} />
                          <span>
                            <strong>{item.label}</strong>
                            <small>{item.hint}</small>
                          </span>
                        </button>
                      ))}
                      <div className="welcome-tools-divider" />
                      <label className="welcome-tools-select">
                        Engine
                        <select
                          value={engine}
                          disabled={busy}
                          onChange={(e) => {
                            setEngine(e.target.value);
                            setEngineParams({});
                          }}
                        >
                          <option value="auto">Auto (recommended)</option>
                          {capabilities?.data
                            .filter((item) => item.available)
                            .map((item) => (
                              <option key={item.id} value={item.id}>
                                {item.name}
                              </option>
                            ))}
                        </select>
                      </label>
                      {engineInfo?.parameters?.map((parameter) => (
                        <label className="welcome-tools-select" key={parameter.id}>
                          {parameter.label}
                          {parameter.options ? (
                            <select
                              value={String(engineParams[parameter.id] ?? parameter.default)}
                              onChange={(event) =>
                                setEngineParams((old) => ({
                                  ...old,
                                  [parameter.id]: parameter.options?.find(
                                    (option) => String(option.value) === event.target.value,
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
                              value={Number(engineParams[parameter.id] ?? parameter.default)}
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
                      {references.length > 0 && (
                        <>
                          <div className="welcome-tools-divider" />
                          <p className="popover-heading">
                            Saved references · {selectedUploads.length} of 5 selected
                          </p>
                          <div className="studio-reference-list">
                            {references.map((file) => (
                              <label className="studio-reference-row" key={file.id}>
                                <input
                                  type="checkbox"
                                  disabled={busy || uploading}
                                  checked={selectedUploads.includes(file.id)}
                                  onChange={(e) => {
                                    if (e.target.checked && selectedUploads.length >= 5) {
                                      setError("Add up to five references.");
                                      return;
                                    }
                                    setSelectedUploads((old) =>
                                      e.target.checked
                                        ? [...old, file.id]
                                        : old.filter((id) => id !== file.id),
                                    );
                                  }}
                                />
                                <span>{file.name}</span>
                                <a
                                  href={`/api/backend/3d-scenes/uploads/${file.id}`}
                                  download
                                  aria-label={`Download ${file.name}`}
                                >
                                  <Download size={14} />
                                </a>
                              </label>
                            ))}
                          </div>
                        </>
                      )}
                    </div>
                  </details>
                  {(mode !== "auto" || engine !== "auto") && (
                    <span className="composer-active-mode">
                      {[mode !== "auto" ? activeMode.label : "", engine !== "auto" ? engineInfo?.name : ""]
                        .filter(Boolean)
                        .join(" · ")}
                    </span>
                  )}
                  <div className="composer-right">
                    <button
                      type="submit"
                      className="send-button"
                      aria-label={editing ? "Apply changes to scene" : "Generate scene"}
                      disabled={
                        !interactive ||
                        busy ||
                        loading ||
                        uploading ||
                        prompt.trim().length < 5
                      }
                    >
                      {busy ? (
                        <LoaderCircle size={19} className="spin" />
                      ) : (
                        <ArrowUp size={20} strokeWidth={2.5} />
                      )}
                    </button>
                  </div>
                </div>
              </form>
              {!showingResult && (
                <div className="suggestions" aria-label="Try a prompt">
                  {suggestions.map((item) => (
                    <button
                      key={item.title}
                      disabled={!interactive || busy}
                      onClick={() => {
                        setMode(item.mode);
                        setPrompt(item.text);
                        promptRef.current?.focus();
                      }}
                    >
                      <item.icon size={18} strokeWidth={1.6} />
                      <span>{item.title}</span>
                    </button>
                  ))}
                </div>
              )}
              <p className="composer-note">
                Each generation is saved to Your scenes. Models use basic shapes;
                dimensions are illustrative.
              </p>
            </div>
          </>
        )}
      </div>
    </StudioFrame>
  );
}
