
import { readFileSync, statSync } from "node:fs";
import { test } from "node:test";
import assert from "node:assert/strict";

const read = (path) => readFileSync(new URL("../" + path, import.meta.url), "utf8");

test("developer portal uses the shared frontend OpenMindAI logo in header and metadata", () => {
  const logo = new URL("../public/openmindai-logo.png", import.meta.url);
  assert.ok(statSync(logo).size > 10000, "Shared PNG brand image must exist");
  assert.equal(readFileSync(logo).subarray(0, 8).toString("hex"), "89504e470d0a1a0a");
  assert.deepEqual(readFileSync(logo), readFileSync(new URL("../../frontend/public/openmindai-logo.png", import.meta.url)), "The API logo must exactly match the primary frontend asset");
  const layout = read("src/app/layout.tsx");
  assert.match(layout, /icon: "\/openmindai-logo.png"/);
  assert.match(layout, /apple: "\/openmindai-logo.png"/);
  const portal = read("src/components/portal.tsx");
  assert.match(portal, /src="\/openmindai-logo.png"/);
  assert.doesNotMatch(portal, /<Code2 /);
});

test("all portal views keep responsive and keyboard-visible controls", () => {
  const style = read("src/app/visual-refresh.css");
  for (const selector of [".topbar", ".landing", ".auth-card", ".workspace", ".key-form", ".model-card", ".docs-page", ".code-panel", ".otp-control"]) {
    assert.ok(style.includes(selector), "Missing styled surface: " + selector);
  }
  assert.match(style, /:focus-visible/);
  assert.match(style, /max-width: 980px/);
  assert.match(style, /max-width: 760px/);
  assert.match(style, /max-width: 430px/);
  assert.match(style, /prefers-reduced-motion/);
  assert.match(read("src/components/portal.tsx"), /aria-expanded=\{menu\}/);
  assert.match(read("src/components/portal.tsx"), /aria-label="Six-digit email verification code"/);
});

test("developer portal exposes OpenMindAI coding agents, not provider names", () => {
  const catalog = read("src/components/catalog.tsx");
  const dashboard = read("src/components/dashboard.tsx");
  const docs = read("src/components/docs.tsx");
  assert.match(catalog, /<h3>OpenMindAI<\/h3>/);
  assert.doesNotMatch(catalog, /m\.provider_access|Hosted NVIDIA|\{m\.name\}/);
  assert.match(dashboard, /<strong>OpenMindAI<\/strong>/);
  assert.doesNotMatch(dashboard, /m\.provider|\{m\.model\}/);
  assert.doesNotMatch(docs, /Additional specialist models appear/);
});
