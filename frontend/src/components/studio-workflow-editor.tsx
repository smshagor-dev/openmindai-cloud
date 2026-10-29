"use client";
import { useEffect, useRef, useState } from "react";
import { studioApi } from "@/lib/studio-scene";
import {
  workbenchApi,
  type Asset,
  type Capabilities,
} from "@/lib/studio-workbench";
type Node = { id: string; type: string; params: Record<string, any> };
type Graph = { nodes: Node[]; edges: { from: string; to: string }[] };
const ports: Record<string, [string | null, string]> = {
  prompt: [null, "text"],
  image: [null, "image"],
  mesh: [null, "mesh"],
  scene: [null, "scene"],
  preprocess: ["image", "image"],
  generate: ["image", "mesh"],
  generate_scene: ["text", "scene"],
  process: ["mesh", "mesh"],
  preview: ["geometry", "geometry"],
  add_to_scene: ["geometry", "scene"],
  export: ["geometry", "artifact"],
};
const names: Record<string, string> = {
  prompt: "Prompt input",
  image: "Image input",
  mesh: "Import mesh",
  scene: "Import scene",
  preprocess: "Image preprocessing",
  generate: "Generate mesh",
  generate_scene: "Generate procedural scene",
  process: "Mesh processing",
  preview: "Preview",
  add_to_scene: "Add model to scene",
  export: "Export artifact",
};
const textTemplate: Graph = {
  nodes: [
    {
      id: "prompt",
      type: "prompt",
      params: { text: "A delivery robot with four wheels" },
    },
    { id: "scene", type: "generate_scene", params: { mode: "object" } },
    { id: "preview", type: "preview", params: {} },
  ],
  edges: [
    { from: "prompt", to: "scene" },
    { from: "scene", to: "preview" },
  ],
};
export default function WorkflowEditor({
  capabilities,
  onRun,
}: {
  capabilities: Capabilities | null;
  onRun: (id: string) => void;
}) {
  const [graph, setGraph] = useState<Graph>(structuredClone(textTemplate)),
    [title, setTitle] = useState("My 3D workflow"),
    [savedId, setSavedId] = useState<number | null>(null),
    [version, setVersion] = useState(1),
    [saved, setSaved] = useState<
      { id: number; title: string; graph: string; version: number }[]
    >([]),
    [assets, setAssets] = useState<Asset[]>([]),
    [images, setImages] = useState<
      { id: number; name: string; mime: string }[]
    >([]),
    [newType, setNewType] = useState("prompt"),
    [from, setFrom] = useState(""),
    [to, setTo] = useState(""),
    [error, setError] = useState(""),
    [busy, setBusy] = useState(false),
    [paths, setPaths] = useState<string[]>([]);
  const canvas = useRef<HTMLDivElement>(null),
    cards = useRef<Record<string, HTMLElement | null>>({});
  useEffect(() => {
    Promise.all([
      workbenchApi("/workflows"),
      workbenchApi("/assets"),
      studioApi("/uploads"),
    ])
      .then(([w, a, i]) => {
        setSaved(w.data);
        setAssets(
          a.data.filter((x: Asset) => x.kind === "mesh" || x.kind === "scene"),
        );
        setImages(
          i.data.filter((x: { mime: string }) => x.mime.startsWith("image/")),
        );
      })
      .catch((e) => setError(e.message));
  }, []);
  useEffect(() => {
    const root = canvas.current;
    if (!root) return;
    const measure = () => {
      const box = root.getBoundingClientRect();
      setPaths(
        graph.edges.map((edge) => {
          const a = cards.current[edge.from]?.getBoundingClientRect(),
            b = cards.current[edge.to]?.getBoundingClientRect();
          if (!a || !b) return "";
          const x1 = a.right - box.left,
            y1 = a.top - box.top + a.height / 2,
            x2 = b.left - box.left,
            y2 = b.top - box.top + b.height / 2;
          return `M ${x1} ${y1} C ${x1 + 30} ${y1}, ${x2 - 30} ${y2}, ${x2} ${y2}`;
        }),
      );
    };
    const observer = new ResizeObserver(measure);
    observer.observe(root);
    for (const node of graph.nodes) {
      const card = cards.current[node.id];
      if (card) observer.observe(card);
    }
    measure();
    return () => observer.disconnect();
  }, [graph]);
  function patch(id: string, value: Record<string, unknown>) {
    setGraph((g) => ({
      ...g,
      nodes: g.nodes.map((n) =>
        n.id === id ? { ...n, params: { ...n.params, ...value } } : n,
      ),
    }));
  }
  function add() {
    const defaults: Record<string, Record<string, unknown>> = {
      prompt: { text: "" },
      image: { upload_id: images[0]?.id || 0 },
      mesh: { asset_id: assets.find((a) => a.kind === "mesh")?.id || 0 },
      scene: { asset_id: assets.find((a) => a.kind === "scene")?.id || 0 },
      preprocess: { size: 768 },
      generate: {
        engine:
          capabilities?.data.find((e) => e.kind === "model")?.id ||
          "hunyuan3d-mini",
        options: {},
      },
      generate_scene: { mode: "object" },
      process: { operation: "smooth", options: { iterations: 5 } },
      add_to_scene: { asset_ids: [] },
      export: { format: "glb" },
      preview: {},
    };
    setGraph((g) => ({
      ...g,
      nodes: [
        ...g.nodes,
        {
          id: "n_" + crypto.randomUUID(),
          type: newType,
          params: defaults[newType],
        },
      ],
    }));
  }
  function connect() {
    setError("");
    const a = graph.nodes.find((n) => n.id === from),
      b = graph.nodes.find((n) => n.id === to);
    if (!a || !b || a === b) {
      setError("Select two different nodes.");
      return;
    }
    const input = ports[b.type][0],
      output = ports[a.type][1];
    if (
      !input ||
      (input !== output &&
        !(
          input === "geometry" && ["mesh", "scene", "geometry"].includes(output)
        ))
    ) {
      setError("These port types do not match.");
      return;
    }
    if (graph.edges.some((e) => e.to === to)) {
      setError("Disconnect the existing input first.");
      return;
    }
    const edges = [...graph.edges, { from, to }];
    const visited = new Set<string>();
    function reaches(id: string): boolean {
      if (id === from) return true;
      if (visited.has(id)) return false;
      visited.add(id);
      return edges.filter((e) => e.from === id).some((e) => reaches(e.to));
    }
    if (reaches(to)) {
      setError("This connection would create a cycle.");
      return;
    }
    setGraph((g) => ({ ...g, edges }));
  }
  async function save() {
    setBusy(true);
    setError("");
    try {
      const result = await workbenchApi(
        savedId ? `/workflows/${savedId}` : "/workflows",
        savedId ? "PUT" : "POST",
        { title, graph, version },
      );
      setSavedId(result.id);
      setVersion(result.version);
      setSaved((old) => [result, ...old.filter((w) => w.id !== result.id)]);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Save failed.");
    } finally {
      setBusy(false);
    }
  }
  async function run() {
    setBusy(true);
    setError("");
    try {
      const job = await workbenchApi("/workflows/run", "POST", {
        graph,
        request_key: crypto.randomUUID(),
      });
      onRun(job.id);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Workflow failed validation.");
    } finally {
      setBusy(false);
    }
  }
  function move(index: number, direction: number) {
    const nodes = [...graph.nodes];
    const target = index + direction;
    if (target < 0 || target >= nodes.length) return;
    [nodes[index], nodes[target]] = [nodes[target], nodes[index]];
    setGraph({ ...graph, nodes });
  }
  return (
    <section aria-label="Typed workflow editor">
      <h3>Build a reusable workflow</h3>
      <div className="studio-tool-row">
        <label>
          Workflow name
          <input
            value={title}
            maxLength={160}
            onChange={(e) => setTitle(e.target.value)}
          />
        </label>
        <label>
          Load workflow
          <select
            value={savedId || ""}
            onChange={(e) => {
              const item = saved.find((w) => w.id === Number(e.target.value));
              if (item) {
                setGraph(
                  typeof item.graph === "string"
                    ? JSON.parse(item.graph)
                    : item.graph,
                );
                setTitle(item.title);
                setSavedId(item.id);
                setVersion(item.version);
              }
            }}
          >
            <option value="">Unsaved workflow</option>
            {saved.map((w) => (
              <option key={w.id} value={w.id}>
                {w.title} · v{w.version}
              </option>
            ))}
          </select>
        </label>
        <button type="button" disabled={busy} onClick={save}>
          Save workflow
        </button>
        <button type="button" disabled={busy} onClick={run}>
          Run validated workflow
        </button>
      </div>
      <div className="studio-tool-row">
        <button
          type="button"
          onClick={() => {
            setGraph(structuredClone(textTemplate));
            setSavedId(null);
          }}
        >
          Text → scene template
        </button>
        <button
          type="button"
          onClick={() => {
            setGraph({
              nodes: [
                {
                  id: "mesh",
                  type: "mesh",
                  params: {
                    asset_id: assets.find((a) => a.kind === "mesh")?.id || 0,
                  },
                },
                {
                  id: "smooth",
                  type: "process",
                  params: { operation: "smooth", options: { iterations: 5 } },
                },
                { id: "export", type: "export", params: { format: "glb" } },
              ],
              edges: [
                { from: "mesh", to: "smooth" },
                { from: "smooth", to: "export" },
              ],
            });
            setSavedId(null);
          }}
        >
          Mesh → smooth → export
        </button>
        <label>
          Add node
          <select value={newType} onChange={(e) => setNewType(e.target.value)}>
            {Object.keys(ports).map((type) => (
              <option key={type} value={type}>
                {names[type]}
              </option>
            ))}
          </select>
        </label>
        <button type="button" disabled={graph.nodes.length >= 40} onClick={add}>
          Add workflow node
        </button>
      </div>
      {error && <p role="alert">{error}</p>}
      <small>
        Connections carry typed artifacts. Cycles and disconnected required
        inputs are rejected before execution. Progress comes from persisted
        node/job states.
      </small>
      <div className="studio-workflow-canvas" ref={canvas}>
        <svg aria-hidden="true" className="studio-workflow-lines">
          <defs>
            <marker
              id="workflow-arrow"
              viewBox="0 0 10 10"
              refX="9"
              refY="5"
              markerWidth="6"
              markerHeight="6"
              orient="auto-start-reverse"
            >
              <path d="M 0 0 L 10 5 L 0 10 z" fill="#60a5fa" />
            </marker>
          </defs>
          {paths.map((path, i) => (
            <path
              key={i}
              d={path}
              stroke="#60a5fa"
              strokeWidth="2"
              fill="none"
              markerEnd="url(#workflow-arrow)"
            />
          ))}
        </svg>
        {graph.nodes.map((node, index) => (
          <article
            className="studio-workflow-node"
            key={node.id}
            ref={(el) => {
              cards.current[node.id] = el;
            }}
          >
            <h4>{names[node.type]}</h4>
            <small>
              {ports[node.type][0] || "Input source"} → {ports[node.type][1]}
            </small>
            {node.type === "prompt" && (
              <label>
                Prompt
                <textarea
                  value={node.params.text}
                  maxLength={8000}
                  onChange={(e) => patch(node.id, { text: e.target.value })}
                />
              </label>
            )}
            {node.type === "image" && (
              <label>
                Private image
                <select
                  value={node.params.upload_id}
                  onChange={(e) =>
                    patch(node.id, { upload_id: Number(e.target.value) })
                  }
                >
                  <option value={0}>Choose image</option>
                  {images.map((i) => (
                    <option key={i.id} value={i.id}>
                      {i.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {["mesh", "scene"].includes(node.type) && (
              <label>
                Private asset
                <select
                  value={node.params.asset_id}
                  onChange={(e) =>
                    patch(node.id, { asset_id: Number(e.target.value) })
                  }
                >
                  <option value={0}>Choose asset</option>
                  {assets
                    .filter((a) => a.kind === node.type)
                    .map((a) => (
                      <option key={a.id} value={a.id}>
                        {a.name}
                      </option>
                    ))}
                </select>
              </label>
            )}
            {node.type === "generate_scene" && (
              <label>
                Mode
                <select
                  value={node.params.mode}
                  onChange={(e) => patch(node.id, { mode: e.target.value })}
                >
                  <option value="object">3D Model</option>
                  <option value="architecture">3D Architecture</option>
                </select>
              </label>
            )}
            {node.type === "generate" && (
              <label>
                Mesh engine
                <select
                  value={node.params.engine}
                  onChange={(e) =>
                    patch(node.id, { engine: e.target.value, options: {} })
                  }
                >
                  {capabilities?.data
                    .filter((e) => e.kind === "model")
                    .map((e) => (
                      <option key={e.id} value={e.id} disabled={!e.available}>
                        {e.name}
                        {e.available ? "" : " · unavailable"}
                      </option>
                    ))}
                </select>
              </label>
            )}
            {node.type === "preprocess" && (
              <label>
                Maximum image dimension
                <input
                  type="number"
                  min={64}
                  max={1024}
                  value={node.params.size}
                  onChange={(e) =>
                    patch(node.id, { size: Number(e.target.value) })
                  }
                />
              </label>
            )}
            {node.type === "process" && (
              <>
                <label>
                  Operation
                  <select
                    value={node.params.operation}
                    onChange={(e) =>
                      patch(node.id, { operation: e.target.value, options: {} })
                    }
                  >
                    {["smooth", "decimate", "repair", "transform"].map((o) => (
                      <option key={o}>{o}</option>
                    ))}
                  </select>
                </label>
                {node.params.operation === "smooth" && (
                  <label>
                    Passes
                    <input
                      type="number"
                      min={1}
                      max={50}
                      value={node.params.options?.iterations ?? 5}
                      onChange={(e) =>
                        patch(node.id, {
                          options: { iterations: Number(e.target.value) },
                        })
                      }
                    />
                  </label>
                )}
                {node.params.operation === "decimate" && (
                  <label>
                    Retained face ratio
                    <input
                      type="number"
                      min={0.05}
                      max={0.95}
                      step={0.05}
                      value={node.params.options?.ratio ?? 0.5}
                      onChange={(e) =>
                        patch(node.id, {
                          options: { ratio: Number(e.target.value) },
                        })
                      }
                    />
                  </label>
                )}
                {node.params.operation === "transform" &&
                  (["position", "rotation", "scale"] as const).map((key) => (
                    <fieldset key={key}>
                      <legend>{key}</legend>
                      <div className="studio-vector">
                        {["X", "Y", "Z"].map((axis, i) => (
                          <label key={axis}>
                            {axis}
                            <input
                              type="number"
                              step=".1"
                              value={
                                node.params.options?.[key]?.[i] ??
                                (key === "scale" ? 1 : 0)
                              }
                              onChange={(e) => {
                                const options = { ...node.params.options },
                                  vector = [
                                    ...(options[key] ||
                                      (key === "scale"
                                        ? [1, 1, 1]
                                        : [0, 0, 0])),
                                  ];
                                vector[i] = Number(e.target.value);
                                patch(node.id, {
                                  options: { ...options, [key]: vector },
                                });
                              }}
                            />
                          </label>
                        ))}
                      </div>
                    </fieldset>
                  ))}
              </>
            )}
            {node.type === "add_to_scene" && (
              <label>
                Additional objects
                <select
                  multiple
                  value={(node.params.asset_ids || []).map(String)}
                  onChange={(e) =>
                    patch(node.id, {
                      asset_ids: Array.from(e.target.selectedOptions).map((o) =>
                        Number(o.value),
                      ),
                    })
                  }
                >
                  {assets.map((a) => (
                    <option key={a.id} value={a.id}>
                      {a.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {node.type === "export" && (
              <label>
                Format
                <select
                  value={node.params.format}
                  onChange={(e) => patch(node.id, { format: e.target.value })}
                >
                  {["glb", "gltf", "obj", "stl", "ply", "json"].map((f) => (
                    <option key={f}>{f}</option>
                  ))}
                </select>
              </label>
            )}
            <div className="studio-tool-row">
              <button
                type="button"
                aria-label={`Move ${names[node.type]} earlier`}
                disabled={index === 0}
                onClick={() => move(index, -1)}
              >
                ↑
              </button>
              <button
                type="button"
                aria-label={`Move ${names[node.type]} later`}
                disabled={index === graph.nodes.length - 1}
                onClick={() => move(index, 1)}
              >
                ↓
              </button>
              <button
                type="button"
                onClick={() =>
                  setGraph((g) => ({
                    nodes: g.nodes.filter((n) => n.id !== node.id),
                    edges: g.edges.filter(
                      (e) => e.from !== node.id && e.to !== node.id,
                    ),
                  }))
                }
              >
                Remove node
              </button>
            </div>
            <small>Node {node.id}</small>
          </article>
        ))}
      </div>
      <div className="studio-tool-row">
        <label>
          Output node
          <select value={from} onChange={(e) => setFrom(e.target.value)}>
            <option value="">Choose source</option>
            {graph.nodes.map((n) => (
              <option key={n.id} value={n.id}>
                {names[n.type]} · {n.id}
              </option>
            ))}
          </select>
        </label>
        <label>
          Input node
          <select value={to} onChange={(e) => setTo(e.target.value)}>
            <option value="">Choose target</option>
            {graph.nodes
              .filter((n) => ports[n.type][0])
              .map((n) => (
                <option key={n.id} value={n.id}>
                  {names[n.type]} · {n.id}
                </option>
              ))}
          </select>
        </label>
        <button type="button" onClick={connect}>
          Connect typed ports
        </button>
      </div>
      {graph.edges.map((edge, i) => (
        <p key={i}>
          {edge.from} → {edge.to}{" "}
          <button
            type="button"
            onClick={() =>
              setGraph((g) => ({
                ...g,
                edges: g.edges.filter((_, n) => n !== i),
              }))
            }
          >
            Disconnect
          </button>
        </p>
      ))}
    </section>
  );
}
