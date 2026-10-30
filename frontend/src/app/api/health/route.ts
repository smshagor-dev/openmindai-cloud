import { NextResponse } from "next/server";

export const runtime = "nodejs";
export const dynamic = "force-dynamic";

/** Public deployment diagnostic, without exposing credentials or private URLs. */
export function GET() {
  return NextResponse.json(
    {
      service: "OpenMindAI frontend",
      frontend: "ok",
      backendConfigured: Boolean(process.env.LARAVEL_API_URL),
    },
    { headers: { "Cache-Control": "no-store" } },
  );
}
