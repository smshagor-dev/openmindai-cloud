export class ApiError extends Error {
  status: number;

  constructor(message: string, status: number) {
    super(message);
    this.status = status;
  }
}
/**
 * Reject HTML error pages rather than attempting to JSON.parse them.
 * A hosting rewrite or backend error template must have an actionable message.
 */
export async function readApiJson<T>(
  response: Response,
  path: string,
): Promise<T> {
  if (response.status === 204 || response.status === 205) {
    if (!response.ok) throw new ApiError("The request failed.", response.status);
    return null as T;
  }

  const contentType = response.headers.get("content-type")?.toLowerCase() ?? "";
  if (!contentType.includes("application/json") && !contentType.includes("+json")) {
    const message = response.status === 404 || (response.ok && contentType.includes("text/html"))
      ? "The API endpoint returned a webpage instead of JSON. Ensure Next.js handles /api/backend/* and is not serving a static fallback."
      : "The API returned an unexpected response. Check the backend connection and server routing.";
    throw new ApiError(message, response.ok ? 502 : response.status);
  }

  let data: unknown;
  try {
    data = await response.json();
  } catch {
    throw new ApiError(
      "The API returned malformed JSON. Check the backend service and reverse-proxy configuration.",
      response.ok ? 502 : response.status,
    );
  }

  if (!response.ok) {
    const payload = data && typeof data === "object" ? data as {
      errors?: Record<string, unknown>;
      message?: unknown;
    } : {};
    const values = payload.errors && typeof payload.errors === "object"
      ? Object.values(payload.errors).flat()
      : [];
    const first = values.find((value) => typeof value === "string" && value.trim());
    const message = typeof first === "string"
      ? first
      : typeof payload.message === "string" && payload.message.trim()
        ? payload.message
        : `The request failed (${response.status}). Please try again.`;
    throw new ApiError(message, response.status);
  }

  return data as T;
}

export async function api<T>(
  path: string,
  method = "GET",
  body?: unknown,
  signal?: AbortSignal,
): Promise<T> {
  const response = await fetch("/api/backend/" + path, {
    method,
    credentials: "same-origin",
    cache: "no-store",
    headers: {
      Accept: "application/json",
      ...(method !== "GET" ? { "Content-Type": "application/json" } : {}),
    },
    ...(method !== "GET" ? { body: JSON.stringify(body ?? {}) } : {}),
    signal,
  });
  return readApiJson<T>(response, path);
}
export type User = {
  id: number;
  name: string;
  avatar?: string | null;
  is_admin?: boolean;
  pending_email?: string | null;
  email_verified_at?: string | null;
  email: string;
  two_factor_confirmed_at: string | null;
};
export type Model = {
  id: number;
  name: string;
  family: string;
  license: string;
};
export type Conversation = {
  id: number;
  title: string;
  updated_at: string;
  pinned?: boolean;
  archived_at?: string | null;
  project_id?: number | null;
  assistant_id?: number | null;
};
export type Message = {
  research_metadata?: import("./research-status").ResearchMetadata | null;
  id?: number;
  animate?: boolean;
  status?: string;
  resumable?: boolean;
  attachment_ids?: number[];
  sources?: {
    title: string;
    url: string;
    snippet?: string;
    citation?: number;
    read_status?: string;
    provider?:string;retrieved_at?:string;duplicate_of?:string|null;mime?:string;passages?:import("./research-evidence").Passage[];pdf?:{total_pages:number;sampled_pages:number[];partial:boolean;ocr:boolean}|null;
  }[];
  feedback?: "up" | "down" | null;
  role: "user" | "assistant";
  content: string;
};
export type Page<T> = { data: T[]; current_page: number; last_page: number };

export async function streamChat(
  body: unknown,
  onEvent: (event: string, data: Record<string, unknown>) => void,
  signal?: AbortSignal,
) {
  const response = await fetch(
    (body as { resume_message_id?: number }).resume_message_id
      ? "/api/backend/chat/resume"
      : "/api/backend/chat/stream",
    {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
      signal,
    },
  );
  if (!response.ok) {
    await readApiJson<unknown>(response, "chat/stream");
    throw new ApiError("Unable to start chat.", response.status);
  }
  if (!response.headers.get("content-type")?.toLowerCase().includes("text/event-stream")) {
    throw new ApiError(
      "The chat endpoint returned an unexpected response. Check the API proxy and server streaming setup.",
      502,
    );
  }
  if (!response.body) throw new Error("Streaming is unavailable.");
  const reader = response.body.getReader();
  const decoder = new TextDecoder();
  let buffer = "";
  let doneEvent = false;
  try {
    while (true) {
      const { value, done } = await reader.read();
      if (done) break;
      buffer += decoder.decode(value, { stream: true });
      let index;
      while ((index = buffer.indexOf("\n\n")) >= 0) {
        const frame = buffer.slice(0, index);
        buffer = buffer.slice(index + 2);
        const event = frame
          .split("\n")
          .find((line) => line.startsWith("event:"))
          ?.slice(6)
          .trim();
        const payload = frame
          .split("\n")
          .filter((line) => line.startsWith("data:"))
          .map((line) => line.slice(5).trim())
          .join("\n");
        if (event && payload) {
          onEvent(event, JSON.parse(payload));
          if (event === "done") doneEvent = true;
        }
      }
    }
    if (!doneEvent)
      throw new Error(
        "The stream disconnected. Check saved history for the partial response.",
      );
  } finally {
    reader.releaseLock();
  }
}
