import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { threeDModelIntent } from "../src/lib/studio-intent.ts";
import { generationIntent, routePrompt, serverRouterConfig } from "../src/lib/prompt-router.ts";

const robot = readFileSync(new URL("./fixtures-robot-3d-prompt.txt", import.meta.url), "utf8");

test("detailed Unity low-poly prompt is a 3D model request, not video or image", async () => {
  assert.equal(threeDModelIntent(robot), true);
  assert.equal(generationIntent(robot), null);
  assert.equal((await routePrompt(robot, serverRouterConfig("fixture"))).kind, "chat");
});

test("3D model requests are recognised in English and Bangla", () => {
  for (const prompt of ["Create a 3D model of a wooden chair", "make a low-poly tree for my game",
    "Generate a game-ready robot and export GLB", "একটা থ্রিডি মডেল বানাও গাড়ির"])
    assert.equal(threeDModelIntent(prompt), true, prompt);
});

test("3D-style images, questions and code stay out of 3D Studio", () => {
  for (const prompt of ["Create a 3D logo for my bakery", "Make a 3D style illustration of a cat",
    "How do I make a 3D model in Blender?", "Write three.js code to render a 3D model",
    "Create an animation video of a robot dancing"])
    assert.equal(threeDModelIntent(prompt), false, prompt);
  assert.equal(generationIntent("Create a 3D logo for my bakery")?.kind, "image");
  assert.equal(generationIntent("Create an animation video of a robot dancing")?.kind, "video");
});

test("chat hands 3D requests to 3D Studio through sessionStorage only", () => {
  const chat = readFileSync(new URL("../src/components/chat-workspace.tsx", import.meta.url), "utf8");
  const studio = readFileSync(new URL("../src/app/3d-studio/page.tsx", import.meta.url), "utf8");
  assert.match(chat, /threeDModelIntent\(text\)/);
  assert.match(chat, /sessionStorage\.setItem\(STUDIO_HANDOFF_KEY/);
  assert.match(studio, /sessionStorage\.removeItem\(STUDIO_HANDOFF_KEY\)/);
  assert.doesNotMatch(studio, /searchParams\.get\(["']prompt/);
});
