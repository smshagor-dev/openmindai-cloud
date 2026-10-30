import { createHash, createHmac } from "node:crypto";
import { isIP } from "node:net";
import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
export const maxDuration = 180;

// Native Flutter clients use Laravel Sanctum bearer tokens, never web cookies.
// Keep this route separate from the browser /api/backend cookie/CSRF gateway.
const getRoutes = new Set([
  "status", "public-config", "me", "mobile/models",
  "media/image-capabilities",
]);
const mediaStatusRoute = /^media\/[1-9][0-9]{0,12}$/;
const mediaOutputRoute = /^media\/[1-9][0-9]{0,12}\/output$/;
const postRoutes = new Set([
  "login",
  "login/verify-ip",
  "register",
  "email/verify-code",
  "email/resend",
  "forgot-password",
  "reset-password",
  "logout",
  "mobile/chat/completions",
  "media/cloud-image",
]);
const publicRoutes = new Set([
  "status", "public-config", "login", "login/verify-ip", "register",
  "email/verify-code", "email/resend", "forgot-password", "reset-password",
]);
const maxJsonResponse = 1024 * 1024;
const identityRoutes = new Set([
  "status", "public-config",
  "login", "login/verify-ip", "register", "email/verify-code",
  "email/resend", "forgot-password", "reset-password",
]);

function signedMobileIdentity(request: NextRequest, target: URL, body: string) {
  const key = process.env.FRONTEND_CHAT_BRIDGE_KEY ?? "";
  // On Vercel, this header is set by its edge and is not an untrusted
  // caller-supplied X-Forwarded-For value.
  const forwarded = process.env.VERCEL === "1"
    ? request.headers.get("x-vercel-forwarded-for")
    : request.headers.get("x-forwarded-for");
  const ip = forwarded?.split(",")[0]?.trim() ?? "";
  if (key.length < 32 || isIP(ip) === 0) return null;
  const timestamp = Math.floor(Date.now() / 1000).toString();
  const data = [
    timestamp,
    ip,
    request.method,
    target.pathname,
    createHash("sha256").update(body).digest("hex"),
  ].join("\n");
  return {
    "X-OpenMind-Mobile-IP": ip,
    "X-OpenMind-Mobile-Time": timestamp,
    "X-OpenMind-Mobile-Signature":
      createHmac("sha256", key).update(data).digest("hex"),
  };
}


function json(payload: Record<string, unknown>, status = 200) {
  return NextResponse.json(payload, {
    status,
    headers: {
      "Cache-Control": "no-store, private",
      "X-Content-Type-Options": "nosniff",
    },
  });
}

// The host is server-controlled. Request URLs and bodies cannot change it.
function backendUrl(path: string) {
  const configured =
    process.env.LARAVEL_API_URL || "https://cloud.openmindai.org/api/v1";
  const base = new URL(configured.replace(/\/+$/, "") + "/");
  const loopback = ["127.0.0.1", "localhost", "[::1]"].includes(base.hostname);
  if (base.protocol !== "https:" && !(loopback && base.protocol === "http:")) {
    throw new Error("Invalid private Laravel API configuration");
  }
  if (!base.pathname.endsWith("/api/v1/")) {
    throw new Error("Laravel API URL must end in /api/v1");
  }
  return new URL(path, base);
}

async function limitedJsonBody(request: NextRequest, max: number) {
  if (!request.headers.get("content-type")?.toLowerCase().startsWith("application/json")) {
    return { error: json({ message: "JSON content type is required." }, 415) };
  }
  const length = Number(request.headers.get("content-length"));
  if (Number.isFinite(length) && length > max) {
    return { error: json({ message: "Mobile API request is too large." }, 413) };
  }
  if (!request.body) return { error: json({ message: "JSON body is required." }, 400) };
  const reader = request.body.getReader();
  const chunks: Uint8Array[] = [];
  let size = 0;
  try {
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > max) {
        await reader.cancel();
        return { error: json({ message: "Mobile API request is too large." }, 413) };
      }
      chunks.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  const text = Buffer.concat(chunks.map((part) => Buffer.from(part))).toString("utf8");
  try {
    const parsed: unknown = JSON.parse(text);
    if (parsed === null || typeof parsed !== "object" || Array.isArray(parsed)) {
      return { error: json({ message: "A JSON object is required." }, 400) };
    }
    return { text };
  } catch {
    return { error: json({ message: "Invalid JSON." }, 400) };
  }
}

