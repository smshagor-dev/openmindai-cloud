import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const workspace = readFileSync(new URL("../src/components/chat-workspace.tsx", import.meta.url), "utf8");

test("image remains available to the next prompt in the same chat", () => {
  assert.match(workspace, /image: \{mime: inlineImage\.mime, data: inlineImage\.data\}/);
  assert.doesNotMatch(workspace, /event === "done" && data\.status === "complete" && inlineImage\)\s*setInlineImage\(null\)/);
  assert.match(workspace, /setInlineImage\(\{name: file\.name/);
});

test("user can remove image and chat switching discards transient bytes", () => {
  assert.match(workspace, /aria-label="Remove attached image"/);
  assert.match(workspace, /onClick=\{\(\) => setInlineImage\(null\)\}/);
  assert.match(workspace, /function newChat\(updateUrl = true\) \{[\s\S]{0,400}setInlineImage\(null\)/);
  assert.match(workspace, /async function openChat\(id: number, fromRoute = false\) \{[\s\S]{0,1200}setInlineImage\(null\)/);
  assert.match(workspace, /const clearSession = useCallback\(\(\) => \{[\s\S]{0,700}setInlineImage\(null\)/);
});
