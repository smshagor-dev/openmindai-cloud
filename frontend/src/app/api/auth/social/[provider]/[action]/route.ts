import { createHash, createHmac, randomBytes, timingSafeEqual } from "node:crypto";
import { isIP } from "node:net";
import { NextRequest, NextResponse } from "next/server";
import { webCookieOptions, webSessionCookie, webSessionOptions } from "@/lib/web-session";

export const runtime = "nodejs";
const flowCookie = "openmindai_social_flow";
const pendingCookie = "openmindai_social_pending";
const cookiePath = "/api/auth/social";
const flowOptions = { ...webCookieOptions, path: cookiePath, maxAge: 600 };
const expiresAt = () => Date.now() + flowOptions.maxAge * 1000;
const providers = ["google", "facebook"];
const steps = ["email", "two-factor", "ip"];
type Context = { params: Promise<{ provider: string; action: string }> };
const validChallenge = (value: unknown): value is string => typeof value === "string" && /^[a-f0-9]{64}$/.test(value);
const digest = (value: string) => createHash("sha256").update(value).digest("hex");

function signature(value: string) {
  const key = process.env.FRONTEND_CHAT_BRIDGE_KEY || "";
  if (key.length < 32) throw new Error("Social sign-in is not configured");
  return createHmac("sha256", key).update("openmindai-social-cookie:" + value).digest("base64url");
}

function seal(value: object) {
  const body = Buffer.from(JSON.stringify(value)).toString("base64url");
  return body + "." + signature(body);
}

function open<T>(value?: string): T | null {
  try {
    if (!value) return null;
    const [body, mac, extra] = value.split(".");
    if (extra !== undefined || !/^[A-Za-z0-9_-]{43}$/.test(mac || "")) return null;
    if (!timingSafeEqual(Buffer.from(mac), Buffer.from(signature(body)))) return null;
    const decoded = JSON.parse(Buffer.from(body, "base64url").toString("utf8"));
    return Number.isSafeInteger(decoded?.expires) && decoded.expires > Date.now() ? decoded : null;
  } catch {
    return null;
  }
}

function frontendOrigin(request: NextRequest) {
  return new URL(process.env.FRONTEND_URL || request.nextUrl.origin).origin;
}

function redirect(request: NextRequest, path: string) {
  const response = NextResponse.redirect(new URL(path, frontendOrigin(request)), 303);
  response.headers.set("Cache-Control", "no-store");
  response.headers.set("Referrer-Policy", "no-referrer");
  return response;
}

function expiredCookie(response: NextResponse, name: string) {
  response.cookies.set(name, "", { ...flowOptions, maxAge: 0 });
}

function errorRedirect(request: NextRequest, error: string) {
  const response = redirect(request, `/social-sign-in?error=${encodeURIComponent(error)}`);
  expiredCookie(response, flowCookie);
  return response;
}

async function backend(request: NextRequest, provider: string, action: string, payload: object, token?: string) {
  const key = process.env.FRONTEND_CHAT_BRIDGE_KEY || "";
  if (key.length < 32) throw new Error("Social sign-in is not configured");
  const base = process.env.LARAVEL_API_URL || "https://cloud.openmindai.org/api/v1";
  const target = new URL(`${base.replace(/\/$/, "")}${action === "approve-mobile" ? "/mobile/social/approve" : `/social/${provider}/${action}`}`);
  const body = JSON.stringify(payload);
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
    "X-Chat-Bridge-Key": key,
  };
  if (token) headers.Authorization = `Bearer ${token}`;
  const forwarded = process.env.VERCEL === "1"
    ? request.headers.get("x-vercel-forwarded-for")
    : process.env.TRUST_PROXY_HEADERS === "true" ? request.headers.get("x-forwarded-for") : null;
  const ip = forwarded?.split(",")[0].trim() || "";
  if (isIP(ip)) {
    const time = Math.floor(Date.now() / 1000).toString();
    headers["X-OpenMind-Mobile-IP"] = ip;
    headers["X-OpenMind-Mobile-Time"] = time;
    headers["X-OpenMind-Mobile-Signature"] = createHmac("sha256", key)
      .update([time, ip, "POST", target.pathname, digest(body)].join("\n")).digest("hex");
  }
  const response = await fetch(target, {
    method: "POST", headers, body, redirect: "error", cache: "no-store",
    signal: AbortSignal.timeout(25000),
  });
  const data = response.status === 204 ? {} : await response.json();
  return { ok: response.ok, status: response.status, data };
}

function updateSession(response: NextResponse, provider: string, data: { token?: string; ticket?: string; step?: string; linked?: boolean }, mobileChallenge?: string) {
  if (typeof data.token === "string") {
    response.cookies.set(webSessionCookie, data.token, webSessionOptions);
    expiredCookie(response, pendingCookie);
  } else if (typeof data.ticket === "string" && steps.includes(data.step || "")) {
    response.cookies.set(pendingCookie, seal({ provider, ticket: data.ticket, mobileChallenge, expires: expiresAt() }), flowOptions);
  } else if (data.linked) {
    expiredCookie(response, pendingCookie);
  }
  return response;
}

