import test from "node:test";
import assert from "node:assert/strict";
import {
  PROMPT_UNDERSTANDING_INSTRUCTIONS,
  PROMPT_UNDERSTANDING_MARKER,
  currentDateContext,
  withPromptUnderstanding,
} from "../src/lib/prompt-understanding.ts";

test("understanding guidance covers multilingual intent, negation, context and response language", () => {
  for (const expected of ["original language", "Banglish", "negations", "latest message", "Ask one concise clarification", "filenames"]) {
    assert.ok(PROMPT_UNDERSTANDING_INSTRUCTIONS.includes(expected), expected);
  }
});

test("messages in any script remain untouched and system guidance is idempotent", () => {
  for (const prompt of [
    "আগের code ta fix koro, name change korba na",
    "Explícame la arquitectura en español",
    "رجاءً اشرح هذه الدالة بالعربية",
    "请用中文解释这个 API",
    "இந்த கோடை சரி செய்யவும்",
    "Можешь объяснить предыдущий ответ?",
    "हिंदी में जवाब दो, code English में रखो",
  ]) {
    const original = [{role:"system",content:"Respect existing preferences."},{role:"user",content:prompt}];
    const result = withPromptUnderstanding(original);
    assert.equal(result[1].content, prompt);
    assert.equal(original[0].content, "Respect existing preferences.");
    assert.match(result[0].content, /Multilingual request handling:/);
    assert.equal(withPromptUnderstanding(result), result);
    assert.equal(result[0].content.split(PROMPT_UNDERSTANDING_MARKER).length - 1, 1);
  }
});

test("older conversations with no system message gain guidance without losing context", () => {
  const previous = [
    {role:"user",content:"Explain in Bengali"},
    {role:"assistant",content:"ব্যাখ্যা"},
    {role:"user",content:"Now summarize that in Arabic"},
  ];
  const result = withPromptUnderstanding(previous);
  assert.equal(result.length, previous.length+1);
  assert.equal(result[0].role, "system");
  assert.deepEqual(result.slice(1), previous);
});

test("guidance states the current date so training-era knowledge is not presented as current", () => {
  const today = new Date().toISOString().slice(0, 10);
  assert.match(currentDateContext(), new RegExp("Current date: " + today));
  assert.match(currentDateContext(new Date("2026-11-02T23:00:00Z")), /Current date: 2026-11-02 \(UTC\)/);
  assert.ok(currentDateContext().includes("does not exist only because it is absent from your training"));
  const result = withPromptUnderstanding([{role:"user",content:"Is the 2026 model out?"}]);
  assert.match(result[0].content, new RegExp("Current date: " + today));
});

test("backend-provided language guidance is not duplicated", () => {
  const input = [
    {role:"system",content:"You are OpenMindAI.\nMultilingual request handling:\nFollow requested language."},
    {role:"user",content:"Bonjour"},
  ];
  assert.equal(withPromptUnderstanding(input), input);
});
