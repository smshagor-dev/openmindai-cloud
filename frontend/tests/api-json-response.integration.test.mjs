import assert from "node:assert/strict";
import test from "node:test";
import { ApiError, readApiJson, streamChat } from "../src/lib/api.ts";

function jsonResponse(body, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json" },
  });
}

test("valid JSON and empty 204 responses remain supported", async () => {
  assert.deepEqual(await readApiJson(jsonResponse({ ok: true }), "me"), { ok: true });
  assert.equal(await readApiJson(new Response(null, { status: 204 }), "logout"), null);
});

test("HTML returned as a successful API response gives a helpful route error", async () => {
  const response = new Response("<!DOCTYPE html><html>Not an API</html>", {
    headers: { "Content-Type": "text/html; charset=utf-8" },
  });
  await assert.rejects(
    readApiJson(response, "me"),
    (error) => error instanceof ApiError &&
      error.status === 502 &&
      error.message.includes("instead of JSON") &&
      error.message.includes("/api/backend/") &&
      !error.message.includes("DOCTYPE"),
  );
});

test("HTML 404 and non-JSON 502 remain API errors, not JSON parsing exceptions", async () => {
  for (const status of [404, 502]) {
    await assert.rejects(
      readApiJson(new Response("<!DOCTYPE html>", {
        status, headers: { "Content-Type": "text/html" },
      }), "models"),
      (error) => error instanceof ApiError &&
        error.status === status &&
        !error.message.includes("Unexpected token"),
    );
  }
});

test("malformed JSON returns a safe diagnostic without showing server HTML", async () => {
  await assert.rejects(
    readApiJson(new Response("{not-json", {
      headers: { "Content-Type": "application/json" },
    }), "capabilities"),
    (error) => error instanceof ApiError &&
      error.status === 502 &&
      error.message.includes("malformed JSON"),
  );
});

test("validation and authentication errors keep structured server messages", async () => {
  await assert.rejects(
    readApiJson(jsonResponse({ message: "Please sign in." }, 401), "me"),
    (error) => error instanceof ApiError && error.status === 401 &&
      error.message === "Please sign in.",
  );
  await assert.rejects(
    readApiJson(jsonResponse({ errors: { name: ["Name is required."] } }, 422), "register"),
    (error) => error instanceof ApiError && error.status === 422 &&
      error.message === "Name is required.",
  );
});

test("chat stream rejects an HTML page rather than presenting a parse error", async () => {
  const original = globalThis.fetch;
  globalThis.fetch = async () => new Response("<!DOCTYPE html>", {
    status: 200, headers: { "Content-Type": "text/html" },
  });
  try {
    await assert.rejects(
      streamChat({ model_id: 1, message: "Hello" }, () => {}),
      (error) => error instanceof ApiError && error.status === 502 &&
        error.message.includes("streaming setup"),
    );
  } finally {
    globalThis.fetch = original;
  }
});

 test("blank backend errors always have a visible fallback", async () => {
  await assert.rejects(readApiJson(jsonResponse({message: "", errors: {chat: [" "]}}, 403), "chat/stream"),
    error => error instanceof ApiError && error.status === 403 && error.message.includes("403"));
});
