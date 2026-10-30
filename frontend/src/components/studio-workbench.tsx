"use client";
import { useCallback, useEffect, useRef, useState } from "react";
import {
  workbenchApi,
  encodeBytes,
  type Asset,
  type Job,
  type Capabilities,
} from "@/lib/studio-workbench";
import WorkflowEditor from "@/components/studio-workflow-editor";
export default function Workbench({
  capabilities,
  jobId,
  selectedAsset,
  onAsset,
  onScene,
  onBusy,
}: {
  capabilities: Capabilities | null;
  jobId: string | null;
  selectedAsset: Asset | null;
  onAsset: (asset: Asset | null) => void;
  onScene: (id: number) => void;
  onBusy: (busy: boolean) => void;
}) {
  const [tab, setTab] = useState("library"),
    [assets, setAssets] = useState<Asset[]>([]),
    [trash, setTrash] = useState<Asset[]>([]),
    [jobs, setJobs] = useState<Job[]>([]),
    [tracked, setTracked] = useState<string | null>(null),
    [error, setError] = useState(""),
    [working, setWorking] = useState(false),
    [operation, setOperation] = useState("transform"),
    [ratio, setRatio] = useState(0.5),
    [iterations, setIterations] = useState(5),
    [format, setFormat] = useState("glb"),
    [position, setPosition] = useState([0, 0, 0]),
    [rotation, setRotation] = useState([0, 0, 0]),
    [scale, setScale] = useState([1, 1, 1]),
    [undo, setUndo] = useState<number[]>([]),
    [redo, setRedo] = useState<number[]>([]),
    [project, setProject] = useState(""),
    [projects, setProjects] = useState<{ id: number; title: string }[]>([]),
    [projectTitle, setProjectTitle] = useState("");
  const completed = useRef(new Set<string>());
  const refresh = useCallback(async () => {
    const [a, j, p, deleted] = await Promise.all([
      workbenchApi("/assets"),
      workbenchApi("/jobs"),
      workbenchApi("/projects"),
      workbenchApi("/assets?trash=1"),
    ]);
    setAssets(a.data);
    setTrash(deleted.data);
    setJobs(j.data);
    setProjects(p.data);
    return { assets: a.data as Asset[], jobs: j.data as Job[] };
  }, []);
  useEffect(() => {
    refresh().catch((e) =>
      setError(e instanceof Error ? e.message : "Workbench unavailable."),
    );
  }, [refresh]);
  useEffect(() => {
    if (jobId) setTracked(jobId);
  }, [jobId]);
  const onAssetRef = useRef(onAsset),
    onSceneRef = useRef(onScene),
    onBusyRef = useRef(onBusy);
  onAssetRef.current = onAsset;
  onSceneRef.current = onScene;
  onBusyRef.current = onBusy;
  useEffect(() => {
    if (!tracked) return;
    let stopped = false;
    let timeout: ReturnType<typeof setTimeout>;
    onBusyRef.current(true);
    const poll = async () => {
      try {
        const detail: Job = await workbenchApi(`/jobs/${tracked}`);
        if (stopped) return;
        setJobs((old) => [detail, ...old.filter((j) => j.id !== detail.id)]);
        if (["completed", "failed", "cancelled"].includes(detail.status)) {
          onBusyRef.current(false);
          if (detail.status === "failed")
            setError(
              `Job failed: ${detail.error_code || "operation unavailable"}.`,
            );
          if (
            detail.status === "completed" &&
            !completed.current.has(detail.id)
          ) {
            completed.current.add(detail.id);
            const lists = await refresh();
            const result = detail.result as
              { asset_id?: number; scene_id?: number } | undefined;
            if (result?.scene_id) onSceneRef.current(result.scene_id);
            else if (result?.asset_id) {
              const asset = lists.assets.find((a) => a.id === result.asset_id);
              if (asset) onAssetRef.current(asset);
            }
          }
          setTracked(null);
          return;
        }
      } catch (e) {
        if (!stopped)
          setError(
            e instanceof Error
              ? e.message
              : "Connection interrupted. Job remains saved.",
          );
      }
      if (!stopped) timeout = setTimeout(poll, 2500);
    };
    poll();
    return () => {
      stopped = true;
      clearTimeout(timeout);
    };
  }, [tracked, refresh]);
  function select(asset: Asset) {
    if (selectedAsset) setUndo((old) => [...old, selectedAsset.id]);
    setRedo([]);
    onAsset(asset);
  }
  async function submit(operation: string, params: Record<string, unknown>) {
    if (!selectedAsset) return;
    setWorking(true);
    setError("");
    try {
      const job = await workbenchApi("/jobs", "POST", {
        request_key: crypto.randomUUID(),
        engine: "cpu",
        operation,
        asset_id: selectedAsset.id,
        project_id: project ? Number(project) : null,
        params,
      });
      setTracked(job.id);
      setTab("jobs");
    } catch (e) {
      setError(e instanceof Error ? e.message : "Operation failed.");
    } finally {
      setWorking(false);
    }
  }
  const current = jobs.find((j) => j.id === tracked);
  return (
    <section className="studio-workbench" aria-label="3D workbench">
      <div
        className="studio-tool-row"
        role="tablist"
        aria-label="Workbench panels"
      >
        {["library", "workflow", "jobs", "models"].map((name) => (
          <button
            key={name}
            type="button"
            role="tab"
            aria-selected={tab === name}
            onClick={() => setTab(name)}
          >
            {name === "library"
              ? "Asset library"
              : name === "workflow"
                ? "Workflow editor"
                : name === "jobs"
                  ? "Job history"
                  : "Models & hardware"}
          </button>
        ))}
      </div>
      {error && <p role="alert">{error}</p>}
      {tracked && (
        <div role="status">
          <p>
            {current?.status === "cancel_requested"
              ? "Cancellation requested; waiting for the worker."
              : `Saved job: ${current?.status || "queued"}`}
          </p>
          <button
            type="button"
            disabled={current?.status === "cancel_requested"}
            onClick={async () => {
              try {
                await workbenchApi(`/jobs/${tracked}/cancel`, "POST", {});
              } catch (e) {
                setError(String(e));
              }
            }}
          >
            Cancel job
          </button>
        </div>
      )}
      {tab === "library" && (
        <>
          <h3>Your private 3D assets</h3>
          <div className="studio-tool-row">
            <label>
              Project
              <select
                value={project}
                onChange={(e) => setProject(e.target.value)}
              >
                <option value="">All projects</option>
                {projects.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.title}
                  </option>
                ))}
              </select>
            </label>
            <label>
              New project
              <input
                maxLength={160}
                value={projectTitle}
                onChange={(e) => setProjectTitle(e.target.value)}
              />
            </label>
            <button
              type="button"
              disabled={!projectTitle.trim()}
              onClick={async () => {
                try {
                  const p = await workbenchApi("/projects", "POST", {
                    title: projectTitle,
                  });
                  setProjects((old) => [p, ...old]);
                  setProject(String(p.id));
                  setProjectTitle("");
                } catch (e) {
                  setError(String(e));
                }
              }}
            >
              Create project
            </button>
          </div>
          <label>
            Import a mesh
            <input
              type="file"
              accept=".glb,.gltf,.obj,.stl,.ply,.json"
              disabled={working || !capabilities?.worker.ready}
              onChange={async (e) => {
                const input = e.currentTarget,
                  file = input.files?.[0];
                if (!file) return;
                setWorking(true);
                setError("");
                try {
                  if (!file.size || file.size > 3_000_000)
                    throw new Error("Import a self-contained mesh up to 3 MB.");
                  const job = await workbenchApi("/assets", "POST", {
                    name: file.name,
                    data: encodeBytes(new Uint8Array(await file.arrayBuffer())),
                    request_key: crypto.randomUUID(),
                    project_id: project ? Number(project) : null,
                  });
                  setTracked(job.id);
                } catch (e) {
                  setError(e instanceof Error ? e.message : "Import failed.");
                } finally {
                  setWorking(false);
                  input.value = "";
                }
              }}
            />
          </label>
          <small>
            GLB/GLTF/OBJ/STL/PLY and self-contained scene manifests. External
            resource files are rejected; originals stay private.
          </small>
          {!capabilities?.worker.ready && (
            <p>
              Mesh import and processing require a connected isolated worker.
              Existing procedural scenes remain available.
            </p>
          )}
          <div className="studio-asset-list">
            {assets
              .filter(
                (a) =>
                  a.kind !== "source_mesh" &&
                  (!project || a.project_id === Number(project)),
              )
              .map((a) => (
                <button
                  type="button"
                  key={a.id}
                  aria-pressed={selectedAsset?.id === a.id}
                  onClick={() => select(a)}
                >
                  {a.name}
                  <small>
                    {a.format.toUpperCase()} · {Math.ceil(a.bytes / 1024)} KB
                    {a.parent_id ? ` · version of #${a.parent_id}` : ""}
                  </small>
                </button>
              ))}
          </div>
          <details>
            <summary>Trash ({trash.length})</summary>
            {trash.map((asset) => (
              <p key={asset.id}>
                {asset.name}{" "}
                <button
                  type="button"
                  onClick={async () => {
                    try {
                      await workbenchApi(
                        `/assets/${asset.id}/restore`,
                        "POST",
                        {},
                      );
                      await refresh();
                    } catch (e) {
                      setError(String(e));
                    }
                  }}
                >
                  Restore
                </button>
              </p>
            ))}
          </details>
          {selectedAsset && (
            <>
              <h4>Selected asset: {selectedAsset.name}</h4>
              <div className="studio-tool-row">
                <button
                  type="button"
                  disabled={!undo.length && !selectedAsset.parent_id}
                  onClick={() => {
                    const id = undo.at(-1) || selectedAsset.parent_id!,
                      asset = assets.find((a) => a.id === id);
                    if (asset) {
                      setRedo((r) => [...r, selectedAsset.id]);
                      setUndo(undo.slice(0, -1));
                      onAsset(asset);
                    }
                  }}
                >
                  Previous version
                </button>
                <button
                  type="button"
                  disabled={!redo.length}
                  onClick={() => {
                    const id = redo.at(-1)!,
                      asset = assets.find((a) => a.id === id);
                    if (asset) {
                      setUndo((r) => [...r, selectedAsset.id]);
                      setRedo(redo.slice(0, -1));
                      onAsset(asset);
                    }
                  }}
                >
                  Next version
                </button>
                <a
                  href={`/api/backend/3d-studio/assets/${selectedAsset.id}/download`}
                  download
                >
                  Download original artifact
                </a>
                <button
                  type="button"
                  onClick={async () => {
                    try {
                      await workbenchApi(
                        `/assets/${selectedAsset.id}`,
                        "DELETE",
                      );
                      onAsset(null);
                      await refresh();
                    } catch (e) {
                      setError(String(e));
                    }
                  }}
                >
                  Move to trash
                </button>
              </div>
              <label>
                Mesh tool
                <select
                  value={operation}
                  onChange={(e) => setOperation(e.target.value)}
                >
                  <option value="transform">Move / rotate / scale</option>
                  <option value="smooth">Smooth mesh</option>
                  <option value="decimate">Reduce polygons</option>
                  <option value="repair">Repair normals / holes</option>
                </select>
              </label>
              {operation === "transform" &&
                (["position", "rotation", "scale"] as const).map((key) => (
                  <fieldset key={key}>
                    <legend>
                      {key}
                      {key === "rotation" ? " (radians)" : ""}
                    </legend>
                    <div className="studio-vector">
                      {["X", "Y", "Z"].map((axis, i) => (
                        <label key={axis}>
                          {axis}
                          <input
                            type="number"
                            step="0.1"
                            min={key === "scale" ? 0.001 : undefined}
                            value={{ position, rotation, scale }[key][i]}
                            onChange={(e) => {
                              const setters = {
                                position: setPosition,
                                rotation: setRotation,
                                scale: setScale,
                              };
                              setters[key]((old) =>
                                old.map((v, n) =>
                                  n === i ? Number(e.target.value) : v,
                                ),
                              );
                            }}
                          />
                        </label>
                      ))}
                    </div>
                  </fieldset>
                ))}
              {operation === "smooth" && (
                <label>
                  Smoothing passes
                  <input
                    type="number"
                    min={1}
                    max={50}
                    value={iterations}
                    onChange={(e) => setIterations(Number(e.target.value))}
                  />
                </label>
              )}
              {operation === "decimate" && (
                <label>
                  Retained face ratio
                  <input
                    type="number"
                    min={0.05}
                    max={0.95}
                    step={0.05}
                    value={ratio}
                    onChange={(e) => setRatio(Number(e.target.value))}
                  />
                </label>
              )}
              <button
                type="button"
                disabled={working || !!tracked || !capabilities?.worker.ready}
                onClick={() =>
                  submit(
                    operation,
                    operation === "transform"
                      ? { position, rotation, scale }
                      : operation === "smooth"
                        ? { iterations }
                        : operation === "decimate"
                          ? { ratio }
                          : {},
                  )
                }
              >
                Apply as new mesh version
              </button>
              <div className="studio-tool-row">
                <label>
                  Export format
                  <select
                    value={format}
                    onChange={(e) => setFormat(e.target.value)}
                  >
                    {["glb", "gltf", "obj", "stl", "ply", "json"].map((f) => (
                      <option key={f} value={f}>
                        {f.toUpperCase()}
                      </option>
                    ))}
                  </select>
                </label>
                <button
                  type="button"
                  disabled={working || !!tracked || !capabilities?.worker.ready}
                  onClick={() => submit("export", { format })}
                >
                  Create verified export
                </button>
              </div>
              <small>
                Processing creates a saved version and preserves the source. STL
                has no materials; OBJ/STL/PLY flatten scene hierarchy. Smoothing
                and repair may merge objects or discard UV textures.
              </small>
            </>
          )}
        </>
      )}
      {tab === "workflow" && (
        <WorkflowEditor
          capabilities={capabilities}
          onRun={(id) => {
            setTracked(id);
            setTab("jobs");
          }}
        />
      )}
      {tab === "jobs" && (
        <>
          <h3>Actual execution history</h3>
          <button
            type="button"
            onClick={() => refresh().catch((e) => setError(String(e)))}
          >
            Refresh jobs
          </button>
          {jobs.length === 0 && <p>No jobs yet.</p>}
          {jobs.map((j) => (
            <details key={j.id} open={j.id === tracked}>
              <summary>
                {j.operation} · {j.status} · {j.engine}
              </summary>
              <p>Job {j.id}</p>
              {j.error_code && <p>{j.error_code}</p>}
              {j.events?.map((e) => (
                <p key={e.id}>
                  {e.created_at}: {e.type}
                </p>
              ))}
              {j.result && (
                <small>
                  Output is saved privately. Select its asset in the library.
                </small>
              )}
            </details>
          ))}
        </>
      )}
      {tab === "models" && (
        <>
          <h3>Engines and hardware</h3>
          <p>
            Mesh worker:{" "}
            {capabilities?.worker.ready
              ? "Connected"
              : "Not verified / not connected"}
          </p>
          {capabilities?.data.map((e) => (
            <article key={e.id}>
              <h4>
                {e.name}
                {e.version ? ` · ${e.version}` : ""}
              </h4>
              <p>
                {e.available ? "Available" : "Unavailable"} · {e.input} →{" "}
                {e.output}
                {e.vram_gb
                  ? ` · upstream VRAM requirement: ${e.vram_gb} GB`
                  : ""}
              </p>
              {!e.available && (
                <small>
                  Requires an approved installation and a real capability check.
                  GPU models also require compatible hardware and verified
                  weights.
                </small>
              )}
            </article>
          ))}
          <small>
            Source and model installations are administrator-controlled. No
            extension code is downloaded or executed by ordinary users.
          </small>
        </>
      )}
    </section>
  );
}
