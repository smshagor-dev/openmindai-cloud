import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
const read = file => readFileSync(new URL("../src/" + file, import.meta.url), "utf8");

test("open research projects keep a flex column so the conversation scrolls inside the frame", () => {
  const css = read("app/research-studio/chat.css");
  assert.doesNotMatch(css, /\.studio-frame \.rs-chat \.rs-main\s*\{\s*display:\s*flow-root/);
  assert.match(css, /\.studio-frame \.rs-chat:not\(\.rs-has-project\) \.rs-main\s*\{\s*display:\s*flow-root/);
  assert.match(css, /\.studio-frame \.rs-has-project \.rs-main\s*\{\s*display:\s*flex;\s*flex-direction:\s*column/);
});

test("with a scene open, the 3D composer edits it through the proxied edit endpoint", () => {
  const page = read("app/3d-studio/page.tsx");
  assert.match(page, /studioApi\(`\/\$\{saved\.id\}\/edit`, "POST"/);
  assert.match(page, /version: saved\.version \?\? 1/);
  assert.match(page, /New scene instead/);
  assert.match(page, /Describe what to change/);
  const proxy = read("app/api/backend/[...path]/route.ts");
  assert.ok(proxy.includes(String.raw`3d-scenes(?:\/uploads|\/\d+\/edit)?`), "edit endpoint is allowed");
  // Model edits can take longer than the generic 30s proxy timeout.
  assert.ok(proxy.includes(String.raw`/^3d-scenes\/\d+\/edit$/.test(endpoint)`), "edit waits for the model");
});

test("both studios show a Back button one level up from an open project, scene or view", () => {
  const frame = read("components/studio-frame.tsx");
  assert.match(frame, /back\?: \{ label: string; onClick: \(\) => void; disabled\?: boolean \}/);
  assert.match(frame, /className="studio-back-button"/);
  const research = read("components/research/studio.tsx");
  assert.match(research, /Back to the conversation/);
  assert.match(research, /Back to all research/);
  assert.match(research, /back=\{back\}/);
  const studio = read("app/3d-studio/page.tsx");
  assert.match(studio, /Back to 3D Studio/);
  assert.match(studio, /back=\{back\}/);
});