export async function POST(request: NextRequest, context: Context) {
  const { provider, action } = await context.params;
  if (!providers.includes(provider) || !["start", "complete"].includes(action)) {
    return new NextResponse(null, { status: 404 });
  }
  if (request.headers.get("origin") !== frontendOrigin(request)) {
    return new NextResponse(null, { status: 403 });
  }
  try {
    if (action === "start") {
      const form = await request.formData();
      const link = form.get("intent") === "link";
      const challenge = form.get("mobile_challenge");
      if (challenge !== null && (link || !validChallenge(challenge))) return errorRedirect(request, "expired");
      const mobileChallenge = validChallenge(challenge) ? challenge : undefined;
      const token = request.cookies.get(webSessionCookie)?.value;
      if (link && !token) return errorRedirect(request, "sign-in-required");
      const state = randomBytes(32).toString("hex");
      const verifier = randomBytes(32).toString("hex");
      const upstream = await backend(request, provider, "start", {
        state, challenge: createHash("sha256").update(verifier).digest("base64url"),
      });
      if (!upstream.ok || typeof upstream.data.url !== "string") return errorRedirect(request, "unavailable");
      const target = new URL(upstream.data.url);
      const expected = provider === "google" ? "https://accounts.google.com" : "https://www.facebook.com";
      if (target.origin !== expected) return errorRedirect(request, "unavailable");
      const response = redirect(request, target.href);
      response.cookies.set(flowCookie, seal({ provider, state, verifier, session: link && token ? digest(token) : null, mobileChallenge, expires: expiresAt() }), flowOptions);
      expiredCookie(response, pendingCookie);
      return response;
    }

    const pending = open<{ provider: string; ticket: string; mobileChallenge?: string }>(request.cookies.get(pendingCookie)?.value);
    if (pending?.provider !== provider || !/^[a-f0-9]{64}$/.test(pending.ticket)) {
      return NextResponse.json({ message: "Sign-in expired. Start again." }, { status: 422 });
    }
    const { code } = await request.json();
    if (typeof code !== "string" || !code.trim() || code.length > 64) {
      return NextResponse.json({ message: "Enter a valid verification code." }, { status: 422 });
    }
    const { ok, status, data } = await backend(request, provider, "complete", { ticket: pending.ticket, code });
    if (!ok) {
      const message = status === 429 ? "Too many attempts. Try again later."
        : status === 409 ? "Sign in to your existing account to link this provider."
        : status === 422 ? "The code was invalid or the sign-in expired."
        : "Could not finish signing in. Start again.";
      return NextResponse.json({ message }, { status });
    }
    if (data.token && validChallenge(pending.mobileChallenge)) {
      const approved = await backend(request, provider, "approve-mobile", { challenge: pending.mobileChallenge }, data.token);
      if (!approved.ok) return NextResponse.json({ message: "Could not connect the mobile app. Start again." }, { status: 503 });
    }
    const response = NextResponse.json({ mobileConnected: Boolean(data.token && pending.mobileChallenge), signedIn: typeof data.token === "string", step: data.step }, {
      headers: { "Cache-Control": "no-store" },
    });
    return updateSession(response, provider, data, pending.mobileChallenge);
  } catch {
    return action === "start" ? errorRedirect(request, "unavailable")
      : NextResponse.json({ message: "Could not finish signing in. Try again." }, { status: 503 });
  }
}

export async function GET(request: NextRequest, context: Context) {
  const { provider, action } = await context.params;
  if (!providers.includes(provider) || action !== "callback") return new NextResponse(null, { status: 404 });
  const flow = open<{ provider: string; state: string; verifier: string; session: string | null; mobileChallenge?: string }>(request.cookies.get(flowCookie)?.value);
  const state = request.nextUrl.searchParams.get("state") || "";
  const code = request.nextUrl.searchParams.get("code") || "";
  if (flow?.provider !== provider || !/^[a-f0-9]{64}$/.test(state) || !/^[a-f0-9]{64}$/.test(flow.state) ||
    !timingSafeEqual(Buffer.from(state), Buffer.from(flow.state)) || !/^[a-f0-9]{64}$/.test(flow.verifier)) {
    return errorRedirect(request, "expired");
  }
  if (!code || code.length > 4096) return errorRedirect(request, "cancelled");
  const token = request.cookies.get(webSessionCookie)?.value;
  if (flow.session && (!token || digest(token) !== flow.session)) return errorRedirect(request, "sign-in-required");
  try {
    const { ok, status, data } = await backend(request, provider, "exchange", {
      code, verifier: flow.verifier, link: Boolean(flow.session),
    }, flow.session ? token : undefined);
    if (!ok) return errorRedirect(request, status === 409 ? "existing-account" : "unavailable");
    if (data.token && validChallenge(flow.mobileChallenge)) {
      const approved = await backend(request, provider, "approve-mobile", { challenge: flow.mobileChallenge }, data.token);
      if (!approved.ok) return errorRedirect(request, "unavailable");
    }
    const response = redirect(request, data.token ? (flow.mobileChallenge ? "/mobile-sign-in?connected=1" : "/") : data.linked ? "/social-sign-in?linked=1"
      : steps.includes(data.step) ? `/social-sign-in?provider=${provider}&step=${data.step}`
        : "/social-sign-in?error=unavailable");
    expiredCookie(response, flowCookie);
    return updateSession(response, provider, data, flow.mobileChallenge);
  } catch {
    return errorRedirect(request, "unavailable");
  }
}
