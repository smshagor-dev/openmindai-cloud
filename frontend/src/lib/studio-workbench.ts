export type Engine = {
  id: string;
  name: string;
  kind: string;
  input: string;
  output: string;
  available: boolean;
  reason?: string;
  limits?: { per_user_daily: number; per_user_hourly: number; server_daily: number; concurrent: number };
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
  result?: { asset_id?: number; format?: string; phase?: string };
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

export function studioJobError(code?: string): string {
  const messages: Record<string, string> = {
    free_provider_quota: "Hugging Face free GPU quota is exhausted. No paid fallback was used. Try later; a free Hugging Face account token on the server can provide its account quota.",
    free_provider_auth_required: "Hugging Face requires authentication for this request. Configure a free account token on the server.",
    free_provider_unavailable: "The free 3D service is unavailable or its queue is full. Try later.",
    free_provider_contract_changed: "The free 3D service changed its API. Generation is paused until the integration is updated.",
    free_queue_timeout: "The free GPU queue exceeded the time limit. The request was stopped locally; the provider may still finish work already started.",
    free_stream_interrupted: "The free 3D connection ended before a model was saved. The GPU request will not be automatically repeated.",
    free_submission_unknown: "The free GPU request outcome is uncertain. Check this saved job before starting another request.",
    free_invalid_mesh: "The free provider did not return a valid 3D mesh. No asset was saved.",
    hosted_submission_unknown: "The provider may have accepted this generation, but its receipt was not confirmed. Check this saved job and your provider account before starting another generation.",
  };
  return messages[code || ""] || `Job failed: ${code || "operation unavailable"}.`;
}
