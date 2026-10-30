import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
const cookieName = "om_developer_session";

const allowed: Record<string, RegExp> = {
  GET: /^(me|public-config|developer\/keys|developer\/catalog|developer\/usage)$/,
  POST: /^(register|login|login\/verify-ip|email\/resend|email\/verify-code|forgot-password|developer\/keys|logout)$/,
  DELETE: /^developer\/keys\/[0-9]{1,18}$/
};
const publicRoutes = new Set(["public-config", "register", "login", "login/verify-ip", "forgot-password", "email/resend", "email/verify-code"]);
const authRoutes = new Set(["register", "login", "login/verify-ip"]);

export async function handler(request: NextRequest, context: { params: Promise<{ path: string[] }> }) {
  const { path } = await context.params;
  const endpoint = path.join("/");
  if (!allowed[request.method]?.test(endpoint)) {
    return NextResponse.json({ message: "Not found." }, { status: 404 });
  }
  if (request.method !== "GET") {
    // Vercel production and preview hostnames each use their own browser origin.
    // Compare against the validated deployment host instead of a production-only
    // PORTAL_ORIGIN, otherwise preview sign-in and key creation return 403.
    const expectedOrigin = process.env.VERCEL === "1"
      ? request.nextUrl.origin
      : process.env.PORTAL_ORIGIN || request.nextUrl.origin;
    if (request.headers.get("origin") !== expectedOrigin) {
      return NextResponse.json({ message: "Invalid request origin." }, { status: 403 });
    }
    if (!request.headers.get("content-type")?.startsWith("application/json")) {
      return NextResponse.json({ message: "JSON is required." }, { status: 415 });
    }
  }
  const session = request.cookies.get(cookieName)?.value;
  if (!session && !publicRoutes.has(endpoint)) {
    return NextResponse.json({ message: "Sign in to continue." }, { status: 401 });
  }
  const base = process.env.LARAVEL_API_URL;
  if (!base || !/^https:\/\//.test(base) && process.env.NODE_ENV === "production") {
    return NextResponse.json({ message: "Developer API backend is not configured." }, { status: 503 });
  }
  let body: string | undefined;
  if (request.method !== "GET") {
    const reader = request.body?.getReader();
    const chunks: Uint8Array[] = [];
    let length = 0;
    if (reader) {
      while (true) {
        const { done, value } = await reader.read();
        if (done) break;
        length += value.byteLength;
        if (length > 16_384) {
          await reader.cancel();
          return NextResponse.json({ message: "Request too large." }, { status: 413 });
        }
        chunks.push(value);
      }
    }
    body = Buffer.concat(chunks).toString("utf8");
    try { JSON.parse(body); } catch {
      return NextResponse.json({ message: "Invalid JSON." }, { status: 400 });
    }
  }
  try {
    const target = new URL(base!.replace(/\/+$/, "") + "/" + endpoint);
    const backend = await fetch(target, {
      method: request.method,
      headers: {
        "Accept": "application/json",
        ...(body !== undefined ? { "Content-Type": "application/json" } : {}),
        ...(session && !publicRoutes.has(endpoint) ? { Authorization: "Bearer " + session } : {}),
        ...(process.env.TRUST_PROXY_HEADERS === "true" && request.headers.get("x-forwarded-for")
          ? { "X-Forwarded-For": request.headers.get("x-forwarded-for")! }
          : {})
      },
      body,
      cache: "no-store",
      redirect: "error",
      signal: AbortSignal.timeout(20_000)
    });
    const raw = await backend.text();
    if (raw.length > 1_000_000) throw new Error("Oversized API response");
    let data: Record<string, unknown> = {};
    try { data = raw ? JSON.parse(raw) : {}; } catch { throw new Error("Non-JSON API response"); }
    // Public developer requests must use this portal, never Laravel directly.
    if (endpoint === "developer/keys" && backend.ok) {
      const portal = new URL(process.env.VERCEL_ENV === "preview"
        ? request.nextUrl.origin
        : process.env.PORTAL_ORIGIN || request.nextUrl.origin);
      if (process.env.NODE_ENV === "production" && portal.protocol !== "https:") {
        return NextResponse.json({ message: "The public portal origin must use HTTPS." }, { status: 503 });
      }
      data.base_url = portal.origin + "/api/v1/openmindai";
    }
    const response = backend.status === 204
      ? new NextResponse(null, { status: 204 })
      : NextResponse.json(data, { status: backend.status });
    response.headers.set("Cache-Control", "no-store, private");
    response.headers.set("X-Content-Type-Options", "nosniff");
    if (authRoutes.has(endpoint) && backend.ok && typeof data.token === "string") {
      response.cookies.set(cookieName, data.token, {
        httpOnly: true, secure: process.env.NODE_ENV === "production",
        sameSite: "lax", path: "/", maxAge: 30 * 24 * 60 * 60
      });
      delete data.token;
      // Regenerate the response so the bearer secret never appears in JSON.
      const safe = NextResponse.json(data, { status: backend.status });
      response.cookies.getAll().forEach((cookie) => safe.cookies.set(cookie));
      safe.headers.set("Cache-Control", "no-store, private");
      safe.headers.set("X-Content-Type-Options", "nosniff");
      return safe;
    }
    if ((endpoint === "logout" && backend.ok) || backend.status === 401) {
      response.cookies.delete(cookieName);
    }
    const retry = backend.headers.get("retry-after");
    if (retry) response.headers.set("Retry-After", retry);
    return response;
  } catch {
    return NextResponse.json({ message: "Cannot connect to the Laravel API." }, { status: 503 });
  }
}
export { handler as GET, handler as POST, handler as DELETE };
