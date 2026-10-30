import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import http from "node:http";
import { spawn } from "node:child_process";
import { createHash, createHmac } from "node:crypto";
import { once } from "node:events";

let backend, next, origin;
let lastRequest;
let logs = "";
let mobileBackendBlocked = false;
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
before(async () => {
  backend = http
    .createServer(async (req, res) => {
      let body = "";
      for await (const chunk of req) body += chunk;
      lastRequest = {
        url: req.url,
        method: req.method,
        authorization: req.headers.authorization,
        mobileIp: req.headers["x-openmind-mobile-ip"],
        mobileTime: req.headers["x-openmind-mobile-time"],
        mobileSignature: req.headers["x-openmind-mobile-signature"],
        body,
      };
      res.setHeader("Content-Type", "application/json");
      if (req.url === "/api/v1/public-config") {
        if (mobileBackendBlocked) {
          res.statusCode = 403;
          res.setHeader("Content-Type", "text/html");
          return res.end("<html>Access denied by Imunify360 bot-protection</html>");
        }
        return res.end(JSON.stringify({
          registration_enabled: true, site_name: "OpenMindAI",
        }));
      }
      if (req.url === "/api/v1/mobile/models")
        return res.end(JSON.stringify({
          data: [{ id: "openmindai-lite", operation: "chat.completions", available: true }],
        }));
      if (req.url === "/api/v1/mobile/chat/completions") {
        res.setHeader("Content-Type", "text/event-stream");
        return res.end('data: {"choices":[{"delta":{"content":"Hello from Cloud"}}]}\n\ndata: [DONE]\n\n');
      }
      if (req.url === "/api/v1/login/verify-ip")
        return res.end(JSON.stringify({
          token: "fixture-mobile-verified-token",
          user: { id: 1, name: "Test User", email: "test@example.com" },
        }));
      if (req.url === "/api/v1/login")
        return res.end(
          JSON.stringify({
            token: "fixture-secret-token",
            user: { id: 1, name: "Test User", email: "test@example.com" },
          }),
        );
      if (req.url === "/api/v1/media/image-capabilities") {
        return res.end(JSON.stringify({ enabled: true, ratios: ["1:1"] }));
      }
      if (req.url === "/api/v1/media/cloud-image" && req.method === "POST") {
        res.statusCode = 202;
        return res.end(JSON.stringify({ id: 9, status: "queued", kind: "image" }));
      }
      if (req.url === "/api/v1/media/9") {
        return res.end(JSON.stringify({
          id: 9, kind: "image", status: "complete", output_mime: "image/png",
        }));
      }
      if (req.url === "/api/v1/media/9/output") {
        res.setHeader("Content-Type", "image/png");
        return res.end(Buffer.from("private-png-fixture"));
      }
      if (req.url === "/api/v1/media/10/output") {
        res.setHeader("Content-Type", "text/html");
        return res.end("<script>invalid media</script>");
      }
      if (req.url === "/api/v1/media" && req.method === "POST") {
        res.statusCode = 202;
        return res.end(JSON.stringify({ id: 9, status: "queued" }));
      }
      if (req.url === "/api/v1/logout") {
        res.statusCode = 204;
        return res.end();
      }
      if (req.url.startsWith("/api/v1/conversations"))
        return res.end(
          JSON.stringify({ data: [], current_page: 2, last_page: 2 }),
        );
      if (req.url === "/api/v1/chat/stream") {
        res.setHeader("Content-Type", "text/event-stream");
        return res.end(
          'event: meta\ndata: {"conversation_id":7,"message_id":8}\n\nevent: delta\ndata: {"content":"Streamed fixture"}\n\nevent: done\ndata: {"status":"complete"}\n\n',
        );
      }
      if (req.url?.startsWith("/api/v1/shared/"))
        return res.end(
          JSON.stringify({ title: "Public snapshot", messages: [] }),
        );
      if (req.url === "/api/v1/guest/chat") return res.end(JSON.stringify({content: "Guest fixture response", remaining: 4}));
      if (req.url === "/api/v1/chat")
        return res.end(
          JSON.stringify({
            conversation_id: 7,
            message: { role: "assistant", content: "Fixture response" },
          }),
        );
      if (req.url === "/api/v1/models") {
        res.statusCode = 429;
        res.setHeader("Retry-After", "25");
        return res.end(JSON.stringify({ message: "Too many requests." }));
      }
      if (req.url === "/api/v1/developer/keys") {
        if (req.method === "POST") {
          res.statusCode = 201;
          return res.end(JSON.stringify({
            id: 6,
            api_key: "only-once-fixture-key",
            base_url: "https://models.example/api/v1/openmindai",
            expires_at: "2027-01-01T00:00:00Z"
          }));
        }
        return res.end(JSON.stringify({
          base_url: "https://models.example/api/v1/openmindai",
          keys: []
        }));
      }
      if (/^\/api\/v1\/developer\/keys\/\d+$/.test(req.url || "") && req.method === "DELETE") {
        res.statusCode = 204;
        return res.end();
      }
      if (req.url === "/api/v1/tokens") {
        res.setHeader("Content-Type", "text/html");
        return res.end("<html>upstream error</html>");
      }
      if (req.url === "/api/v1/email/change/confirm")
        return res.end(JSON.stringify({ message: "Email changed." }));
      if (req.url === "/api/v1/me/avatar")
        return res.end(
          JSON.stringify({ id: 1, avatar: "data:image/png;base64,fixture" }),
        );
      if (req.url === "/api/v1/settings/data")
        return res.end(JSON.stringify({ message: "Completed." }));
      if (req.url === "/api/v1/preferences")
        return res.end(
          JSON.stringify({
            theme: "system",
            send_on_enter: false,
            response_language: "Bangla",
          }),
        );
      if (req.url === "/api/v1/assistants" || req.url === "/api/v1/tasks")
        return res.end(JSON.stringify([]));
      if (req.url === "/api/v1/tasks/7/runs")
        return res.end(
          JSON.stringify({ data: [], current_page: 1, last_page: 1 }),
        );
      if (req.url === "/api/v1/tasks/7/runs/2/resume") {
        res.statusCode = 202;
        return res.end(JSON.stringify({ id: 2, status: "queued" }));
      }
      if (req.url.startsWith("/api/v1/explore/assistants")) {
        if (req.url.endsWith("/copy")) {
          res.statusCode = 201;
          return res.end(JSON.stringify({ id: 4, name: "Tutor" }));
        }
        return res.end(
          JSON.stringify({ data: [], current_page: 1, last_page: 1 }),
        );
      }
      if (req.url === "/api/v1/canvases/3/versions")
        return res.end(JSON.stringify([{ version: 1 }]));
      if (req.url === "/api/v1/me")
        return res.end(JSON.stringify({ id: 1, name: "Test User" }));
      if (
        /^\/api\/v1\/(teams|team-invites|connectors|connector-calls|live)(\/|$)/.test(
          req.url,
        )
      ) {
        if (req.method === "DELETE") {
          res.statusCode = 204;
          return res.end();
        }
        return res.end(JSON.stringify({ ok: true }));
      }
      res.statusCode = 404;
      res.end("{}");
    })
    .listen(0, "127.0.0.1");
  await once(backend, "listening");
  const probe = http.createServer().listen(0, "127.0.0.1");
  await once(probe, "listening");
  const port = probe.address().port;
  await new Promise((resolve) => probe.close(resolve));
  origin = `http://127.0.0.1:${port}`;
  next = spawn(
    process.execPath,
    [
      "node_modules/next/dist/bin/next",
      "start",
      "-H",
      "127.0.0.1",
      "-p",
      String(port),
    ],
    {
      cwd: process.cwd(),
      env: {
        ...process.env,
        NEXT_TELEMETRY_DISABLED: "1",
        // These fixtures exercise the local backend proxy independently of .env.
        CHAT_PROVIDER: "local",
        FRONTEND_CHAT_BRIDGE_KEY: "0123456789abcdef0123456789abcdef",
        VERCEL: "0",
        FRONTEND_URL: origin,
        LARAVEL_API_URL: `http://127.0.0.1:${backend.address().port}/api/v1`,
      },
      stdio: ["ignore", "pipe", "pipe"],
    },
  );
  next.stdout.on("data", (data) => {
    logs += data;
  });
  next.stderr.on("data", (data) => {
    logs += data;
  });
  for (let i = 0; i < 80; i++) {
    try {
      const r = await fetch(origin);
      if (r.ok) return;
    } catch {}
    await delay(200);
  }
  throw new Error(`Next.js did not start: ${logs}`);
});
after(async () => {
  next?.kill("SIGTERM");
  await new Promise((resolve) => backend?.close(resolve));
});
const request = (path, options = {}) =>
  fetch(`${origin}/api/backend/${path}`, options);
