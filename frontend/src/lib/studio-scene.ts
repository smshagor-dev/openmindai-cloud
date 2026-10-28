export type SceneNode = {
  id: string;
  label: string;
  shape: "box" | "sphere" | "cylinder" | "cone" | "torus";
  position: [number, number, number];
  rotation: [number, number, number];
  scale: [number, number, number];
  color: string;
};
export type StudioScene = {
  title: string;
  description: string;
  nodes: SceneNode[];
  edges: { from: string; to: string; label: string }[];
};
export type SavedScene = {
  id: number;
  mode: "object" | "architecture";
  prompt: string;
  scene: StudioScene;
};
export async function studioApi(path = "", method = "GET", body?: unknown) {
  const response = await fetch(`/api/backend/3d-scenes${path}`, {
    method,
    headers: body ? { "Content-Type": "application/json" } : undefined,
    body: body ? JSON.stringify(body) : undefined,
    cache: "no-store",
  });
  if (response.status === 204) return null;
  const data = await response.json();
  if (!response.ok)
    throw new Error(
      data.message || data.error?.message || "3D Studio request failed.",
    );
  return data;
}
export function downloadScene(data: BlobPart, filename: string, type: string) {
  const url = URL.createObjectURL(new Blob([data], { type }));
  const a = document.createElement("a");
  a.href = url;
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}
