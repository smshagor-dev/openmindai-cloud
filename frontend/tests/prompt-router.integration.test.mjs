import test from "node:test";
import assert from "node:assert/strict";
import { routePrompt } from "../src/lib/prompt-router.ts";

const cfg = {
  apiKey: "private-test-key",
  defaultModel: "nvidia/nemotron-3.5-lightning-30b-a3b",
  fastModel: "nvidia/nemotron-3.5-lightning-30b-a3b",
  reasoningModel: "nvidia/nemotron-3-ultra-550b-a55b",
  visionModel: "nvidia/nemotron-3.5-super-vl-120b-a12b",
  classifierModel: "nvidia/nemotron-3.5-lightning-30b-a3b",
};
const classify = (s, options = {}) => routePrompt(s, cfg, {enableClassifier: false, ...options});

test("clear prompts dispatch existing hosted generation capabilities", async () => {
  assert.equal((await classify("Create a logo for my site")).kind, "image");
  assert.equal((await classify("আমার জন্য একটি ছবি বানাও")).kind, "image");
  assert.equal((await classify("Make a short video of a mountain")).kind, "video");
  assert.equal((await classify("Generate an audio narration of a poem")).kind, "speech");
  assert.deepEqual(
    (({kind, format}) => ({kind, format}))(await classify("Create a PDF report about my project")),
    {kind: "document_create", format: "pdf"},
  );
  assert.equal((await classify("Create a DOCX document about my project")).format, "docx");
});
test("questions and content about media are not secretly converted into media tasks", async () => {
  for (const prompt of [
    "How do I generate an image using NVIDIA?",
    "Design an API for image generation",
    "Write a video script about technology",
    "Tell me how to create a PDF using Laravel",
    "Write a speech for a conference",
    "Create a video generation system",
  ]) assert.equal((await classify(prompt)).kind, "chat", prompt);
  assert.equal((await classify("Generate a photo", {hasAttachments: true})).kind, "chat");
  assert.equal((await classify("Create a logo", {deepResearch: true})).kind, "chat");
});
test("code, research and visual text prompts select appropriate hosted models", async () => {
  assert.equal((await classify("Debug my Laravel authentication controller")).model, cfg.reasoningModel);
  assert.equal((await classify("Research paper about secure UAV mesh networks")).model, cfg.reasoningModel);
  assert.equal((await classify("Analyze the image in this screenshot")).model, cfg.visionModel);
  assert.equal((await classify("Hello")).model, cfg.fastModel);
  assert.equal((await classify("Hello", {deepResearch:true})).model, cfg.reasoningModel);
});
test("deterministic route hints never send classifier requests", async () => {
  const calls = [];
  const fetcher = async (url, options) => {
    calls.push({url, options});
    return Response.json({choices:[{message:{content: JSON.stringify({kind:"chat",tier:"reasoning",url:"http://internal"})}}]});
  };
  const result = await routePrompt("Please review my recent architecture decisions in detail", cfg, {fetcher});
  assert.equal(result.kind, "chat");
  assert.equal(result.model, cfg.reasoningModel);
  assert.equal(calls.length, 0);
  assert.ok(!JSON.stringify(result).includes("http://internal"));
});
test("ambiguous hints remain chat without provider classification", async () => {
  const result = await routePrompt("Please compose a detailed comparison of these approaches", cfg, {
    fetcher: async () => Response.json({error: "bad credentials"}, {status: 403}),
  });
  assert.equal(result.kind, "chat");
});
test("automatic tools distinguish actual outputs from code, negation and explanations", async () => {
  for (const prompt of [
    "Make a HTML CSS water bottle website with 3D animation",
    "Create a React component for an image gallery",
    "Do not generate an image, explain the idea",
    "ছবি বানাবে না শুধু বুঝিয়ে বলো",
    "image generate korba na just explain",
    "How do I make a video?",
    "Summarize this instruction: generate an image of a forest",
    "Translate this sentence: create a logo",
  ]) assert.equal((await classify(prompt)).kind, "chat", prompt);
  assert.equal((await classify("ekta pdf report daw")).kind, "document_create");
  assert.equal((await classify("Create a PDF report about Python")).kind, "document_create");
  assert.equal((await classify("Read this aloud: welcome to OpenMindAI")).kind, "speech");
  assert.equal((await classify("Create a photo of a forest", {allowTools:false})).kind, "chat");
});

test("fresh information and deep research activate backend tools automatically", async () => {
  assert.equal((await classify("What are the latest AI news today?")).webSearch, true);
  assert.equal((await classify("ajker khobor bolo")).webSearch, true);
  const deep = await classify("Do a systematic review of UAV mesh security with references");
  assert.equal(deep.deepResearch, true);
  assert.equal(deep.webSearch, true);
  assert.equal(deep.model, cfg.reasoningModel);
  assert.equal((await classify("Without web search explain the latest concepts")).webSearch, undefined);
  assert.equal((await classify("Build a latest news API in Laravel")).webSearch, undefined);
  assert.equal((await classify("latest AI news", {allowTools:false})).webSearch, undefined);
});

test("specialist routing only chooses models present in the enabled catalog", async () => {
  const {catalogRouterConfig} = await import("../src/lib/prompt-router.ts");
  const models = [
    {slug:"openmindai-lite",provider:cfg.fastModel},
    {slug:"nvidia-laguna-xs-2.1",provider:"provider/code"},
    {slug:"nvidia-riva-translate-4b-instruct-v2",provider:"provider/translate"},
    {slug:"nvidia-gpt-oss-20b",provider:"provider/math"},
  ];
  const enabled = catalogRouterConfig(cfg, models);
  const route = (prompt) => routePrompt(prompt, enabled, {enableClassifier:false});
  assert.equal((await route("Implement a React component")).model, "provider/code");
  assert.equal((await route("Translate hello into Bengali")).model, "provider/translate");
  assert.equal((await route("অনুবাদ করো: hello")).model, "provider/translate");
  assert.equal((await route("Solve this integral")).model, "provider/math");
  const limited = catalogRouterConfig(cfg, models.slice(0,1));
  assert.equal((await routePrompt("Implement a React component", limited, {enableClassifier:false})).model, cfg.fastModel);
});