const headers = () => ({ "Content-Type": "application/json", Origin: origin });
async function login() {
  return request("login", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({
      email: "test@example.com",
      password: "password",
      device_name: "web",
    }),
  });
}
test("workspace renders the welcome layout", async () => {
  const r = await fetch(origin);
  const html = await r.text();
  assert.ok(html.includes("What can I help with?"));
  assert.ok(html.includes("OpenMindAI"));
  assert.ok(html.includes("openmindai-logo.png"));
  assert.ok(html.includes("New chat"));
  assert.ok(html.includes("Ask anything"));
});
test("frontend health endpoint stays JSON even without backend credentials", async () => {
  const response = await fetch(origin + "/api/health");
  assert.equal(response.status, 200);
  assert.match(response.headers.get("content-type") || "", /application\/json/);
  const health = await response.json();
  assert.equal(health.service, "OpenMindAI frontend");
  assert.equal(health.frontend, "ok");
  assert.equal(health.backendConfigured, true);
  assert.equal(JSON.stringify(health).includes("127.0.0.1"), false);
});

test("protected requests reject missing cookies", async () => {
  assert.equal((await request("me")).status, 401);
});
test("cross-origin mutations are rejected", async () => {
  const r = await request("login", {
    method: "POST",
    headers: { ...headers(), Origin: "https://evil.example" },
    body: "{}",
  });
  assert.equal(r.status, 403);
});
test("token stays in an HttpOnly cookie and is removed from JSON", async () => {
  const r = await login();
  assert.equal(r.status, 200);
  assert.match(r.headers.get("set-cookie"), /HttpOnly/i);
  assert.match(r.headers.get("set-cookie"), /SameSite=lax/i);
  assert.match(r.headers.get("set-cookie"), /Secure/i);
  const data = await r.json();
  assert.equal(data.token, undefined);
  assert.equal(data.user.name, "Test User");
});
test("cookie authenticates upstream, and chat response contract is preserved", async () => {
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  const r = await request("chat", {
    method: "POST",
    headers: { ...headers(), Cookie: cookie },
    body: JSON.stringify({ model_id: 1, message: "Hi" }),
  });
  assert.equal(r.status, 200);
  assert.equal((await r.json()).message.content, "Fixture response");
  assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  assert.equal(JSON.parse(lastRequest.body).message, "Hi");
});
test("only numeric pagination reaches the upstream", async () => {
  await request("conversations?page=2&url=https://evil.example", {
    headers: { Cookie: "openmindai_token=fixture-secret-token" },
  });
  assert.equal(lastRequest.url, "/api/v1/conversations?page=2");
});
test("arbitrary upstream paths and non-JSON mutations are blocked", async () => {
  assert.equal((await request("admin/users")).status, 404);
  assert.equal(
    (
      await request("login", {
        method: "POST",
        headers: { Origin: origin },
        body: "{}",
      })
    ).status,
    415,
  );
});
test("rate limit status and retry header are preserved", async () => {
  const r = await request("models", {
    headers: { Cookie: "openmindai_token=fixture-secret-token" },
  });
  assert.equal(r.status, 429);
  assert.equal(r.headers.get("retry-after"), "25");
});
test("invalid upstream HTML is not exposed", async () => {
  const r = await request("tokens", {
    headers: { Cookie: "openmindai_token=fixture-secret-token" },
  });
  assert.equal(r.status, 502);
  assert.ok(!(await r.text()).includes("<html>"));
});
test("logout removes the browser cookie", async () => {
  const r = await request("logout", {
    method: "POST",
    headers: {
      ...headers(),
      Cookie: "openmindai_token=fixture-secret-token",
    },
    body: "{}",
  });
  assert.equal(r.status, 204);
  assert.match(r.headers.get("set-cookie"), /openmindai_token=;/);
});

