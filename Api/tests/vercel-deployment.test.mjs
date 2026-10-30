import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL("../" + path, import.meta.url), "utf8");

test("Developer API deploys the Next.js application with a typechecked build", () => {
  const vercel = JSON.parse(read("vercel.json"));
  const pkg = JSON.parse(read("package.json"));
  assert.equal(vercel.framework, "nextjs");
  assert.equal(vercel.installCommand, "npm install --no-audit --no-fund");
  assert.equal(vercel.buildCommand, "npm run typecheck && npm run build");
  assert.equal(pkg.scripts.build, "next build");
  assert.equal(read(".node-version").trim(), "22");
});

test("Authenticated portal supports each Vercel preview origin without disabling origin checks", () => {
  const src = read("src/app/api/bridge/[...path]/route.ts");
  assert.match(src, /process\.env\.VERCEL === "1"/);
  assert.match(src, /request\.nextUrl\.origin/);
  assert.match(src, /request\.headers\.get\("origin"\) !== expectedOrigin/);
});

test("Only Laravel's backend URL is configured, not NVIDIA credentials", () => {
  const env = read(".env.example");
  assert.match(env, /LARAVEL_API_URL=https:\/\/cloud\.openmindai\.org\/api\/v1/);
  assert.doesNotMatch(env, /^NVIDIA_API_KEY=/m);
  const route = read("src/app/api/v1/openmindai/[...path]/route.ts");
  assert.match(route, /export const runtime = "nodejs"/);
  assert.match(route, /export const maxDuration = 180/);
});
