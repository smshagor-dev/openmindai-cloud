import test from "node:test";
import assert from "node:assert/strict";
import { readProviderJson, ProviderResponseError } from "../src/lib/provider-response.ts";

test("parses valid hosted JSON including vendor +json MIME", async () => {
  const response = new Response(JSON.stringify({ artifacts: [{ base64: "abc" }] }), {
    headers: { "Content-Type": "application/problem+json; charset=utf-8" },
  });
  assert.deepEqual(await readProviderJson(response, 2000), { artifacts: [{ base64: "abc" }] });
});

test("stops oversized JSON stream even if Content-Length is missing", async () => {
  let pulls = 0;
  const stream = new ReadableStream({
    pull(controller) {
      pulls++;
      controller.enqueue(new Uint8Array(80).fill(65));
      if (pulls >= 4) controller.close();
    },
  });
  const response = new Response(stream, { headers: { "Content-Type": "application/json" } });
  await assert.rejects(readProviderJson(response, 140), ProviderResponseError);
  assert.equal(pulls < 10, true);
});

test("rejects forged declared response length before streaming", async () => {
  const response = new Response('{"ok":true}', {
    headers: { "Content-Type": "application/json", "Content-Length": "9999" },
  });
  await assert.rejects(readProviderJson(response, 50), /too large/i);
});

test("rejects non-JSON HTML and malformed JSON without exposing provider contents", async () => {
  const html = new Response("<html>private-error-page</html>", {
    headers: { "Content-Type": "text/html" },
  });
  await assert.rejects(readProviderJson(html, 1000), /invalid response/i);
  const broken = new Response('{"error":"secret",}', {
    headers: { "Content-Type": "application/json" },
  });
  await assert.rejects(readProviderJson(broken, 1000), error => {
    assert.equal(error instanceof ProviderResponseError, true);
    assert.doesNotMatch(error.message, /secret/);
    return true;
  });
});
