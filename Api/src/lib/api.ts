export type Developer = {
  id: number;
  name: string;
  email: string;
  two_factor_confirmed_at: string | null;
};
export type ApiKey = {
  id: number;
  name: string;
  created_at: string;
  last_used_at: string | null;
  expires_at: string | null;
};
export type KeyResponse = { base_url: string; keys: ApiKey[] };
export type CreatedKey = { api_key: string; id: number; base_url: string; expires_at: string };
export type Model = {
  id: string; name: string; category: string; description?: string;
  available: boolean; operation: string; path: string | null;
};
export type Usage = {
  month: string; used: number; limit: number | null; remaining: number | null;
  by_model: { model: string; kind: string; used: number }[];
  daily: { day: string; used: number }[];
  unit: string; note: string;
};
export class RequestError extends Error {
  constructor(message: string, public status: number) { super(message); }
}
export async function api<T>(path: string, method = "GET", body?: unknown): Promise<T> {
  const response = await fetch("/api/bridge/" + path, {
    method, credentials: "same-origin", cache: "no-store",
    headers: { Accept: "application/json", ...(method !== "GET" ? { "Content-Type": "application/json" } : {}) },
    ...(method !== "GET" ? { body: JSON.stringify(body ?? {}) } : {})
  });
  const type = response.headers.get("content-type") || "";
  if (!type.includes("application/json")) throw new RequestError("The API did not return JSON. Check your server configuration.", response.status);
  const result: unknown = await response.json();
  if (!response.ok) {
    const error = result && typeof result === "object" ? result as { message?: string; errors?: Record<string, string[]> } : {};
    const first = error.errors ? Object.values(error.errors).flat().find((item) => typeof item === "string") : undefined;
    throw new RequestError(first || error.message || "The request could not be completed.", response.status);
  }
  return result as T;
}
