export type Engine = {
  id: string;
  name: string;
  kind: string;
  input: string;
  output: string;
  available: boolean;
  reason?: string;
  vram_gb?: number;
  version?: string;
  parameters?: {
    id: string;
    label: string;
    type: string;
    default: unknown;
    min?: number;
    max?: number;
    options?: { value: string | number; label: string }[];
  }[];
};
export type Capabilities = {
  data: Engine[];
  deployment?: { scene_execution: "direct" | "queued"; queued_jobs: boolean };
  worker: { ready: boolean; reason?: string; processors?: string[] };
};
export type Asset = {
  id: number;
  parent_id: number | null;
  project_id: number | null;
  name: string;
  format: string;
  kind: string;
  bytes: number;
  metadata: string | Record<string, unknown>;
};
export type Job = {
  id: string;
  engine: string;
  operation: string;
  status: string;
  error_code?: string;
  result?: { asset_id: number; format: string };
  events?: { id: number; type: string; data: string; created_at: string }[];
};
export async function workbenchApi(
  path: string,
  method = "GET",
  body?: unknown,
) {
  const response = await fetch(`/api/backend/3d-studio${path}`, {
    method,
    headers: body ? { "Content-Type": "application/json" } : undefined,
    body: body ? JSON.stringify(body) : undefined,
    cache: "no-store",
  });
  if (response.status === 204) return null;
  const result = await response.json();
  if (!response.ok) throw new Error(result.message || "3D operation failed.");
  return result;
}
export function encodeBytes(bytes: Uint8Array) {
  let raw = "";
  for (let i = 0; i < bytes.length; i += 32768)
    raw += String.fromCharCode(...bytes.subarray(i, i + 32768));
  return btoa(raw);
}