test("oversized JSON is rejected before forwarding", async () => {
  const r = await request("login", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ email: "x".repeat(100_001) }),
  });
  assert.equal(r.status, 413);
});

test("SSE events pass through without being converted into JSON", async () => {
  const r = await request("chat/stream", {
    method: "POST",
    headers: { ...headers(), Cookie: "openmindai_token=fixture-secret-token" },
    body: JSON.stringify({ model_id: 1, message: "Hello" }),
  });
  assert.equal(r.status, 200);
  assert.match(r.headers.get("content-type"), /text\/event-stream/);
  const text = await r.text();
  assert.ok(text.includes("event: delta"));
  assert.ok(text.includes("Streamed fixture"));
});
test("shared snapshots can be read without sending a bearer token", async () => {
  const r = await request("shared/" + "a".repeat(64));
  assert.equal(r.status, 200);
  assert.equal((await r.json()).title, "Public snapshot");
  assert.equal(lastRequest.authorization, undefined);
});
test("brand asset is served from this application", async () => {
  const r = await fetch(origin + "/openmindai-logo.png");
  assert.equal(r.status, 200);
  assert.match(r.headers.get("content-type"), /image\/png/);
});

test("media uploads accept bounded base64 JSON through the authenticated proxy", async () => {
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  const r = await request("media", {
    method: "POST",
    headers: { ...headers(), Cookie: cookie },
    body: JSON.stringify({
      kind: "vision",
      prompt: "Describe",
      input: "a".repeat(200_000),
    }),
  });
  assert.equal(r.status, 202);
  assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  assert.equal(JSON.parse(lastRequest.body).input.length, 200_000);
});
test("media binary downloads preserve privacy and reject HTML", async () => {
  assert.equal((await request("media/9/output")).status, 401);
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  const r = await request("media/9/output", { headers: { Cookie: cookie } });
  assert.equal(r.status, 200);
  assert.equal(r.headers.get("content-type"), "image/png");
  assert.match(r.headers.get("cache-control"), /private.*no-store/);
  assert.match(
    r.headers.get("content-disposition"),
    /attachment; filename="openmindai-9.png"/,
  );
  assert.equal(await r.text(), "private-png-fixture");
  assert.equal(
    (await request("media/10/output", { headers: { Cookie: cookie } })).status,
    502,
  );
});

