import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const source = (file) =>
  readFileSync(new URL("../" + file, import.meta.url), "utf8");

test("Vercel detects the frontend as a Next.js application with pinned installation", () => {
  const vercel = JSON.parse(source("vercel.json"));
  const pkg = JSON.parse(source("package.json"));
  assert.equal(vercel.framework, "nextjs");
  assert.equal(vercel.installCommand, "npm ci");
  assert.equal(vercel.buildCommand, "npm run typecheck && npm run build");
  assert.equal(pkg.scripts.build, "next build");
  assert.ok(source("package-lock.json").includes('"lockfileVersion": 3'));
  assert.equal(source(".node-version").trim(), "22");
});

test("Next.js API handles Vercel deployment origins and long requests", () => {
  const route = source("src/app/api/backend/[...path]/route.ts");
  assert.match(route, /export const runtime = "nodejs"/);
  assert.match(route, /export const maxDuration = 300/);
  assert.match(route, /process\.env\.VERCEL === "1"/);
  assert.match(route, /request\.nextUrl\.origin/);
  assert.match(route, /request\.headers\.get\("origin"\) !== expectedOrigin/);
});

test("Vercel config keeps provider credentials server-only and points to Laravel", () => {
  const env = source(".env.example");
  assert.match(env, /LARAVEL_API_URL=https:\/\/cloud\.openmindai\.org\/api\/v1/);
  assert.match(env, /FRONTEND_CHAT_BRIDGE_KEY=/);
  // The current Next.js hosting flow still performs server-side inference.
  // Neither provider nor HMAC signing credentials may be public env vars.
  assert.match(env, /^NVIDIA_API_KEY=/m);
  assert.doesNotMatch(env, /^NEXT_PUBLIC_NVIDIA_API_KEY=/m);
  assert.doesNotMatch(env, /^NEXT_PUBLIC_FRONTEND_CHAT_BRIDGE_KEY=/m);
});
