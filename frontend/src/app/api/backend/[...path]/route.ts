import { studioRoute, studioDownload } from "@/lib/studio-proxy";
import { researchRoute, researchDownload } from "@/lib/research-proxy";
import { createHmac } from "node:crypto";
import {
  nvidiaChat,
  hostedCatalog,
  nvidiaEnabled,
  nvidiaModels,
  isNvidiaModelId,
} from "@/lib/nvidia-chat";
import { generateHostedImage } from "@/lib/hosted-image";
import {
  hostedMediaRequest,
  hostedMediaEnabled,
  hostedDocumentEnabled,
} from "@/lib/hosted-media";
import { cookies } from "next/headers";
import { NextRequest, NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";
// Vercel Fluid Compute (Hobby): allow hosted chat, research and media to finish.
// This is an upper bound, not a guarantee when Laravel or NVIDIA time out.
export const maxDuration = 300;
const cookieName = "openmindai_token";
const publicPaths = new Set([
  "guest/chat",
  "login",
  "login/verify-ip",
  "register",
  "public-config",
  "forgot-password",
  "reset-password",
  "email/resend",
  "email/verify-code",
  "email/change/confirm",
]);
const routes: Record<string, RegExp> = {
  GET: /^(3d-scenes(?:\/uploads(?:\/\d+)?|\/\d+)?|developer\/keys|public-config|teams(?:\/\d+(?:\/(documents|threads)\/\d+)?)?|connectors|explore\/assistants(?:\/[a-f0-9-]{36})?|assistants|tasks(?:\/\d+\/runs)?|canvases\/\d+\/versions(?:\/\d+)?|conversations\/\d+\/branches|media(?:\/image-capabilities|\/\d+(?:\/output)?)?|me|models|tokens|capabilities|preferences|projects|memories|attachments(?:\/\d+)?|canvases(?:\/\d+)?|shares|export|shared\/[a-f0-9]{64}|conversations(?:\/\d+)?)$/,
  POST: /^(3d-scenes(?:\/uploads)?|guest\/chat|developer\/keys|teams(?:\/\d+\/(preview|invites|documents|threads(?:\/\d+)?))?|team-invites\/\d+\/accept|connectors\/[a-zA-Z0-9_-]{1,80}\/(connect|tools|call)|live\/(start|input|stop)|explore\/assistants\/[a-f0-9-]{36}\/copy|assistants(?:\/\d+\/publish)?|tasks(?:\/\d+\/runs(?:\/\d+\/resume)?)?|me\/(avatar|email)|email\/change\/confirm|settings\/data|media(?:\/(?:image|transcription|speech|video|document_create))?|login(?:\/verify-ip)?|register|forgot-password|reset-password|email\/resend|email\/verify-code|logout|logout-all|web-search|chat(?:\/(stream|resume))?|projects|memories|attachments|canvases|conversations\/\d+\/(branch|share)|conversations\/\d+\/messages\/\d+\/stop|2fa\/(setup|confirm|recovery-codes))$/,
  PATCH: /^(me|preferences|conversations\/\d+)$/,
  PUT: /^(teams\/\d+\/(members|documents)\/\d+|assistants\/\d+|tasks\/\d+|me\/password|projects\/\d+|canvases\/\d+|memories\/\d+|conversations\/\d+\/messages\/\d+\/feedback)$/,
  DELETE:
    /^(3d-scenes\/\d+|developer\/keys\/\d+|teams\/\d+(?:\/(invites|members|documents|threads)\/\d+)?|connectors\/[a-zA-Z0-9_-]{1,80}|connector-calls\/\d+|assistants\/\d+(?:\/publish)?|tasks\/\d+(?:\/runs\/\d+)?|me\/(avatar|email)|media\/\d+|me|2fa|tokens\/\d+|conversations\/\d+|projects\/\d+|canvases\/\d+|memories\/\d+|attachments\/\d+|shares\/\d+)$/,
};
async function proxy(
  request: NextRequest,
  context: { params: Promise<{ path: string[] }> },
) {
  const { path } = await context.params;
  const endpoint = path.join("/");
  if (
    !routes[request.method]?.test(endpoint) &&
    !researchRoute(request.method, endpoint) &&
    !studioRoute(request.method, endpoint)
  )
    return NextResponse.json({ message: "Not found." }, { status: 404 });
  if (!["GET", "HEAD"].includes(request.method)) {
    // Each Vercel preview has its own origin. A production-only FRONTEND_URL
    // must not break preview login and authenticated mutation requests.
    // On Vercel, request.nextUrl.origin is the request's own trusted origin.
    const expectedOrigin =
      process.env.VERCEL === "1"
        ? request.nextUrl.origin
        : new URL(process.env.FRONTEND_URL || request.url).origin;
    if (request.headers.get("origin") !== expectedOrigin)
      return NextResponse.json(
        { message: "Invalid request origin." },
        { status: 403 },
      );
    if (!request.headers.get("content-type")?.startsWith("application/json"))
      return NextResponse.json(
        { message: "JSON is required." },
        { status: 415 },
      );
  }
  const jar = await cookies();
  const token = jar.get(cookieName)?.value;
  const isPublic =
    publicPaths.has(endpoint) ||
    endpoint.startsWith("shared/") ||
    (request.method === "GET" &&
      /^explore\/assistants(?:\/[a-f0-9-]{36})?$/.test(endpoint));
  if (!token && !isPublic)
    return NextResponse.json({ message: "Please sign in." }, { status: 401 });
  const base =
    process.env.LARAVEL_API_URL || "https://cloud.openmindai.org/api/v1";
  if (!base)
    return NextResponse.json(
      { message: "The backend connection has not been configured." },
      { status: 503 },
    );
  let body: string | undefined;
  if (request.method !== "GET") {
    const reader = request.body?.getReader();
    const chunks: Uint8Array[] = [];
    let size = 0;
    if (reader) {
      while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        size += value.byteLength;
        if (
          size >
          (endpoint === "3d-scenes/uploads" || endpoint === "3d-studio/assets"
            ? 4_500_000
            : endpoint.startsWith("3d-scenes/") ||
                endpoint === "3d-scenes" ||
                endpoint.startsWith("3d-studio/")
              ? 150_000
              : endpoint.startsWith("research/")
                ? 12_000_000
                : endpoint === "media" ||
                    endpoint === "media/image" ||
                    endpoint === "live/input" ||
                    endpoint === "media/transcription"
                  ? 11_000_000
                  : endpoint === "chat/stream" && nvidiaEnabled()
                    ? 6_000_000 // one 4 MB image plus conversation metadata
                    : endpoint === "me/avatar"
                      ? 130_000
                      : 100_000)
        ) {
          await reader.cancel();
          return NextResponse.json(
            { message: "Request is too large." },
            { status: 413 },
          );
        }
        chunks.push(value);
      }
    }
    body = Buffer.concat(chunks).toString("utf8");
    try {
      JSON.parse(body);
    } catch {
      return NextResponse.json({ message: "Invalid JSON." }, { status: 400 });
    }
  }
  if (
    [
      "media/transcription",
      "media/speech",
      "media/document_create",
      "media/video",
    ].includes(endpoint) &&
    request.method === "POST"
  ) {
    const kind = endpoint.slice("media/".length);
    const data = JSON.parse(body!);
    // Never trust the browser's requested engine or provider URL.
    const response = await hostedMediaRequest(
      JSON.stringify({
        kind,
        prompt: data.prompt ?? "",
        input: data.input,
        format: data.format,
        language: data.language,
      }),
      token!,
      base,
      request.signal,
    );
    if (response.status === 401) jar.delete(cookieName);
    return response;
  }
  if (endpoint === "media/image" && request.method === "POST") {
    const response = await generateHostedImage(
      body!,
      token!,
      base,
      request.signal,
    );
    if (response.status === 401) jar.delete(cookieName);
    return response;
  }
  if (
    (endpoint === "chat/stream" || endpoint === "chat/resume") &&
    request.method === "POST" &&
    nvidiaEnabled() &&
    (endpoint === "chat/resume" ||
      JSON.parse(body!)?.model_id == null ||
      isNvidiaModelId(Number(JSON.parse(body!)?.model_id)))
  ) {
    const response = await nvidiaChat(body!, token!, base, request.signal);
    if (response.status === 401) jar.delete(cookieName);
    return response;
  }
  if (endpoint === "models" && request.method === "GET" && nvidiaEnabled()) {
    // Authenticate against Laravel before exposing the frontend-hosted model.
    try {
      const response = await fetch(`${base.replace(/\/$/, "")}/models`, {
        headers: {
          Authorization: `Bearer ${token}`,
          Accept: "application/json",
        },
        cache: "no-store",
        redirect: "error",
        signal: AbortSignal.timeout(10000),
      });
      if (response.status === 401) jar.delete(cookieName);
      if (!response.ok)
        return NextResponse.json(
          { message: "Cannot load models." },
          { status: response.status },
        );
      const local = await response.json();
      const catalogResponse = await fetch(
        `${base.replace(/\/$/, "")}/chat/hosted-models`,
        {
          headers: {
            Authorization: `Bearer ${token}`,
            Accept: "application/json",
          },
          cache: "no-store",
          redirect: "error",
          signal: AbortSignal.timeout(10000),
        },
      );
      if (!catalogResponse.ok)
        return NextResponse.json(
          { message: "Cannot verify enabled hosted models." },
          { status: catalogResponse.status === 401 ? 401 : 503 },
        );
      const catalog = await catalogResponse.json();
      if (!Array.isArray(catalog.data))
        return NextResponse.json(
          { message: "Invalid hosted model catalog." },
          { status: 503 },
        );
      const allowed = catalog.data.map((model: { id: string }) => model.id);
      return NextResponse.json(
        [
          ...nvidiaModels(allowed, hostedCatalog(catalog.data)),
          ...(Array.isArray(local) ? local : []),
        ],
        { headers: { "Cache-Control": "no-store" } },
      );
    } catch {
      return NextResponse.json(
        { message: "Cannot connect to backend." },
        { status: 503 },
      );
    }
  }
  try {
    const url = new URL(`${base.replace(/\/$/, "")}/${endpoint}`);
    // Only a numeric page parameter reaches Laravel; clients cannot choose an upstream URL.
    const before = request.nextUrl.searchParams.get("before");
    if (
      researchRoute(request.method, endpoint) &&
      before &&
      /^\d{1,15}$/.test(before)
    )
      url.searchParams.set("before", before);
    const after = request.nextUrl.searchParams.get("after");
    if (
      researchRoute(request.method, endpoint) &&
      after &&
      /^\d{1,15}$/.test(after)
    )
      url.searchParams.set("after", after);
    const page = request.nextUrl.searchParams.get("page");
    if (page && /^\d{1,6}$/.test(page)) url.searchParams.set("page", page);
    for (const key of ["q", "project_id", "archived", "kind", "trash"]) {
      const value = request.nextUrl.searchParams.get(key);
      if (value && value.length <= 100) url.searchParams.set(key, value);
    }
    const upstream = await fetch(url, {
      method: request.method,
      body,
      cache: "no-store",
      redirect: "error",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        ...(process.env.TRUST_PROXY_HEADERS === "true" &&
        request.headers.get("x-forwarded-for")
          ? { "X-Forwarded-For": request.headers.get("x-forwarded-for")! }
          : {}),
        ...(endpoint === "guest/chat" &&
        process.env.FRONTEND_CHAT_BRIDGE_KEY &&
        (process.env.VERCEL === "1" ||
          process.env.TRUST_PROXY_HEADERS === "true")
          ? (() => {
              const ip =
                request.headers.get("x-forwarded-for")?.split(",")[0]?.trim() ||
                "";
              return {
                "X-Guest-IP": ip,
                "X-Guest-Signature": createHmac(
                  "sha256",
                  process.env.FRONTEND_CHAT_BRIDGE_KEY!,
                )
                  .update(ip)
                  .digest("hex"),
              };
            })()
          : {}),
        ...(token && !isPublic ? { Authorization: `Bearer ${token}` } : {}),
      },
      signal:
        endpoint.startsWith("chat") ||
        endpoint === "guest/chat" ||
        endpoint === "3d-scenes"
          ? request.signal
          : AbortSignal.timeout(30_000),
    });
    if (
      upstream.ok &&
      upstream.headers.get("content-type")?.includes("text/event-stream")
    ) {
      return new NextResponse(upstream.body, {
        status: 200,
        headers: {
          "Content-Type": "text/event-stream",
          "Cache-Control": "no-cache, no-transform",
          "X-Accel-Buffering": "no",
        },
      });
    }
    if (
      upstream.ok &&
      (/^3d-scenes\/uploads\/\d+$/.test(endpoint) || studioDownload(endpoint))
    ) {
      return new NextResponse(upstream.body, {
        headers: {
          "Content-Type": "application/octet-stream",
          "Content-Disposition":
            upstream.headers.get("content-disposition") ||
            "attachment; filename=reference.bin",
          "Cache-Control": "private, no-store",
          "X-Content-Type-Options": "nosniff",
        },
      });
    }
    if (upstream.ok && researchDownload(endpoint)) {
      const mime = upstream.headers.get("content-type")?.split(";")[0] || "";
      const formats: Record<string, string> = {
        "application/pdf": "pdf",
        "application/zip": "zip",
        "application/vnd.openxmlformats-officedocument.wordprocessingml.document":
          "docx",
        "text/plain": "txt",
        "text/markdown": "md",
        "text/csv": "csv",
        "image/png": "png",
        "image/jpeg": "jpg",
        "application/octet-stream": "bin",
      };
      if (!formats[mime])
        return NextResponse.json(
          { message: "Invalid research download format." },
          { status: 502 },
        );
      return new NextResponse(upstream.body, {
        headers: {
          "Content-Type": mime,
          "Content-Disposition": `attachment; filename="research-${endpoint.split("/").at(-2)}.${formats[mime]}"`,
          "Cache-Control": "private, no-store",
          "X-Content-Type-Options": "nosniff",
        },
      });
    }
    if (upstream.ok && /^media\/\d+\/output$/.test(endpoint)) {
      const mime = upstream.headers.get("content-type")?.split(";")[0];
      if (
        !mime ||
        ![
          "image/png",
          "image/jpeg",
          "audio/wav",
          "video/mp4",
          "application/zip",
          "application/pdf",
          "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        ].includes(mime)
      )
        return NextResponse.json(
          { message: "Invalid media format." },
          { status: 502 },
        );
      return new NextResponse(upstream.body, {
        headers: {
          "Content-Type": mime,
          "Content-Disposition": `attachment; filename="openmindai-${endpoint.split("/")[1]}.${mime === "image/png" ? "png" : mime === "image/jpeg" ? "jpg" : mime === "audio/wav" ? "wav" : mime === "application/zip" ? "zip" : mime === "application/pdf" ? "pdf" : mime === "application/vnd.openxmlformats-officedocument.wordprocessingml.document" ? "docx" : "mp4"}"`,
          "Cache-Control": "private, no-store",
          "X-Content-Type-Options": "nosniff",
        },
      });
    }
    // cPanel/Cloudflare can return an HTML 504 instead of a JSON API error.
    // Preserve the upstream status and never mistake a timeout for an image failure.
    if (upstream.status === 504 || upstream.status === 502) {
      return NextResponse.json(
        {
          message: /^media(?:\/|$)/.test(endpoint)
            ? "The image service gateway timed out (HTTP " +
              upstream.status +
              "). Check the saved task in Library before trying again."
            : "The backend gateway timed out (HTTP " +
              upstream.status +
              "). Please retry after checking its availability.",
        },
        { status: upstream.status, headers: { "Cache-Control": "no-store" } },
      );
    }
    const text = await upstream.text();
    let data: Record<string, unknown> = {};
    if (text) {
      try {
        data = JSON.parse(text);
      } catch {
        return NextResponse.json(
          { message: "The backend returned an invalid response." },
          { status: 502 },
        );
      }
    }
    // Document drafting uses authenticated Laravel inference; other media engines
    // retain their own configuration requirements.
    if (
      endpoint === "capabilities" &&
      upstream.ok &&
      (hostedMediaEnabled() || hostedDocumentEnabled())
    ) {
      const advertised = Array.isArray(data.media)
        ? data.media.filter((item): item is string => typeof item === "string")
        : [];
      data.media = Array.from(
        new Set([
          ...advertised,
          ...(hostedMediaEnabled() ? ["transcription", "speech", "video"] : []),
          ...(hostedDocumentEnabled() ? ["document_create"] : []),
        ]),
      );
      data.image_generation = data.image_generation === true;
      data.voice_transcription =
        hostedMediaEnabled() || data.voice_transcription === true;
    }
    if (
      ["login", "login/verify-ip", "register"].includes(endpoint) &&
      upstream.ok &&
      typeof data.token === "string"
    ) {
      jar.set(cookieName, data.token, {
        httpOnly: true,
        secure: process.env.NODE_ENV === "production",
        sameSite: "lax",
        path: "/",
        maxAge: 30 * 86400,
      });
      delete data.token;
    }
    if (
      upstream.status === 401 ||
      (upstream.ok &&
        [
          "logout",
          "logout-all",
          "me/password",
          "email/change/confirm",
        ].includes(endpoint)) ||
      (upstream.ok && endpoint === "me" && request.method === "DELETE")
    )
      jar.delete(cookieName);
    const response =
      upstream.status === 204
        ? new NextResponse(null, { status: 204 })
        : NextResponse.json(data, { status: upstream.status });
    response.headers.set("Cache-Control", "no-store");
    const retry = upstream.headers.get("retry-after");
    if (retry) response.headers.set("Retry-After", retry);
    return response;
  } catch {
    return NextResponse.json(
      {
        message:
          endpoint === "chat"
            ? "The model did not respond in time. Check your chat history before retrying."
            : "Cannot connect to the backend. Please try again.",
      },
      { status: 503 },
    );
  }
}
export {
  proxy as GET,
  proxy as POST,
  proxy as PATCH,
  proxy as PUT,
  proxy as DELETE,
};
