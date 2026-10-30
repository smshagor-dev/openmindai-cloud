import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
export const maxDuration = 180;

const uuid = "[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}";
const routes: Record<string, RegExp> = {
  GET: new RegExp("^(models|catalog|cosmos/" + uuid + "(?:/download)?)$", "i"),
  POST: /^(chat\/completions|models\/[a-z0-9][a-z0-9._-]{0,100}\/invoke|cosmos\/generations)$/
};

function fail(message: string, status: number) {
  return NextResponse.json({ error: { message, type: "gateway_error" } }, {
    status,
    headers: { "Cache-Control": "private, no-store", "X-Content-Type-Options": "nosniff" }
  });
}

async function readJson(request: NextRequest, maxBytes: number): Promise<string | NextResponse> {
  if (!request.headers.get("content-type")?.toLowerCase().startsWith("application/json")) {
    return fail("Content-Type must be application/json.", 415);
  }
  if (Number(request.headers.get("content-length") || 0) > maxBytes) {
    return fail("Request exceeds the supported size.", 413);
  }
  const reader = request.body?.getReader();
  if (!reader) return fail("JSON body is required.", 400);
  const chunks: Uint8Array[] = [];
  let total = 0;
  while (true) {
    const { done, value } = await reader.read();
    if (done) break;
    total += value.byteLength;
    if (total > maxBytes) {
      await reader.cancel();
      return fail("Request exceeds the supported size.", 413);
    }
    chunks.push(value);
  }
  const raw = Buffer.concat(chunks).toString("utf8");
  try {
    const json: unknown = JSON.parse(raw);
    if (json === null || typeof json !== "object" || Array.isArray(json)) {
      return fail("A JSON object is required.", 400);
    }
  } catch {
    return fail("Invalid JSON request.", 400);
  }
  return raw;
}

export async function handler(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> }
) {
  const { path } = await context.params;
  const endpoint = path.join("/");
  if (!routes[request.method]?.test(endpoint)) {
    return fail("Endpoint not found.", 404);
  }

  // Portal login cookies never grant inference access.
  const match = /^Bearer ([^\s]{12,4096})$/i.exec(request.headers.get("authorization") || "");
  if (!match) return fail("A valid Bearer developer API key is required.", 401);

  const backend = process.env.LARAVEL_API_URL;
  if (!backend || (process.env.NODE_ENV === "production" && !backend.startsWith("https://"))) {
    return fail("The API backend is not configured.", 503);
  }
  let target: URL;
  try {
    target = new URL(backend.replace(/\/+$/, "") + "/openmindai/" + endpoint);
    if (!["http:", "https:"].includes(target.protocol) ||
        !target.pathname.startsWith("/api/v1/openmindai/")) {
      return fail("The API backend URL is invalid.", 503);
    }
  } catch {
    return fail("The API backend URL is invalid.", 503);
  }

  let body: string | undefined;
  if (request.method === "POST") {
    const size = endpoint === "chat/completions" ? 1_048_576
      : endpoint === "cosmos/generations" ? 16_384 : 262_144;
    const parsed = await readJson(request, size);
    if (parsed instanceof NextResponse) return parsed;
    body = parsed;
  }

  // No cookies, untrusted forwarding headers, Origin, or client URLs reach Laravel.
  try {
    const upstream = await fetch(target, {
      method: request.method,
      body,
      headers: {
        "Authorization": "Bearer " + match[1],
        "Accept": endpoint === "chat/completions" ? "application/json, text/event-stream" : "application/json",
        ...(body === undefined ? {} : { "Content-Type": "application/json" })
      },
      cache: "no-store",
      redirect: "error",
      signal: AbortSignal.any([request.signal, AbortSignal.timeout(175_000)])
    });

    const contentType = upstream.headers.get("content-type")?.toLowerCase() || "";
    const headers = new Headers({
      "Cache-Control": "private, no-store, no-transform",
      "X-Content-Type-Options": "nosniff"
    });
    const retry = upstream.headers.get("retry-after");
    if (retry) headers.set("Retry-After", retry);

    if (upstream.ok && contentType.includes("text/event-stream") && endpoint === "chat/completions") {
      headers.set("Content-Type", "text/event-stream; charset=utf-8");
      headers.set("X-Accel-Buffering", "no");
      return new Response(upstream.body, { status: upstream.status, headers });
    }

    if (upstream.ok && endpoint.endsWith("/download")) {
      const mime = contentType.split(";")[0];
      if (mime !== "video/mp4" && mime !== "image/jpeg") {
        await upstream.body?.cancel();
        return fail("Unexpected media format from inference backend.", 502);
      }
      headers.set("Content-Type", mime);
      headers.set("Content-Disposition", 'attachment; filename="openmindai-cosmos.' + (mime === "video/mp4" ? "mp4" : "jpg") + '"');
      return new Response(upstream.body, { status: upstream.status, headers });
    }

    if (!contentType.includes("application/json") && !contentType.includes("+json")) {
      await upstream.body?.cancel();
      return fail("The inference backend returned an unexpected response.", 502);
    }
    if (Number(upstream.headers.get("content-length") || 0) > 2_100_000) {
      await upstream.body?.cancel();
      return fail("Model response is too large.", 502);
    }
    const raw = await upstream.text();
    if (Buffer.byteLength(raw) > 2_100_000) return fail("Model response is too large.", 502);
    let result: unknown;
    try { result = raw ? JSON.parse(raw) : null; }
    catch { return fail("Invalid JSON response from backend.", 502); }

    headers.set("Content-Type", "application/json; charset=utf-8");
    return new Response(JSON.stringify(result), { status: upstream.status, headers });
  } catch {
    if (request.signal.aborted) return fail("Client disconnected.", 499);
    return fail("Cannot connect to the inference backend.", 503);
  }
}
export { handler as GET, handler as POST };
