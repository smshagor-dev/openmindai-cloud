import { cookies } from "next/headers";
import { NextRequest, NextResponse } from "next/server";
import WordExtractor from "word-extractor";
export const runtime = "nodejs";
export async function POST(request: NextRequest) {
  const origin =
    process.env.VERCEL === "1"
      ? request.nextUrl.origin
      : new URL(process.env.FRONTEND_URL || request.url).origin;
  if (request.headers.get("origin") !== origin)
    return NextResponse.json(
      { message: "Invalid request origin." },
      { status: 403 },
    );
  if (!request.headers.get("content-type")?.startsWith("application/json"))
    return NextResponse.json({ message: "JSON is required." }, { status: 415 });
  const token = (await cookies()).get("openmindai_token")?.value;
  if (!token)
    return NextResponse.json(
      { message: "Please sign in to preview Word files." },
      { status: 401 },
    );
  try {
    const base =
      process.env.LARAVEL_API_URL || "https://cloud.openmindai.org/api/v1";
    const identity = await fetch(base.replace(/\/$/, "") + "/me", {
      headers: { Authorization: "Bearer " + token, Accept: "application/json" },
      cache: "no-store",
      redirect: "error",
      signal: AbortSignal.timeout(10000),
    });
    if (!identity.ok)
      return NextResponse.json(
        { message: "Cannot verify your session." },
        { status: identity.status === 401 ? 401 : 503 },
      );
    const reader = request.body?.getReader();
    if (!reader)
      return NextResponse.json(
        { message: "File is required." },
        { status: 422 },
      );
    let size = 0;
    const chunks: Uint8Array[] = [];
    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.byteLength;
      if (size > 4_100_000) {
        await reader.cancel();
        return NextResponse.json(
          { message: "Legacy Word preview supports files up to 3 MB." },
          { status: 413 },
        );
      }
      chunks.push(value);
    }
    const { data } = JSON.parse(Buffer.concat(chunks).toString("utf8"));
    if (typeof data !== "string" || !/^[A-Za-z0-9+/]+={0,2}$/.test(data))
      return NextResponse.json(
        { message: "Invalid document." },
        { status: 422 },
      );
    const file = Buffer.from(data, "base64");
    if (
      file.length > 3_000_000 ||
      file.subarray(0, 8).toString("hex") !== "d0cf11e0a1b11ae1"
    )
      return NextResponse.json(
        { message: "Select a valid .doc file up to 3 MB." },
        { status: 422 },
      );
    const document = await new WordExtractor().extract(file);
    return NextResponse.json(
      { text: document.getBody().slice(0, 250000) },
      { headers: { "Cache-Control": "no-store" } },
    );
  } catch {
    return NextResponse.json(
      { message: "Unable to preview this document. Try a DOCX or PDF copy." },
      { status: 422 },
    );
  }
}
