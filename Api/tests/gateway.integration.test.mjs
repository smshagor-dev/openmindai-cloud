import { before, after, test } from "node:test";
import assert from "node:assert/strict";
import { createServer } from "node:http";
import { spawn } from "node:child_process";
import { once } from "node:events";

let backend;
let next;
let origin;
let lastRequest;
let output = "";
const key = "fixture-developer-key-secret-value";
const uuid = "f83b7c13-46c0-43a2-a0b1-94d51cad9900";
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

before(async () => {
  backend = createServer(async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    const body = Buffer.concat(chunks).toString("utf8");
    lastRequest = {
      path: req.url,
      method: req.method,
      authorization: req.headers.authorization,
      cookie: req.headers.cookie,
      origin: req.headers.origin,
      body
    };
    res.setHeader("Content-Type", "application/json");
    if (req.url === "/api/v1/login") {
      return res.end(JSON.stringify({ token: "fixture-browser-session", user: { id: 10, name: "Developer" } }));
    }
    if (req.url === "/api/v1/me") {
      if (req.headers.authorization !== "Bearer fixture-browser-session") {
        res.statusCode = 401;
        return res.end(JSON.stringify({ message: "Invalid session." }));
      }
      return res.end(JSON.stringify({ id: 10, name: "Developer", email: "dev@example.test" }));
    }
    if (req.url === "/api/v1/developer/keys") {
      if (req.headers.authorization !== "Bearer fixture-browser-session") {
        res.statusCode = 403;
        return res.end(JSON.stringify({ message: "Not permitted." }));
      }
      if (req.method === "POST") {
        res.statusCode = 201;
        return res.end(JSON.stringify({
          id: 30, name: "developer:Desktop",
          api_key: key,
          base_url: "https://cloud.openmindai.org/api/v1/openmindai"
        }));
      }
      return res.end(JSON.stringify({
        base_url: "https://cloud.openmindai.org/api/v1/openmindai",
        keys: [{ id: 30, name: "developer:Desktop" }]
      }));
    }

    if (req.url?.startsWith("/api/v1/openmindai/")) {
      if (req.headers.authorization !== "Bearer " + key) {
        res.statusCode = 401;
        return res.end(JSON.stringify({ error: { message: "Invalid API key." } }));
      }
      if (req.url === "/api/v1/openmindai/models") {
        return res.end(JSON.stringify({ object: "list", data: [{ id: "openmindai-lite" }] }));
      }
      if (req.url === "/api/v1/openmindai/catalog") {
        res.statusCode = 429;
        res.setHeader("Retry-After", "30");
        return res.end(JSON.stringify({ error: { message: "Rate limited." } }));
      }
      if (req.url === "/api/v1/openmindai/chat/completions") {
        if (JSON.parse(body).stream) {
          res.statusCode = 200;
          res.setHeader("Content-Type", "text/event-stream");
          res.write('data: {"choices":[{"delta":{"content":"Hello"}}]}\n\n');
          return res.end("data: [DONE]\n\n");
        }
        return res.end(JSON.stringify({ choices: [{ message: { role: "assistant", content: "Hello" } }] }));
      }
      if (req.url === "/api/v1/openmindai/models/nvidia-embed/invoke") {
        return res.end(JSON.stringify({ model: "nvidia-embed", output: { embedding: [0.1] } }));
      }
      if (req.url === "/api/v1/openmindai/cosmos/generations") {
        res.statusCode = 202;
        return res.end(JSON.stringify({ id: uuid, status_path: "/api/v1/openmindai/cosmos/" + uuid }));
      }
      if (req.url === "/api/v1/openmindai/cosmos/" + uuid) {
        return res.end(JSON.stringify({ id: uuid, status: "completed", download_path: "/api/v1/openmindai/cosmos/" + uuid + "/download" }));
      }
      if (req.url === "/api/v1/openmindai/cosmos/" + uuid + "/download") {
        res.setHeader("Content-Type", "image/jpeg");
        return res.end(Buffer.from([255, 216, 255, 217]));
      }
    }
    res.statusCode = 404;
    res.end(JSON.stringify({ message: "Not found." }));
  }).listen(0, "127.0.0.1");
  await once(backend, "listening");

  const probe = createServer().listen(0, "127.0.0.1");
  await once(probe, "listening");
  const port = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  origin = "http://127.0.0.1:" + port;
  next = spawn(process.execPath, ["node_modules/next/dist/bin/next", "dev", "-p", String(port)], {
    cwd: process.cwd(),
    env: {
      ...process.env,
      NODE_ENV: "development",
      NEXT_TELEMETRY_DISABLED: "1",
      PORTAL_ORIGIN: origin,
      LARAVEL_API_URL: "http://127.0.0.1:" + backend.address().port + "/api/v1"
    },
    stdio: ["ignore", "pipe", "pipe"]
  });
  next.stdout.on("data", (chunk) => { output += chunk.toString(); });
  next.stderr.on("data", (chunk) => { output += chunk.toString(); });
  let started = false;
  for (let i = 0; i < 110; i++) {
    if (next.exitCode !== null) throw new Error("Next.js exited: " + output.slice(-2000));
    try {
      const resp = await fetch(origin + "/");
      if (resp.ok) { started = true; break; }
    } catch {}
    await pause(250);
  }
  if (!started) throw new Error("Next.js did not start: " + output.slice(-2000));
});

