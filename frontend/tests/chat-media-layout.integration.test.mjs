import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const source = file =>
  readFileSync(new URL("../src/" + file, import.meta.url), "utf8");

test("media progress and finished previews share the centered conversation thread", () => {
  const workspace = source("components/chat-workspace.tsx");
  const threadStart = workspace.indexOf('<div className="thread">');
  const creation = workspace.indexOf('<div className="generated-media-entry" role="status"', threadStart);
  const result = workspace.indexOf('{inlineMedia.map((id) => (', creation);
  const threadEnd = workspace.indexOf('\n            </div>\n          )}', result);

  assert.ok(threadStart >= 0, "the conversation thread exists");
  assert.ok(creation > threadStart, "creation progress is inside the thread");
  assert.ok(result > creation, "completed media follows creation progress");
  assert.ok(threadEnd > result, "media items close before the thread closes");
  assert.match(workspace, /!pendingCreation && inlineMedia\.length === 0 \? "is-empty"/);
  assert.match(workspace, /!pendingCreation && inlineMedia\.length === 0 \? \(/);
  assert.match(workspace, /\[messages\.length, busy, loadingChat, pendingCreation, inlineMedia\.length\]/);
});

test("conversation actions do not add an orphaned label above the composer", () => {
  const workspace = source("components/chat-workspace.tsx");
  const composer = workspace.slice(workspace.indexOf('className={`composer-area'));
  assert.ok(!composer.includes('>\n              Conversation branches\n'));
  assert.match(workspace, /aria-label="Conversation branches"/);
  assert.match(workspace, /<GitBranch size=\{18\} \/>/);
});

test("generation previews remain bounded on desktop and small screens", () => {
  const theme = source("app/chat-theme.css");
  assert.match(theme, /\.workspace \.generated-media-content \.creation-progress/);
  assert.match(theme, /\.workspace \.generated-media-content \.inline-media/);
  assert.match(theme, /max-width: 100%/);
  assert.match(theme, /max-height: 320px/);
  assert.match(theme, /@media \(max-width: 760px\)/);
});