test("developer key endpoints require login and remain origin protected", async () => {
  assert.equal((await request("developer/keys")).status, 401);
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  const list = await request("developer/keys", { headers: { Cookie: cookie } });
  assert.equal(list.status, 200);
  assert.deepEqual((await list.json()).keys, []);

  const created = await request("developer/keys", {
    method: "POST",
    headers: { ...headers(), Cookie: cookie },
    body: JSON.stringify({ name: "OpenMind Studio", password: "correct" }),
  });
  assert.equal(created.status, 201);
  assert.equal((await created.json()).api_key, "only-once-fixture-key");
  assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  const revoked = await request("developer/keys/6", {
    method: "DELETE",
    headers: { ...headers(), Cookie: cookie },
    body: "{}",
  });
  assert.equal(revoked.status, 204);
  const denied = await request("developer/keys", {
    method: "POST",
    headers: { ...headers(), Origin: "https://evil.example", Cookie: cookie },
    body: "{}",
  });
  assert.equal(denied.status, 403);
});

test("profile settings and data actions use the authenticated proxy", async () => {
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  let r = await request("preferences", {
    method: "PATCH",
    headers: { ...headers(), Cookie: cookie },
    body: JSON.stringify({ theme: "system", send_on_enter: false }),
  });
  assert.equal(r.status, 200);
  assert.equal((await r.json()).send_on_enter, false);
  assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  r = await request("me/avatar", {
    method: "POST",
    headers: { ...headers(), Cookie: cookie },
    body: JSON.stringify({ image: "a".repeat(120_000) }),
  });
  assert.equal(r.status, 200);
  r = await request("settings/data", {
    method: "POST",
    headers: { ...headers(), Cookie: cookie },
    body: JSON.stringify({ action: "delete_chats" }),
  });
  assert.equal(r.status, 200);
  assert.equal(
    (
      await request("settings/data", {
        method: "POST",
        headers: headers(),
        body: "{}",
      })
    ).status,
    401,
  );
});
test("email confirmation is explicit, unauthenticated and clears the old cookie", async () => {
  const page = await fetch(
    `${origin}/confirm-email?account=1&token=${"a".repeat(64)}`,
  );
  assert.equal(page.status, 200);
  assert.ok((await page.text()).includes("Confirm my email change"));
  const r = await request("email/change/confirm", {
    method: "POST",
    headers: headers(),
    body: JSON.stringify({ account: 1, token: "a".repeat(64) }),
  });
  assert.equal(r.status, 200);
  assert.equal(lastRequest.authorization, undefined);
  assert.match(
    r.headers.get("set-cookie"),
    /(?:Max-Age=0|Expires=Thu, 01 Jan 1970)/,
  );
});