after(async () => {
  next?.kill("SIGTERM");
  if (backend) await new Promise((resolve) => backend.close(resolve));
});

function request(path, options = {}) {
  return fetch(origin + "/api/v1/openmindai/" + path, options);
}
function headers() {
  return { "Authorization": "Bearer " + key, "Content-Type": "application/json" };
}
test("the portal is the developer Base URL, not Laravel", async () => {
  const login = await fetch(origin + "/api/bridge/login", {
    method: "POST",
    headers: { "Origin": origin, "Content-Type": "application/json" },
    body: JSON.stringify({ email: "dev@example.test", password: "test", device_name: "Developer portal" })
  });
  assert.equal(login.status, 200);
  assert.equal((await login.json()).token, undefined);
  assert.match(login.headers.get("set-cookie") || "", /HttpOnly/i);
  const cookie = login.headers.get("set-cookie").split(";")[0];

  const list = await fetch(origin + "/api/bridge/developer/keys", { headers: { Cookie: cookie } });
  assert.equal(list.status, 200);
  assert.equal((await list.json()).base_url, origin + "/api/v1/openmindai");

  const created = await fetch(origin + "/api/bridge/developer/keys", {
    method: "POST",
    headers: { Cookie: cookie, Origin: origin, "Content-Type": "application/json" },
    body: JSON.stringify({ name: "Desktop", password: "correct" })
  });
  assert.equal(created.status, 201);
  const data = await created.json();
  assert.equal(data.base_url, origin + "/api/v1/openmindai");
  assert.equal(data.api_key, key);
});

test("model endpoints require a developer Bearer key, never session cookies", async () => {
  assert.equal((await request("models")).status, 401);
  const noKey = await request("models", { headers: { Cookie: "om_developer_session=fixture-browser-session" } });
  assert.equal(noKey.status, 401);
  const response = await request("models", { headers: headers() });
  assert.equal(response.status, 200);
  assert.equal((await response.json()).data[0].id, "openmindai-lite");
  assert.equal(lastRequest.authorization, "Bearer " + key);
  assert.equal(lastRequest.cookie, undefined);
  assert.equal(lastRequest.origin, undefined);
  assert.equal(response.headers.get("cache-control")?.includes("no-store"), true);
});

test("chat completions forward JSON and stream SSE events", async () => {
  const json = await request("chat/completions", {
    method: "POST", headers: headers(),
    body: JSON.stringify({ model: "openmindai-lite", messages: [{ role: "user", content: "Hello" }] })
  });
  assert.equal(json.status, 200);
  assert.equal((await json.json()).choices[0].message.content, "Hello");
  const stream = await request("chat/completions", {
    method: "POST", headers: headers(),
    body: JSON.stringify({ model: "openmindai-lite", stream: true, messages: [{ role: "user", content: "Hello" }] })
  });
  assert.equal(stream.status, 200);
  assert.match(stream.headers.get("content-type") || "", /text\/event-stream/);
  assert.match(await stream.text(), /data: \[DONE\]/);
});

test("non-chat invoke, queued media status and download are accessible", async () => {
  const invoke = await request("models/nvidia-embed/invoke", {
    method: "POST", headers: headers(), body: JSON.stringify({ input: { input: "Hello" } })
  });
  assert.equal(invoke.status, 200);
  const queued = await request("cosmos/generations", {
    method: "POST", headers: headers(), body: JSON.stringify({ prompt: "Generate an image", model_mode: "text2image" })
  });
  assert.equal(queued.status, 202);
  const status = await request("cosmos/" + uuid, { headers: headers() });
  assert.equal((await status.json()).download_path, "/api/v1/openmindai/cosmos/" + uuid + "/download");
  const download = await request("cosmos/" + uuid + "/download", { headers: headers() });
  assert.equal(download.status, 200);
  assert.equal(download.headers.get("content-type"), "image/jpeg");
  assert.deepEqual(Buffer.from(await download.arrayBuffer()), Buffer.from([255, 216, 255, 217]));
});

test("rate limits and error statuses are preserved", async () => {
  const response = await request("catalog", { headers: headers() });
  assert.equal(response.status, 429);
  assert.equal(response.headers.get("retry-after"), "30");
  assert.equal((await response.json()).error.message, "Rate limited.");
});

test("rejects arbitrary paths, invalid body types, oversize JSON and foreign URLs", async () => {
  assert.equal((await request("provider/invoke", { headers: headers() })).status, 404);
  assert.equal((await request("chat/completions", {
    method: "POST", headers: headers(), body: "{"
  })).status, 400);
  assert.equal((await request("chat/completions", {
    method: "POST", headers: headers(), body: JSON.stringify({ field: "x".repeat(1_050_000) })
  })).status, 413);
  assert.equal((await request("chat/completions", {
    method: "POST",
    headers: { Authorization: "Bearer " + key, "Content-Type": "text/plain" },
    body: "hello"
  })).status, 415);
  assert.equal((await request("models/https:evil/invoke", {
    method: "POST", headers: headers(), body: "{}"
  })).status, 404);
});
