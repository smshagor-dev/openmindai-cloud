import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = file => readFileSync(new URL("../src/" + file, import.meta.url), "utf8");

test("generation status renders inside the chat turn, not the composer", () => {
  const workspace = read("components/chat-workspace.tsx");
  assert.doesNotMatch(workspace, /tool-progress/);
  assert.match(workspace, /statusLabel=\{busy && index === messages\.length - 1 \? researchProgress \|\| undefined : undefined\}/);
  assert.match(read("components/research-response.tsx"), /statusLabel=\{statusLabel\}/);
  assert.match(read("components/response-markdown.tsx"), /<TypingIndicator writing=\{Boolean\(text\)\} label=\{statusLabel\} \/>/);
});