test("workspace tools and canvas history use only allowlisted authenticated routes", async () => {
  assert.equal((await request("assistants")).status, 401);
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  for (const path of [
    "assistants",
    "tasks",
    "tasks/7/runs",
    "canvases/3/versions",
  ]) {
    const r = await request(path, { headers: { Cookie: cookie } });
    assert.equal(r.status, 200);
    assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  }
  assert.equal(
    (await request("tasks/7/execute-shell", { headers: { Cookie: cookie } }))
      .status,
    404,
  );
});

test("task resume is forwarded securely while private memory bridge stays inaccessible", async () => {
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  const r = await request("tasks/7/runs/2/resume", {
    method: "POST",
    headers: { ...headers(), Cookie: cookie },
    body: "{}",
  });
  assert.equal(r.status, 202);
  assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  assert.equal(
    (
      await request("chat/frontend/memory", {
        method: "POST",
        headers: { ...headers(), Cookie: cookie },
        body: "{}",
      })
    ).status,
    404,
  );
});

test("public assistant browsing omits tokens and copying requires authentication", async () => {
  const slug = "11111111-1111-4111-8111-111111111111";
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  assert.equal(
    (
      await request("explore/assistants?q=Tutor", {
        headers: { Cookie: cookie },
      })
    ).status,
    200,
  );
  assert.equal(lastRequest.authorization, undefined);
  assert.equal((await request(`explore/assistants/${slug}`)).status, 200);
  assert.equal(
    (
      await request(`explore/assistants/${slug}/copy`, {
        method: "POST",
        headers: headers(),
        body: "{}",
      })
    ).status,
    401,
  );
  assert.equal(
    (
      await request(`explore/assistants/${slug}/copy`, {
        method: "POST",
        headers: { ...headers(), Cookie: cookie },
        body: "{}",
      })
    ).status,
    201,
  );
  assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
});

test("teams, approved connectors and live input remain authenticated and origin-protected", async () => {
  for (const path of ["teams", "connectors"])
    assert.equal((await request(path)).status, 401);
  const auth = await login();
  const cookie = auth.headers.get("set-cookie").split(";")[0];
  for (const path of [
    "teams",
    "teams/2",
    "teams/2/documents/3",
    "teams/2/threads/4",
    "connectors",
  ]) {
    const response = await request(path, { headers: { Cookie: cookie } });
    assert.equal(response.status, 200);
    assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  }
  for (const path of [
    "teams/2/preview",
    "teams/2/invites",
    "teams/2/documents",
    "teams/2/threads/4",
    "team-invites/3/accept",
    "connectors/docs/connect",
    "connectors/docs/tools",
    "connectors/docs/call",
    "live/start",
    "live/stop",
  ]) {
    assert.equal(
      (
        await request(path, {
          method: "POST",
          headers: { ...headers(), Cookie: cookie },
          body: "{}",
        })
      ).status,
      200,
    );
    assert.equal(lastRequest.authorization, "Bearer fixture-secret-token");
  }
  for (const path of ["teams/2/members/3", "teams/2/documents/3"])
    assert.equal(
      (
        await request(path, {
          method: "PUT",
          headers: { ...headers(), Cookie: cookie },
          body: "{}",
        })
      ).status,
      200,
    );
  assert.equal(
    (
      await request("live/input", {
        method: "POST",
        headers: { ...headers(), Cookie: cookie },
        body: JSON.stringify({ input: "a".repeat(150000) }),
      })
    ).status,
    200,
  );
  assert.equal(
    (
      await request("live/start", {
        method: "POST",
        headers: {
          Cookie: cookie,
          "Content-Type": "application/json",
          Origin: "https://attacker.example",
        },
        body: "{}",
      })
    ).status,
    403,
  );
  assert.equal(
    (
      await request("connectors/docs/endpoint", {
        method: "POST",
        headers: { ...headers(), Cookie: cookie },
        body: "{}",
      })
    ).status,
    404,
  );
  for (const path of [
    "teams/2",
    "teams/2/members/3",
    "teams/2/invites/4",
    "connectors/docs",
    "connector-calls/8",
  ])
    assert.equal(
      (
        await request(path, {
          method: "DELETE",
          headers: { ...headers(), Cookie: cookie },
          body: "{}",
        })
      ).status,
      204,
    );
});

