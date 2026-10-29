"use client";
import { useEffect, useRef, useState } from "react";
import dynamic from "next/dynamic";
import type { StudioScene } from "@/lib/studio-scene";
const ProceduralViewport = dynamic(
  () => import("@/components/studio-viewport"),
  { ssr: false },
);
import * as THREE from "three";
import { GLTFLoader } from "three/addons/loaders/GLTFLoader.js";
import { OBJLoader } from "three/addons/loaders/OBJLoader.js";
import { STLLoader } from "three/addons/loaders/STLLoader.js";
import { PLYLoader } from "three/addons/loaders/PLYLoader.js";
import { SVGRenderer } from "three/addons/renderers/SVGRenderer.js";
import { OrbitControls } from "three/addons/controls/OrbitControls.js";
import { workbenchApi, type Asset } from "@/lib/studio-workbench";
export default function AssetViewport({ asset }: { asset: Asset }) {
  const host = useRef<HTMLDivElement>(null),
    reset = useRef<() => void>(() => {}),
    [error, setError] = useState(""),
    [ready, setReady] = useState(false),
    [selected, setSelected] = useState(""),
    [compatible, setCompatible] = useState(false),
    [nativeScene, setNativeScene] = useState<StudioScene | null>(null);
  useEffect(() => {
    let disposed = false;
    const cleaners: (() => void)[] = [];
    const cleanup = () => {
      while (cleaners.length) cleaners.pop()?.();
    };
    const disposeObject = (object: THREE.Object3D) =>
      object.traverse((o) => {
        if (o instanceof THREE.Mesh) {
          o.geometry.dispose();
          for (const material of Array.isArray(o.material)
            ? o.material
            : [o.material]) {
            for (const value of Object.values(material))
              if (value instanceof THREE.Texture) value.dispose();
            material.dispose();
          }
        }
      });
    setNativeScene(null);
    setReady(false);
    setError("");
    setSelected("");
    (async () => {
      try {
        const result = await workbenchApi(`/assets/${asset.id}/data`);
        let bytes = Uint8Array.from(atob(result.data), (c) => c.charCodeAt(0));
        let format = result.format;
        let object: THREE.Object3D;
        if (format === "json") {
          const manifest = JSON.parse(new TextDecoder().decode(bytes));
          if (Array.isArray(manifest.nodes) && Array.isArray(manifest.edges)) {
            if (!disposed) {
              setNativeScene(manifest as StudioScene);
              setReady(true);
            }
            return;
          }
          if (
            manifest.schema !== "openmind.scene/1" ||
            typeof manifest.glb !== "string"
          )
            throw new Error("This scene manifest needs the procedural viewer.");
          bytes = Uint8Array.from(atob(manifest.glb), (c) => c.charCodeAt(0));
          format = "glb";
        }
        if (format === "glb" || format === "gltf") {
          const gltf = await new GLTFLoader().parseAsync(
            format === "glb" ? bytes.buffer : new TextDecoder().decode(bytes),
            "",
          );
          object = gltf.scene;
        } else if (format === "obj")
          object = new OBJLoader().parse(new TextDecoder().decode(bytes));
        else {
          const geometry =
            format === "stl"
              ? new STLLoader().parse(bytes.buffer)
              : new PLYLoader().parse(bytes.buffer);
          object = new THREE.Mesh(
            geometry,
            new THREE.MeshStandardMaterial({
              color: 0xa6c8ed,
              roughness: 0.6,
              vertexColors: geometry.hasAttribute("color"),
            }),
          );
        }
        if (disposed) {
          disposeObject(object);
          return;
        }
        cleaners.push(() => disposeObject(object));
        const container = host.current!;
        let renderer: THREE.WebGLRenderer | SVGRenderer;
        let svg = false;
        try {
          renderer = new THREE.WebGLRenderer({ antialias: true });
          renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
        } catch {
          renderer = new SVGRenderer();
          renderer.setClearColor(new THREE.Color("#101722"), 1);
          svg = true;
        }
        cleaners.push(() => {
          if (renderer instanceof THREE.WebGLRenderer) renderer.dispose();
          renderer.domElement.remove();
        });
        let triangles = 0;
        object.traverse((o) => {
          if (o instanceof THREE.Mesh)
            triangles +=
              (o.geometry.index?.count ||
                o.geometry.getAttribute("position").count) / 3;
        });
        if (svg && triangles > 10000)
          throw new Error(
            "This detailed mesh needs WebGL to preview. The saved model can still be downloaded.",
          );
        if (svg)
          object.traverse((o) => {
            if (o instanceof THREE.Mesh) {
              const source = Array.isArray(o.material)
                ? o.material[0]
                : o.material;
              for (const original of Array.isArray(o.material)
                ? o.material
                : [o.material]) {
                cleaners.push(() => {
                  for (const value of Object.values(original))
                    if (value instanceof THREE.Texture) value.dispose();
                  original.dispose();
                });
              }
              o.material = new THREE.MeshLambertMaterial({
                color: source.color || 0xa6c8ed,
                side: THREE.DoubleSide,
              });
            }
          });
        setCompatible(svg);
        const scene = new THREE.Scene();
        scene.background = new THREE.Color("#101722");
        scene.add(new THREE.AmbientLight(0xffffff, 1.5));
        const light = new THREE.DirectionalLight(0xffffff, 3);
        light.position.set(5, 8, 4);
        scene.add(light);
        scene.add(object);
        const bounds = new THREE.Box3().setFromObject(object);
        const center = bounds.getCenter(new THREE.Vector3()),
          size = bounds.getSize(new THREE.Vector3()).length();
        const camera = new THREE.PerspectiveCamera(
          45,
          1,
          Math.max(0.001, size / 10000),
          Math.max(100, size * 20),
        );
        const controls = new OrbitControls(
          camera,
          renderer.domElement as HTMLElement,
        );
        cleaners.push(() => controls.dispose());
        controls.target.copy(center);
        reset.current = () => {
          camera.position
            .copy(center)
            .add(new THREE.Vector3(size, size * 0.7, size).multiplyScalar(0.9));
          controls.target.copy(center);
          controls.update();
          render();
        };
        container.append(renderer.domElement);
        function render() {
          renderer.render(scene, camera);
        }
        function resize() {
          const w = container.clientWidth,
            h = container.clientHeight;
          renderer.setSize(w, h);
          camera.aspect = w / Math.max(1, h);
          camera.updateProjectionMatrix();
          render();
        }
        const observer = new ResizeObserver(resize);
        cleaners.push(() => observer.disconnect());
        observer.observe(container);
        controls.addEventListener("change", render);
        reset.current();
        resize();
        const pick = (raw: Event) => {
          const event = raw as PointerEvent;
          const rect = renderer.domElement.getBoundingClientRect();
          const ray = new THREE.Raycaster();
          ray.setFromCamera(
            new THREE.Vector2(
              ((event.clientX - rect.left) / rect.width) * 2 - 1,
              (-(event.clientY - rect.top) / rect.height) * 2 + 1,
            ),
            camera,
          );
          const hit = ray.intersectObject(object, true)[0];
          setSelected(hit?.object.name || (hit ? "Mesh part" : ""));
        };
        renderer.domElement.addEventListener("pointerup", pick);
        cleaners.push(() =>
          renderer.domElement.removeEventListener("pointerup", pick),
        );
        setReady(true);
      } catch (e) {
        cleanup();
        if (!disposed)
          setError(e instanceof Error ? e.message : "Could not preview model.");
      }
    })();
    return () => {
      disposed = true;
      cleanup();
    };
  }, [asset.id]);
  return (
    <section aria-label="Imported mesh preview">
      <div className="studio-tool-row">
        <button type="button" disabled={!ready} onClick={() => reset.current()}>
          Reset mesh view
        </button>
        <a href={`/api/backend/3d-studio/assets/${asset.id}/download`} download>
          Download {asset.format.toUpperCase()}
        </a>
      </div>
      {error && <p role="alert">{error}</p>}
      {!ready && !error && <p role="status">Loading validated geometry…</p>}
      {nativeScene ? (
        <ProceduralViewport
          scene={nativeScene}
          architecture={nativeScene.edges.length > 0}
        />
      ) : (
        <div className="studio-viewport studio-asset-viewport" ref={host} />
      )}
      {compatible && ready && (
        <small>
          Compatible geometry preview. Texture rendering requires WebGL.
        </small>
      )}
      {selected && <p>Selected: {selected}</p>}
    </section>
  );
}