async function limitedResponse(response: Response) {
  if (!response.body) return "";
  const reader = response.body.getReader();
  const parts: Uint8Array[] = [];
  let size = 0;
  try {
    for (;;) {
      const { value, done } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > maxJsonResponse) {
        await reader.cancel();
        throw new Error("Oversized upstream response");
      }
      parts.push(value);
    }
  } finally {
    reader.releaseLock();
  }
  return Buffer.concat(parts.map((item) => Buffer.from(item))).toString("utf8");
}

function firewallDetected(text: string) {
  const value = text.toLowerCase();
  return value.includes("imunify360") &&
    (value.includes("bot-protection") ||
     value.includes("access denied") ||
     value.includes("whitelisted"));
}

function upstreamIssue(status: number, content: string) {
  if (status === 403 && firewallDetected(content)) {
    return json({
      message: "The Laravel hosting firewall also blocks the OpenMindAI gateway. The hosting administrator must allow this API connection.",
      code: "HOSTING_FIREWALL_BLOCKED",
    }, 503);
  }
  if (status >= 300 && status < 400) {
    return json({ message: "The Laravel API redirected instead of returning JSON." }, 502);
  }
  return json({
    message: "The Laravel API returned an unexpected non-JSON response.",
  }, status >= 500 ? 502 : status >= 400 ? status : 502);
}