test("guest chat works without a token, still rejects cross-origin requests and supports conversation URLs", async () => {
  const r = await request("guest/chat", {method: "POST", headers: headers(), body: JSON.stringify({message: "Hello"})});
  assert.equal(r.status, 200);
  assert.equal((await r.json()).remaining, 4);
  assert.equal(lastRequest.authorization, undefined);
  const crossOrigin = await request("guest/chat", {method: "POST", headers: {"Content-Type": "application/json", Origin: "https://other.example"}, body: JSON.stringify({message: "Hello"})});
  assert.equal(crossOrigin.status, 403);
  const conversation = await fetch(`${origin}/c/7-example-chat`);
  assert.equal(conversation.status, 200);
  assert.match(await conversation.text(), /OpenMindAI/);
});


const mobileRequest = (path, options = {}) =>
  fetch(origin + "/api/mobile/v1/" + path, options);

test("mobile gateway checks real Laravel backend status", async () => {
  const status = await mobileRequest("status");
  assert.equal(status.status, 200);
  assert.deepEqual(await status.json(), { gateway: "ok", backend: "connected" });
  const config = await mobileRequest("public-config");
  assert.equal(config.status, 200);
  assert.equal((await config.json()).registration_enabled, true);
});

test("mobile login returns Sanctum token, not a browser cookie", async () => {
  const response = await mobileRequest("login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      email: "test@example.com",
      password: "fixture-password",
      device_name: "OpenMindAI Mobile",
    }),
  });
  assert.equal(response.status, 200);
  assert.equal((await response.json()).token, "fixture-secret-token");
  assert.equal(response.headers.get("set-cookie"), null);
  assert.equal(lastRequest.authorization, undefined);
  assert.ok(lastRequest.mobileIp, "signed client IP must reach Laravel");
  assert.ok(lastRequest.mobileTime);
  const message = [
    lastRequest.mobileTime, lastRequest.mobileIp, "POST", "/api/v1/login",
    createHash("sha256").update(lastRequest.body).digest("hex"),
  ].join("\n");
  assert.equal(lastRequest.mobileSignature,
    createHmac("sha256", "0123456789abcdef0123456789abcdef")
      .update(message).digest("hex"));
  assert.match(response.headers.get("cache-control") || "", /no-store/);
});

test("mobile unfamiliar-network OTP returns a bearer session", async () => {
  const response = await mobileRequest("login/verify-ip", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({
      challenge_id: "00000000-0000-4000-8000-000000000001",
      code: "123456",
    }),
  });
  assert.equal(response.status, 200);
  assert.equal((await response.json()).token, "fixture-mobile-verified-token");
  assert.equal(response.headers.get("set-cookie"), null);
});

test("mobile APIs reject browser cookies and require a bearer token", async () => {
  assert.equal((await mobileRequest("me")).status, 401);
  assert.equal((await mobileRequest("mobile/models", {
    headers: { Cookie: "openmindai_token=fixture-secret-token" },
  })).status, 401);
  const response = await mobileRequest("mobile/models", {
    headers: { Authorization: "Bearer fixture-mobile-secret-token" },
  });
  assert.equal(response.status, 200);
  assert.equal((await response.json()).data[0].id, "openmindai-lite");
  assert.equal(lastRequest.authorization, "Bearer fixture-mobile-secret-token");
});

