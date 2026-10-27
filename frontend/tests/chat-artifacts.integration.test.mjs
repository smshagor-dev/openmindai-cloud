import test from "node:test";
import assert from "node:assert/strict";
import {generatedMediaIds} from "../src/lib/chat-artifacts.ts";
import {generationIntent,generationNeedsContext} from "../src/lib/prompt-router.ts";

test("inline media restores accepted and legacy saved artifacts without duplicate or unsafe URLs", () => {
  assert.deepEqual(generatedMediaIds("[View generated image](/api/backend/media/42/output)\n[Download generated document](/api/backend/media/53/output)\n[View generated image](/api/backend/media/42/output)"), [42,53]);
  for (const content of ["[View generated image](https://evil.example/api/backend/media/42/output)", "[View generated image](/api/backend/media/0/output)", "[View generated image](/api/backend/media/9007199254740999/output)", "[Other](/api/backend/media/42/output)"])
    assert.deepEqual(generatedMediaIds(content), []);
});

test("context and evidence dependent files retain the conversation/tool workflow", () => {
  for(const prompt of ["Search official sources and create a PDF", "Create a document using the previous answer", "আগের তথ্যসূত্র থেকে ডকুমেন্ট তৈরি করো"])
    assert.equal(generationNeedsContext(prompt),true,prompt);
  assert.equal(generationNeedsContext("Generate an image of a forest"),false);
  assert.equal(generationNeedsContext("Create a Word document about trees"),false);
});

test("creation intent recognizes Bengali, Banglish and documents while rejecting negation and code requests", () => {
  for (const prompt of ["আমাকে একটি ছবি বানিয়ে দাও", "akta chobi daw", "image generate kore daw", "Create a professional logo"])
    assert.equal(generationIntent(prompt)?.kind, "image", prompt);
  assert.deepEqual(generationIntent("Create a PDF about trees"), {kind:"document_create",format:"pdf"});
  assert.deepEqual(generationIntent("Generate a Word document about trees"), {kind:"document_create",format:"docx"});
  for (const prompt of ["Do not generate an image", "How do I create an image?", "Create a React image gallery", "Translate this: generate an image", "image generate korba na just explain"])
    assert.equal(generationIntent(prompt), null, prompt);
});
