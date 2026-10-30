import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = file => readFileSync(new URL("../src/" + file, import.meta.url), "utf8");

test("edit button opens an in-place message editor rather than a browser prompt", () => {
  const workspace = read("components/chat-workspace.tsx");
  assert.match(workspace, /function startEditingMessage\(index: number\)/);
  assert.match(workspace, /editingMessageIndex === index/);
  assert.match(workspace, /className="inline-message-editor"/);
  assert.match(workspace, /onClick=\{\(\) => startEditingMessage\(index\)\}/);
  assert.doesNotMatch(workspace, /prompt\("Edit your message"/);
  assert.doesNotMatch(workspace, /window\.prompt\(/);
});

test("editing keeps existing message branching semantics and text history", () => {
  const workspace = read("components/chat-workspace.tsx");
  assert.match(workspace, /async function branch\(index: number, revisedText\?: string\)/);
  assert.match(workspace, /\(revisedText \?\? target.content\)\.trim\(\)/);
  assert.match(workspace, /message_id: target.id/);
  assert.match(workspace, /text: content,/);
  assert.match(workspace, /history: messages.slice\(0, index\)/);
  assert.match(workspace, /attachmentIds: result.attachment_ids/);
  assert.match(workspace, /async function saveEditedMessage\(index: number\)/);
  assert.match(workspace, /const saved = await branch\(index, content\)/);
});

test("edit form has keyboard, validation and cancellation behavior", () => {
  const workspace = read("components/chat-workspace.tsx");
  assert.match(workspace, /onSubmit=\{\(event\) => \{/);
  assert.match(workspace, /void saveEditedMessage\(index\)/);
  assert.match(workspace, /event.key === "Escape"/);
  assert.match(workspace, /!event.shiftKey && !event.nativeEvent.isComposing/);
  assert.match(workspace, /onClick=\{cancelEditingMessage\}/);
  assert.match(workspace, /!editingText.trim\(\)/);
  assert.match(workspace, /editingText.trim\(\) === message.content.trim\(\)/);
  assert.match(workspace, /setEditingMessageIndex\(null\)/);
  assert.match(workspace, /setSavingEdit\(true\)/);
});

test("editor retains responsive and theme-aware styling", () => {
  const theme = read("app/chat-theme.css");
  assert.match(theme, /\.workspace \.inline-message-editor/);
  assert.match(theme, /\.workspace \.inline-edit-actions/);
  assert.match(theme, /\.workspace \.inline-edit-actions \.inline-edit-save/);
  assert.match(theme, /@media \(max-width: 760px\)/);
});
