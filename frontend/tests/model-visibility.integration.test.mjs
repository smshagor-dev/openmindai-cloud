import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import { NVIDIA_MODEL_ID, nvidiaModel } from "../src/lib/nvidia-chat.ts";

test("hosted model metadata uses product branding without exposing the provider model", () => {
  assert.deepEqual(nvidiaModel(), {
    id: NVIDIA_MODEL_ID,
    name: "OpenMindAI",
    family: "Hosted AI",
    license: "External inference service",
  });
});

test("chat header is static while existing model routing remains active", () => {
  const workspace = readFileSync(
    new URL("../src/components/chat-workspace.tsx", import.meta.url),
    "utf8",
  );
  assert.match(workspace, /className="topbar-brand"/);
  assert.match(workspace, /className="topbar-brand-logo"/);
  assert.match(workspace, /className="sidebar-brand-copy"/);
  assert.match(workspace, /<WorkspaceWelcome title="What can I help with\?"/);
  const welcome = readFileSync(new URL("../src/components/workspace-welcome.tsx", import.meta.url), "utf8");
  assert.match(welcome, /className="workspace-welcome-identity"/);
  assert.match(welcome, /src="\/openmindai-logo.png"/);
  assert.match(workspace, /src="\/openmindai-logo.png"/);
  assert.doesNotMatch(workspace, /className="model-picker"/);
  assert.doesNotMatch(workspace, /className="active-model-label"/);
  assert.doesNotMatch(workspace, /className="model-menu-wrapper"/);
  assert.match(workspace, /setModelId\(\(previous\) =>/);
  assert.match(workspace, /model_id: inlineImage && !override \? -2 : modelId/);
  assert.match(workspace, /<ModelPicker models=\{models\}/);
  assert.doesNotMatch(workspace, /This chat uses an external AI service/);
});

test("workspace tool descriptions do not display the provider name", () => {
  const tools = readFileSync(
    new URL("../src/components/tools-workbench.tsx", import.meta.url),
    "utf8",
  );
  assert.doesNotMatch(tools, /NVIDIA|Nemotron/i);
});