async function mobile(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
) {
  const endpoint = (await context.params).path.join("/");
  const isGet = request.method === "GET";
  const mediaStatus = isGet && mediaStatusRoute.test(endpoint);
  const imageOutput = isGet && mediaOutputRoute.test(endpoint);
  if (!(isGet ? getRoutes : postRoutes).has(endpoint) &&
      !mediaStatus && !imageOutput) {
    return json({ message: "Not found." }, 404);
  }
  if (request.nextUrl.search) {
    return json({ message: "Query parameters are not supported." }, 400);
  }
  // Do not share this native bearer-token service with the web's cookie
  // session route or allow a browser to use its ambient web cookies.
  if (request.headers.has("origin")) {
    return json({ message: "The native API does not accept browser origins." }, 403);
  }
  const isPublic = publicRoutes.has(endpoint);
  const authorization = request.headers.get("authorization") ?? "";
  if (!isPublic && !/^Bearer [^\s]{10,4096}$/.test(authorization)) {
    return json({ message: "Sign in to use OpenMindAI Cloud." }, 401);
  }
  if (isPublic && authorization) {
    return json({ message: "Authorization is not required for this operation." }, 400);
  }

  let body: string | undefined;
  if (!isGet) {
    const parsed = await limitedJsonBody(
      request, endpoint === "mobile/chat/completions" ? 1024 * 1024 : 32 * 1024,
    );
    if (parsed.error) return parsed.error;
    body = parsed.text;
  }

  try {
    // status uses the same real Laravel endpoint as public-config, with a
    // sanitized result that never exposes hostnames, cookies or credentials.
    const target = endpoint === "status" ? "public-config" : endpoint;
    const upstreamUrl = backendUrl(target);
    const identity = (identityRoutes.has(endpoint) ||
        endpoint === "media/cloud-image" ||
        endpoint === "media/image-capabilities" ||
        mediaStatus || imageOutput)
      ? signedMobileIdentity(request, upstreamUrl, body ?? "")
      : {};
    if (!identity) {
      return json({
        message: "The Mobile gateway requires its existing shared backend "
          + "bridge key and a verified client IP.",
        code: "MOBILE_IDENTITY_NOT_CONFIGURED",
      }, 503);
    }
    const upstream = await fetch(upstreamUrl, {
      method: request.method,
      body,
      headers: {
        Accept: target === "mobile/chat/completions"
          ? "text/event-stream" : imageOutput
          ? "image/png,image/jpeg" : "application/json",
        ...(isGet ? {} : { "Content-Type": "application/json" }),
        ...(!isPublic ? { Authorization: authorization } : {}),
        ...identity,
      },
      cache: "no-store",
      redirect: "manual",
      signal: AbortSignal.any([
        request.signal,
        AbortSignal.timeout(target === "mobile/chat/completions" ? 150000 : 30000),
      ]),
    });

    // Avoid buffering streamed content. Pass cancellation through to Laravel.
    if (target === "mobile/chat/completions" && upstream.ok) {
      if (!upstream.headers.get("content-type")?.includes("text/event-stream") ||
          !upstream.body) {
        return json({ message: "The Cloud inference endpoint did not return a stream." }, 502);
      }
      return new Response(upstream.body, {
        status: 200,
        headers: {
          "Content-Type": "text/event-stream; charset=utf-8",
          "Cache-Control": "private, no-store, no-transform",
          "X-Accel-Buffering": "no",
          "X-Content-Type-Options": "nosniff",
        },
      });
    }

    if (imageOutput && upstream.ok) {
      const mime = (upstream.headers.get("content-type") ?? "").split(";")[0].trim();
      const length = Number(upstream.headers.get("content-length") ?? "0");
      if (!["image/png", "image/jpeg"].includes(mime) ||
          !upstream.body || (length > 0 && length > 25 * 1024 * 1024)) {
        return json({ message: "The Cloud image output is not a supported file." }, 502);
      }
      // Keep the media private. Relay bytes only for an authenticated
      // request; do not expose a public storage URL or Set-Cookie.
      // Stream with a hard byte cap even when upstream omits Content-Length.
      let forwardedBytes = 0;
      const safeStream = upstream.body.pipeThrough(
        new TransformStream<Uint8Array, Uint8Array>({
          transform(chunk, controller) {
            forwardedBytes += chunk.byteLength;
            if (forwardedBytes > 25 * 1024 * 1024) {
              throw new Error("Cloud image exceeded the media size limit");
            }
            controller.enqueue(chunk);
          },
        }),
      );
      return new Response(safeStream, {
        status: 200,
        headers: {
          "Content-Type": mime,
          "Cache-Control": "private, no-store",
          "X-Content-Type-Options": "nosniff",
        },
      });
    }

    const raw = await limitedResponse(upstream);
    if (endpoint === "status") {
      if (upstream.ok && upstream.headers.get("content-type")?.includes("application/json")) {
        try {
          const data: unknown = JSON.parse(raw);
          if (data && typeof data === "object" && "registration_enabled" in data) {
            return json({ gateway: "ok", backend: "connected" });
          }
        } catch { /* Return an explicit failure status below. */ }
      }
      return json({
        gateway: "ok", backend: "unavailable",
        reason: upstream.status === 403 && firewallDetected(raw)
          ? "HOSTING_FIREWALL_BLOCKED" : "INVALID_BACKEND_RESPONSE",
      }, 503);
    }
    if (upstream.status === 204) {
      return new Response(null, {
        status: 204,
        headers: { "Cache-Control": "private, no-store" },
      });
    }
    if (!upstream.headers.get("content-type")?.includes("application/json")) {
      return upstreamIssue(upstream.status, raw);
    }
    let payload: unknown;
    try {
      payload = JSON.parse(raw);
    } catch {
      return json({ message: "The Laravel API returned invalid JSON." }, 502);
    }
    if (!payload || typeof payload !== "object" || Array.isArray(payload)) {
      return json({ message: "The Laravel API returned unexpected JSON." }, 502);
    }

    const response = json(payload as Record<string, unknown>, upstream.status);
    const retryAfter = upstream.headers.get("retry-after");
    if (retryAfter && /^\d{1,6}$/.test(retryAfter)) {
      response.headers.set("Retry-After", retryAfter);
    }
    // Unlike the browser gateway, native login MUST receive its own Sanctum
    // token and store it in the device's encrypted keychain. No Set-Cookie.
    return response;
  } catch {
    return json({
      message: "The mobile gateway cannot reach the Laravel backend. Please retry later.",
      code: "BACKEND_UNAVAILABLE",
    }, 503);
  }
}

export { mobile as GET, mobile as POST };
