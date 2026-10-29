"use client";
import { useState } from "react";
import {
  studioApi,
  type SavedScene,
  type SceneNode,
  type StudioScene,
} from "@/lib/studio-scene";
export default function SceneEditor({
  saved,
  onChange,
  onSaved,
}: {
  saved: SavedScene;
  onChange: (scene: StudioScene) => void;
  onSaved: (scene: SavedScene) => void;
}) {
  const [nodeId, setNodeId] = useState(saved.scene.nodes[0]?.id || ""),
    [undo, setUndo] = useState<StudioScene[]>([]),
    [redo, setRedo] = useState<StudioScene[]>([]),
    [busy, setBusy] = useState(false),
    [error, setError] = useState(""),
    [versions, setVersions] = useState<{ version: number; scene: string }[]>(
      [],
    );
  const node = saved.scene.nodes.find((n) => n.id === nodeId);
  function edit(next: StudioScene) {
    setUndo((old) => [...old, structuredClone(saved.scene)]);
    setRedo([]);
    onChange(next);
  }
  function patch(update: Partial<SceneNode>) {
    if (node)
      edit({
        ...saved.scene,
        nodes: saved.scene.nodes.map((n) =>
          n.id === node.id ? { ...n, ...update } : n,
        ),
      });
  }
  async function save() {
    setBusy(true);
    setError("");
    try {
      const result = await studioApi(`/${saved.id}`, "PUT", {
        version: saved.version || 1,
        scene: saved.scene,
      });
      onSaved(result);
      setVersions((await studioApi(`/${saved.id}/revisions`)).data);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Save failed");
    } finally {
      setBusy(false);
    }
  }
  return (
    <section className="studio-editor" aria-label="Scene editor">
      <h3>Edit geometry</h3>
      <div className="studio-tool-row">
        <button
          type="button"
          disabled={!undo.length || busy}
          onClick={() => {
            const old = undo.at(-1)!;
            setRedo((r) => [...r, structuredClone(saved.scene)]);
            setUndo(undo.slice(0, -1));
            onChange(old);
          }}
        >
          Undo
        </button>
        <button
          type="button"
          disabled={!redo.length || busy}
          onClick={() => {
            const old = redo.at(-1)!;
            setUndo((u) => [...u, structuredClone(saved.scene)]);
            setRedo(redo.slice(0, -1));
            onChange(old);
          }}
        >
          Redo
        </button>
        <button type="button" disabled={busy} onClick={save}>
          {busy ? "Saving…" : "Save scene revision"}
        </button>
        <button
          type="button"
          disabled={busy}
          onClick={async () => {
            try {
              setVersions((await studioApi(`/${saved.id}/revisions`)).data);
            } catch (e) {
              setError(String(e));
            }
          }}
        >
          Load revision history
        </button>
      </div>
      <label>
        Part
        <select value={nodeId} onChange={(e) => setNodeId(e.target.value)}>
          {saved.scene.nodes.map((n) => (
            <option key={n.id} value={n.id}>
              {n.label}
            </option>
          ))}
        </select>
      </label>
      {node && (
        <>
          <label>
            Part name
            <input
              value={node.label}
              maxLength={100}
              onChange={(e) => patch({ label: e.target.value })}
            />
          </label>
          <label>
            Material color
            <input
              aria-label="Material color"
              type="color"
              value={node.color}
              onChange={(e) => patch({ color: e.target.value })}
            />
          </label>
          <label>
            <input
              type="checkbox"
              checked={!node.hidden}
              onChange={(e) => patch({ hidden: !e.target.checked })}
            />
            Visible
          </label>
          {(["position", "rotation", "scale"] as const).map((key) => (
            <fieldset key={key}>
              <legend>
                {key === "position"
                  ? "Move"
                  : key === "rotation"
                    ? "Rotate (radians)"
                    : "Scale"}
              </legend>
              <div className="studio-vector">
                {["X", "Y", "Z"].map((axis, i) => (
                  <label key={axis}>
                    {axis}
                    <input
                      aria-label={`${key} ${axis}`}
                      type="number"
                      step="0.1"
                      min={
                        key === "scale"
                          ? 0.05
                          : key === "rotation"
                            ? -6.284
                            : -40
                      }
                      max={
                        key === "scale" ? 20 : key === "rotation" ? 6.284 : 40
                      }
                      value={node[key][i]}
                      onChange={(e) => {
                        const value = Number(e.target.value);
                        const min =
                            key === "scale"
                              ? 0.05
                              : key === "rotation"
                                ? -6.284
                                : -40,
                          max =
                            key === "scale"
                              ? 20
                              : key === "rotation"
                                ? 6.284
                                : 40;
                        if (
                          !Number.isFinite(value) ||
                          value < min ||
                          value > max
                        )
                          return;
                        const vector = [...node[key]] as [
                          number,
                          number,
                          number,
                        ];
                        vector[i] = value;
                        patch({ [key]: vector });
                      }}
                    />
                  </label>
                ))}
              </div>
            </fieldset>
          ))}
        </>
      )}
      {versions.length > 0 && (
        <label>
          Previous revisions
          <select
            defaultValue=""
            onChange={(e) => {
              const version = versions.find(
                (v) => v.version === Number(e.target.value),
              );
              if (version)
                edit(
                  typeof version.scene === "string"
                    ? JSON.parse(version.scene)
                    : version.scene,
                );
            }}
          >
            <option value="">Choose a revision to restore</option>
            {versions.map((v) => (
              <option key={v.version} value={v.version}>
                Revision {v.version}
              </option>
            ))}
          </select>
        </label>
      )}
      {error && <p role="alert">{error}</p>}
      <small>
        Edits change geometry. Save to persist a new revision; GLB export uses
        the visible edited model.
      </small>
    </section>
  );
}
