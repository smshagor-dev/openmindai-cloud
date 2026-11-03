// Image → 2.5D relief model, entirely in the user's browser: Depth Anything V2
// estimates per-pixel depth, which displaces a textured grid exported as GLB.
// No server GPU or API key is involved and the image never leaves the device.
import * as THREE from "three";
import { GLTFExporter } from "three/addons/exporters/GLTFExporter.js";

export type DepthProgress = { stage: "download" | "prepare" | "depth" | "mesh"; percent: number | null };
type DepthMap = { data: Uint8Array; width: number; height: number };

// One background worker per page, reused across generations (the model stays loaded).
let worker: Worker | null = null;
let queue: Promise<unknown> = Promise.resolve();

function estimateDepth(image: Blob, onProgress: (p: DepthProgress) => void): Promise<DepthMap> {
  const run = () => new Promise<DepthMap>((resolve, reject) => {
    worker ||= new Worker(new URL("./depth-worker.ts", import.meta.url), { type: "module" });
    const current = worker;
    current.onmessage = (event: MessageEvent) => {
      const message = event.data;
      if (message.type === "progress") onProgress({ stage: message.stage, percent: message.percent });
      else if (message.type === "depth") resolve(message);
      else reject(new Error(message.message || "Depth estimation failed."));
    };
    current.onerror = () => {
      current.terminate();
      if (worker === current) worker = null;
      reject(new Error("The depth AI could not start in this browser."));
    };
    current.postMessage({ image });
  });
  const result = queue.then(run, run);
  queue = result.catch(() => undefined);
  return result;
}

/** Downscaled canvas of the image (also used as the model texture). */
async function imageCanvas(file: Blob, max: number) {
  const bitmap = await createImageBitmap(file);
  try {
    const ratio = Math.min(1, max / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement("canvas");
    canvas.width = Math.max(1, Math.round(bitmap.width * ratio));
    canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
    canvas.getContext("2d")!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    return canvas;
  } finally {
    bitmap.close();
  }
}

export async function imageToReliefGlb(file: Blob, onProgress: (p: DepthProgress) => void): Promise<ArrayBuffer> {
  onProgress({ stage: "download", percent: 0 });
  const input = await imageCanvas(file, 518);
  const small = await new Promise<Blob>((resolve, reject) =>
    input.toBlob((blob) => (blob ? resolve(blob) : reject(new Error("Could not prepare the image."))), "image/png"));
  const depth = await estimateDepth(small, onProgress);
  onProgress({ stage: "mesh", percent: null });
  const texture = await imageCanvas(file, 1024);
  return reliefGlb(depth.data, depth.width, depth.height, texture);
}

/** Depth map (0–255, brighter = nearer) → displaced, textured grid with a closed back. */
async function reliefGlb(depth: ArrayLike<number>, dw: number, dh: number, texture: HTMLCanvasElement): Promise<ArrayBuffer> {
  const aspect = dw / dh;
  const width = aspect >= 1 ? 1 : aspect, height = aspect >= 1 ? 1 / aspect : 1;
  const segX = Math.min(255, dw - 1), segY = Math.min(255, Math.round(segX / aspect));
  const geometry = new THREE.PlaneGeometry(width, height, segX, segY);
  const position = geometry.attributes.position as THREE.BufferAttribute;
  const uv = geometry.attributes.uv as THREE.BufferAttribute;
  const relief = 0.28 * Math.max(width, height);
  const sample = (u: number, v: number) => {
    // Bilinear sample; v = 1 is the top row of the image.
    const x = u * (dw - 1), y = (1 - v) * (dh - 1);
    const x0 = Math.floor(x), y0 = Math.floor(y), x1 = Math.min(dw - 1, x0 + 1), y1 = Math.min(dh - 1, y0 + 1);
    const fx = x - x0, fy = y - y0, at = (px: number, py: number) => depth[py * dw + px] / 255;
    return (at(x0, y0) * (1 - fx) + at(x1, y0) * fx) * (1 - fy) + (at(x0, y1) * (1 - fx) + at(x1, y1) * fx) * fy;
  };
  for (let i = 0; i < position.count; i++) position.setZ(i, sample(uv.getX(i), uv.getY(i)) * relief);
  geometry.computeVertexNormals();
  const map = new THREE.CanvasTexture(texture);
  map.colorSpace = THREE.SRGBColorSpace;
  const front = new THREE.Mesh(geometry, new THREE.MeshStandardMaterial({ map, roughness: 0.55, metalness: 0.05 }));
  front.name = "Relief";
  const back = new THREE.Mesh(new THREE.PlaneGeometry(width, height), new THREE.MeshStandardMaterial({ color: 0x1b1b1f, roughness: 0.9 }));
  back.rotation.y = Math.PI;
  back.position.z = -0.01;
  back.name = "Back";
  const group = new THREE.Group();
  group.name = "Image relief";
  group.add(front, back);
  try {
    return (await new GLTFExporter().parseAsync(group, { binary: true })) as ArrayBuffer;
  } finally {
    geometry.dispose();
    map.dispose();
  }
}
