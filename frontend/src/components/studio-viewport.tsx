"use client";
import { useEffect, useRef, useState } from "react";
import * as THREE from "three";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { SVGRenderer } from "three/addons/renderers/SVGRenderer.js";
import {
  CSS2DRenderer,
  CSS2DObject,
} from "three/addons/renderers/CSS2DRenderer.js";
import { downloadScene, type StudioScene } from "@/lib/studio-scene";
export default function StudioViewport({
  scene,
  architecture,
}: {
  scene: StudioScene;
  architecture: boolean;
}) {
  const host = useRef<HTMLDivElement>(null),
    actions = useRef<{ reset: () => void; export: () => Promise<void> } | null>(
      null,
    );
  const [selected, setSelected] = useState<string | null>(null),
    [error, setError] = useState(""),
    [exporting, setExporting] = useState(false),
    [compatibility, setCompatibility] = useState(false),
    [ready, setReady] = useState(false);
  useEffect(() => {
    if (!host.current) return;
    const container = host.current;
    setError("");
    setSelected(null);
    setReady(false);
    let renderer: THREE.WebGLRenderer | SVGRenderer;
    let fallback = false;
    try {
      renderer = new THREE.WebGLRenderer({ antialias: true });
    } catch {
      renderer = new SVGRenderer();
      renderer.setQuality("low");
      renderer.setPrecision(2);
      renderer.domElement.setAttribute("role", "img");
      renderer.domElement.setAttribute(
        "aria-label",
        "Compatibility 3D preview",
      );
      fallback = true;
    }
    setCompatibility(fallback);
    const world = new THREE.Scene();
    world.background = new THREE.Color("#101722");
    const camera = new THREE.PerspectiveCamera(45, 1, 0.05, 1000);
    if (renderer instanceof THREE.WebGLRenderer)
      renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
    container.appendChild(renderer.domElement);
    const labels = new CSS2DRenderer();
    labels.domElement.className = "studio-label-layer";
    container.appendChild(labels.domElement);
    const controls = new OrbitControls(
      camera,
      fallback ? container : (renderer.domElement as HTMLCanvasElement),
    );
    controls.enableDamping = !fallback;
    world.add(new THREE.HemisphereLight(0xffffff, 0x34415e, 3));
    const light = new THREE.DirectionalLight(0xffffff, 4);
    light.position.set(10, 20, 10);
    world.add(light);
    const model = new THREE.Group();
    model.name = scene.title;
    world.add(model);
    const meshes: THREE.Mesh[] = [];
    function label(text: string, position: THREE.Vector3) {
      const el = document.createElement("span");
      el.className = "studio-label";
      el.textContent = text;
      const obj = new CSS2DObject(el);
      obj.position.copy(position);
      world.add(obj);
    }
    for (const node of scene.nodes) {
      const geometry =
        node.shape === "sphere"
          ? new THREE.SphereGeometry(0.5, fallback ? 12 : 24, fallback ? 8 : 16)
          : node.shape === "cylinder"
            ? new THREE.CylinderGeometry(0.5, 0.5, 1, 24)
            : node.shape === "cone"
              ? new THREE.ConeGeometry(0.5, 1, 24)
              : node.shape === "torus"
                ? new THREE.TorusGeometry(0.5, 0.15, 12, 32)
                : new THREE.BoxGeometry(1, 1, 1);
      const mesh = new THREE.Mesh(
        geometry,
        new THREE.MeshStandardMaterial({
          color: node.color,
          roughness: 0.55,
          metalness: 0.15,
        }),
      );
      mesh.position.fromArray(node.position);
      mesh.rotation.set(...node.rotation);
      mesh.scale.fromArray(node.scale);
      mesh.name = node.label;
      mesh.userData.nodeId = node.id;
      model.add(mesh);
      meshes.push(mesh);
      if (architecture)
        label(
          node.label,
          new THREE.Vector3(...node.position).add(
            new THREE.Vector3(0, node.scale[1] / 2 + 0.4, 0),
          ),
        );
    }
    for (const edge of scene.edges) {
      const from = scene.nodes.find((n) => n.id === edge.from)!,
        to = scene.nodes.find((n) => n.id === edge.to)!;
      const start = new THREE.Vector3(...from.position),
        end = new THREE.Vector3(...to.position),
        delta = end.clone().sub(start),
        distance = delta.length();
      if (distance < 0.01) continue;
      const inset = Math.min(Math.max(...to.scale) * 0.6, distance * 0.3);
      const arrow = new THREE.ArrowHelper(
        delta.normalize(),
        start,
        distance - inset,
        0x80bfff,
        Math.min(0.5, distance * 0.15),
        0.22,
      );
      arrow.name = edge.label;
      model.add(arrow);
      if (architecture)
        label(
          edge.label,
          start
            .clone()
            .lerp(end, 0.5)
            .add(new THREE.Vector3(0, 0.35, 0)),
        );
    }
    const box = new THREE.Box3().setFromObject(model),
      center = box.getCenter(new THREE.Vector3()),
      radius = Math.max(box.getSize(new THREE.Vector3()).length() / 2, 2);
    const grid = new THREE.GridHelper(radius * 4, 40, 0x38506a, 0x223247);
    grid.position.y = box.min.y - 0.05;
    world.add(grid);
    const reset = () => {
      controls.target.copy(center);
      camera.position
        .copy(center)
        .add(
          new THREE.Vector3(1, 0.8, 1).normalize().multiplyScalar(radius * 3),
        );
      controls.update();
    };
    reset();
    actions.current = {
      reset,
      export: async () => {
        const { GLTFExporter } =
          await import("three/addons/exporters/GLTFExporter.js");
        const result = await new GLTFExporter().parseAsync(model, {
          binary: true,
        });
        if (!(result instanceof ArrayBuffer)) throw new Error("Export failed");
        downloadScene(result, "openmind-scene.glb", "model/gltf-binary");
      },
    };
    setReady(true);
    const renderFrame = () => {
      renderer.render(world, camera);
      labels.render(world, camera);
    };
    const resize = () => {
      const w = container.clientWidth,
        h = container.clientHeight;
      if (!w || !h) return;
      camera.aspect = w / h;
      camera.updateProjectionMatrix();
      renderer.setSize(w, h);
      labels.setSize(w, h);
      renderFrame();
    };
    const observer = new ResizeObserver(resize);
    observer.observe(container);
    resize();
    const ray = new THREE.Raycaster();
    let down = { x: 0, y: 0 };
    const pointerDown = (e: PointerEvent) => {
      down = { x: e.clientX, y: e.clientY };
    };
    const click = (e: PointerEvent) => {
      if (Math.hypot(e.clientX - down.x, e.clientY - down.y) > 5) return;
      const r = renderer.domElement.getBoundingClientRect();
      ray.setFromCamera(
        new THREE.Vector2(
          ((e.clientX - r.left) / r.width) * 2 - 1,
          (-(e.clientY - r.top) / r.height) * 2 + 1,
        ),
        camera,
      );
      const hit = ray.intersectObjects(meshes)[0];
      setSelected(hit ? String(hit.object.userData.nodeId) : null);
    };
    container.addEventListener("pointerdown", pointerDown);
    container.addEventListener("pointerup", click);
    if (renderer instanceof THREE.WebGLRenderer) {
      renderer.setAnimationLoop(() => {
        controls.update();
        renderFrame();
      });
    } else {
      controls.addEventListener("change", renderFrame);
    }
    return () => {
      actions.current = null;
      observer.disconnect();
      if (renderer instanceof THREE.WebGLRenderer)
        renderer.setAnimationLoop(null);
      controls.removeEventListener("change", renderFrame);
      controls.dispose();
      container.removeEventListener("pointerdown", pointerDown);
      container.removeEventListener("pointerup", click);
      world.traverse((obj) => {
        const m = obj as THREE.Mesh;
        m.geometry?.dispose();
        if (Array.isArray(m.material)) m.material.forEach((x) => x.dispose());
        else m.material?.dispose();
      });
      if (renderer instanceof THREE.WebGLRenderer) renderer.dispose();
      container.replaceChildren();
    };
  }, [scene, architecture]);
  const node = scene.nodes.find((n) => n.id === selected);
  return (
    <section className="studio-viewer" aria-label="Interactive 3D preview">
      <div className="studio-viewport" ref={host} />
      {compatibility && (
        <p className="studio-compatibility" role="status">
          Compatibility preview · WebGL unavailable. Orbit, inspect and GLB
          export remain available.
        </p>
      )}
      {error && (
        <p role="alert" className="studio-preview-error">
          {error}
        </p>
      )}
      <div className="studio-viewer-tools">
        <button onClick={() => actions.current?.reset()}>Reset view</button>
        <button
          disabled={exporting || !ready}
          onClick={async () => {
            setExporting(true);
            try {
              await actions.current?.export();
            } catch {
              setError("GLB export failed. Scene JSON is still available.");
            } finally {
              setExporting(false);
            }
          }}
        >
          {exporting ? "Exporting…" : "Download GLB"}
        </button>
      </div>
      <label className="studio-inspect">
        Inspect component
        <select
          aria-label="Inspect component"
          value={selected ?? ""}
          onChange={(event) => setSelected(event.target.value || null)}
        >
          <option value="">Choose a component</option>
          {scene.nodes.map((part) => (
            <option key={part.id} value={part.id}>
              {part.label}
            </option>
          ))}
        </select>
      </label>
      {node && (
        <aside className="studio-node">
          <strong>{node.label}</strong>
          <p>
            {node.shape} · {node.position.join(", ")}
          </p>
          {scene.edges
            .filter((e) => e.from === node.id || e.to === node.id)
            .map((e, i) => (
              <p key={i}>
                {scene.nodes.find((n) => n.id === e.from)?.label} →{" "}
                {scene.nodes.find((n) => n.id === e.to)?.label}: {e.label}
              </p>
            ))}
        </aside>
      )}
      <p className="studio-viewer-hint">
        Drag to orbit · Scroll to zoom · Right-drag to pan · Click a part to
        inspect
      </p>
    </section>
  );
}
