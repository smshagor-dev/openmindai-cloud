import assert from "node:assert/strict";
import test from "node:test";
import { NVIDIA_HOSTED_MODELS, NVIDIA_AUTO_MODEL_ID, nvidiaModels, isNvidiaModelId } from "../src/lib/nvidia-chat.ts";

test("OpenMindAI hosted agent IDs map to the intended NVIDIA catalog", () => {
  assert.deepEqual(NVIDIA_HOSTED_MODELS.map((model) => model.slug), [
    "openmindai-super", "openmindai-ultra", "openmindai-lite",
  ]);
  assert.equal(NVIDIA_AUTO_MODEL_ID, -4);
  assert.ok(isNvidiaModelId(-4));
  assert.ok(isNvidiaModelId(-2));
  assert.ok(isNvidiaModelId(-3));
  assert.ok(isNvidiaModelId(-1));
  assert.equal(isNvidiaModelId(99), false);
  assert.deepEqual(nvidiaModels(["openmindai-super"]).map((model) => model.name), ["OpenMindAI Auto", "OpenMindAI Super"]);
});

test("additional hosted chat models keep stable branded selections across catalog changes", async () => {
  const { hostedCatalog } = await import("../src/lib/nvidia-chat.ts");
  const rows = [
    {id: "deepseek-flash", name: "Flash", provider_model: "deepseek-ai/flash"},
    {id: "qwen-code", name: "Code", provider_model: "qwen/code"},
  ];
  const original = hostedCatalog(rows);
  const reordered = hostedCatalog([...rows].reverse());
  assert.equal(original[0].name, "OpenMindAI Flash");
  assert.equal(original[0].id, reordered[1].id);
  assert.equal(original[0].provider, "deepseek-ai/flash");
  assert.ok(isNvidiaModelId(original[0].id));
  assert.equal(nvidiaModels(rows.map(row => row.id), original).length, 3);
  assert.equal(hostedCatalog([{id: "missing-provider"}]).length, 0);
});