test("native Cloud image requests use private authenticated media routes", async () => {
  const token = { Authorization: "Bearer fixture-mobile-secret-token" };
  assert.equal((await mobileRequest("media/image-capabilities")).status, 401);
  const capabilities = await mobileRequest("media/image-capabilities",
    { headers: token });
  assert.equal(capabilities.status, 200);
  assert.equal((await capabilities.json()).enabled, true);

  const create = await mobileRequest("media/cloud-image", {
    method: "POST",
    headers: { ...token, "Content-Type": "application/json" },
    body: JSON.stringify({ prompt: "a dog in a park", mode: "generate", count: 1 }),
  });
  assert.equal(create.status, 202);
  assert.equal((await create.json()).id, 9);
  assert.equal(lastRequest.authorization, token.Authorization);

  const status = await mobileRequest("media/9", { headers: token });
  assert.equal((await status.json()).status, "complete");
  const output = await mobileRequest("media/9/output", { headers: token });
  assert.equal(output.status, 200);
  assert.equal(output.headers.get("content-type"), "image/png");
  assert.equal(output.headers.get("set-cookie"), null);
  assert.match(output.headers.get("cache-control") || "", /no-store/);
  assert.equal(await output.text(), "private-png-fixture");
});

test("media gateway rejects arbitrary paths and non-image HTML downloads", async () => {
  const headers = { Authorization: "Bearer fixture-mobile-secret-token" };
  assert.equal((await mobileRequest("media/../tokens",
    { headers })).status, 404);
  assert.equal((await mobileRequest("media/abc/output",
    { headers })).status, 404);
  const response = await mobileRequest("media/10/output", { headers });
  assert.equal(response.status, 502);
  assert.equal(JSON.stringify(await response.json()).includes("<script>"), false);
});

test("native mobile SSE streams and preserves bearer authentication", async () => {
  const response = await mobileRequest("mobile/chat/completions", {
    method: "POST",
    headers: {
      Authorization: "Bearer fixture-mobile-secret-token",
      "Content-Type": "application/json",
    },
    body: JSON.stringify({
      model: "openmindai-lite",
      messages: [{ role: "user", content: "Hello" }],
      stream: true,
    }),
  });
  assert.equal(response.status, 200);
  assert.match(response.headers.get("content-type"), /text\/event-stream/);
  assert.match(await response.text(), /data: \[DONE\]/);
  assert.equal(lastRequest.authorization, "Bearer fixture-mobile-secret-token");
});

test("native gateway rejects browser origins, unknown paths and oversized bodies", async () => {
  assert.equal((await mobileRequest("login", {
    method: "POST",
    headers: { Origin: origin, "Content-Type": "application/json" },
    body: "{}",
  })).status, 403);
  assert.equal((await mobileRequest("evil/admin-token")).status, 404);
  assert.equal((await mobileRequest("me?url=https://evil.example", {
    headers: { Authorization: "Bearer fixture-mobile-secret-token" },
  })).status, 400);
  assert.equal((await mobileRequest("login", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ message: "a".repeat(45000) }),
  })).status, 413);
});

test("mobile status identifies upstream WAF blocks without returning HTML", async () => {
  mobileBackendBlocked = true;
  try {
    const status = await mobileRequest("status");
    assert.equal(status.status, 503);
    assert.equal((await status.json()).reason, "HOSTING_FIREWALL_BLOCKED");
    const response = await mobileRequest("public-config");
    assert.equal(response.status, 503);
    const data = await response.json();
    assert.equal(data.code, "HOSTING_FIREWALL_BLOCKED");
    assert.equal(JSON.stringify(data).includes("<html>"), false);
  } finally {
    mobileBackendBlocked = false;
  }
});

test("legacy document preview requires an authenticated same-origin request", async () => {
  const response = await fetch(`${origin}/api/document-preview`, {method: "POST", headers: headers(), body: JSON.stringify({data: "eA=="})});
  assert.equal(response.status, 401);
});
