import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";

const proxy = readFileSync(new URL("../src/app/api/backend/[...path]/route.ts", import.meta.url), "utf8");
const chat = readFileSync(new URL("../src/lib/nvidia-chat.ts", import.meta.url), "utf8");

test("model selector and chat authorization use the same web-session catalog", () => {
  assert.match(proxy, /chat\/hosted-models/);
  assert.match(chat, /chat\/hosted-models/);
  assert.doesNotMatch(proxy, /openmindai\/models/);
  assert.doesNotMatch(chat, /openmindai\/models/);
});
test("model catalog failure is not silently treated as zero enabled models", () => {
  assert.match(proxy, /Cannot verify enabled hosted models/);
  assert.match(proxy, /Invalid hosted model catalog/);
});
