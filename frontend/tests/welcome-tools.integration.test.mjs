import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const file = (path) => readFileSync(new URL("../src/components/" + path, import.meta.url), "utf8");
const menu = file("welcome-tools-menu.tsx");
const workspace = file("chat-workspace.tsx");
const browser = file("browser-tab-links-dialog.tsx");

test("welcome tool menu includes all seven requested actions", () => {
  for (const label of [
    "Add photos & files", "Add from library", "Create image",
    "Add browser tabs", "Web search", "Work in a project", "Deep research",
  ]) assert.ok(menu.includes(label), "Missing menu action: " + label);
  assert.match(menu, /onSelect\(item\.action\)/);
  assert.match(menu, /signedIn && item\.blocked/);
});

test("menu actions connect to real supported chat workflows", () => {
  assert.match(workspace, /<WelcomeToolsMenu/);
  assert.match(workspace, /onSelect=\{selectWelcomeTool\}/);
  assert.match(workspace, /fileRef\.current\?\.click\(\)/);
  assert.match(workspace, /setToolsOpen\("library"\)/);
  assert.match(workspace, /selectGenerationTool\("image"\)/);
  assert.doesNotMatch(workspace, /<MediaDialog/);
  assert.match(workspace, /setWorkspaceOpen\(true\)/);
  assert.match(workspace, /setDeepResearch\(action === "research" && !turningOff\)/);
  assert.match(workspace, /generation_kind: mediaMode/);
  assert.match(workspace, /generatedMediaIds\(message.content\)/);
});

test("browser links require valid public URL forms and do not read native tabs", () => {
  assert.match(browser, /new URL\(part\)/);
  assert.match(browser, /\["https:", "http:"\]/);
  assert.match(browser, /parsed\.username \|\| parsed\.password/);
  assert.match(browser, /parts\.length > 10/);
  assert.match(browser, /cannot read your/);
});


test("tools stay within the viewport and remain scrollable at mobile sizes", () => {
  const theme = readFileSync(new URL("../src/app/chat-theme.css", import.meta.url), "utf8");
  assert.match(workspace, /onToggle=\{\(event\) => setToolMenuOpen/);
  assert.match(workspace, /window\.visualViewport/);
  assert.match(workspace, /getBoundingClientRect\(\)/);
  assert.match(menu, /style=\{position\}/);
  assert.match(menu, /aria-label="Close tools"/);
  assert.match(theme, /position: fixed/);
  assert.match(theme, /max-width: calc\(100vw - 24px\)/);
  assert.match(theme, /overflow-y: auto/);
});
