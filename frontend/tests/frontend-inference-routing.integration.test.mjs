import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { generateHostedImage } from "../src/lib/hosted-image.ts";
import { hostedMediaRequest, hostedMediaEnabled } from "../src/lib/hosted-media.ts";

const imageUrl = "https://ai.api.nvidia.com/v1/genai/black-forest-labs/flux.1-schnell";
const speechUrl = "https://877104f7-e885-42b9-8de8-f6e4c6303969.invocation.api.nvcf.nvidia.com/v1/audio/synthesize";
const png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aX1kAAAAASUVORK5CYII=";

const envNames = ["NVIDIA_API_KEY", "FRONTEND_CHAT_BRIDGE_KEY"];
function installTestSecrets() {
  const old = Object.fromEntries(envNames.map(k => [k, process.env[k]]));
  process.env.NVIDIA_API_KEY = "frontend-server-only-provider-key";
  process.env.FRONTEND_CHAT_BRIDGE_KEY = "x".repeat(32);
  return () => {
    for (const key of envNames) {
      if (old[key] === undefined) delete process.env[key];
      else process.env[key] = old[key];
    }
  };
}

test("frontend image inference is only requested through Laravel, never directly through NVIDIA", async () => {
  const restore = installTestSecrets();
  try {
    const urls = [];
    const result = await generateHostedImage(JSON.stringify({prompt:"A sunrise"}), "user-token",
      "https://cloud.openmindai.org/api/v1", new AbortController().signal, {fetcher:async(url,init)=>{
        urls.push({url,init});
        return Response.json({id:3,ids:[3],status:"queued"}, {status:202});
      }});
    assert.equal(result.status,202);
    assert.deepEqual(urls.map(x=>x.url),["https://cloud.openmindai.org/api/v1/media/cloud-image"]);
    assert.equal(urls[0].init.headers.Authorization,"Bearer user-token");
    assert.doesNotMatch(JSON.stringify(urls),/frontend-server-only-provider-key/);
  } finally { restore(); }
});

test("frontend speech inference calls the hosted API directly and stores WAV in Laravel", async () => {
  const restore = installTestSecrets();
  try {
    assert.equal(hostedMediaEnabled(), true);
    const wave = Buffer.alloc(44);
    wave.write("RIFF", 0); wave.write("WAVE", 8);
    const urls = [];
    const fetcher = async (url, init) => {
      urls.push({ url, init });
      if (url.endsWith("/media/hosted/task/begin"))
        return Response.json({ id: 5, status: "running" }, { status: 202 });
      if (url === speechUrl)
        return new Response(wave, { headers: { "Content-Type": "audio/wav" } });
      if (url.endsWith("/media/5/hosted/task/complete"))
        return Response.json({ id: 5, status: "complete" });
      throw Error("Unexpected request: " + url);
    };
    const result = await hostedMediaRequest(
      JSON.stringify({ kind: "speech", prompt: "Hello", language: "en-US" }),
      "user-token", "https://cloud.openmindai.org/api/v1",
      new AbortController().signal, { fetcher },
    );
    assert.equal(result.status, 201);
    assert.equal(urls[1].url, speechUrl);
    assert.equal(urls[1].init.headers.Authorization, "Bearer frontend-server-only-provider-key");
    const saved = JSON.parse(urls[2].init.body);
    assert.equal(saved.mime, "audio/wav");
    assert.equal(saved.data, wave.toString("base64"));
  } finally { restore(); }
});

test("server-only inference routing keeps provider keys private and relay URLs fixed", () => {
  const read = path => readFileSync(new URL("../" + path, import.meta.url), "utf8");
  const chat = read("src/lib/nvidia-chat.ts");
  const image = read("src/lib/hosted-image.ts");
  const media = read("src/lib/hosted-media.ts");
  const route = read("src/app/api/backend/[...path]/route.ts");
  const env = read(".env.example");
  for (const module of [chat, media]) assert.match(module, /process\.env\.NVIDIA_API_KEY/);
  assert.doesNotMatch(image, /ai\.api\.nvidia\.com|integrate\.api\.nvidia\.com/);
  assert.match(image, /media\/cloud-image/);
  assert.match(chat, /frontend_inference_route/);
  assert.match(chat, /String\(url\) === endpoint/);
  assert.doesNotMatch(route, /hosted_inference_enabled/);
  assert.doesNotMatch(route, /provider\/invoke/);
  assert.match(env, /^NVIDIA_API_KEY=/m);
  assert.doesNotMatch(env, /^NEXT_PUBLIC_NVIDIA_API_KEY=/m);
});

test("missing server NVIDIA key never advertises enabled media", () => {
  const prior = process.env.NVIDIA_API_KEY;
  const priorBridge = process.env.FRONTEND_CHAT_BRIDGE_KEY;
  try {
    delete process.env.NVIDIA_API_KEY;
    process.env.FRONTEND_CHAT_BRIDGE_KEY = "x".repeat(32);
    assert.equal(hostedMediaEnabled(), false);
  } finally {
    if (prior === undefined) delete process.env.NVIDIA_API_KEY;
    else process.env.NVIDIA_API_KEY = prior;
    if (priorBridge === undefined) delete process.env.FRONTEND_CHAT_BRIDGE_KEY;
    else process.env.FRONTEND_CHAT_BRIDGE_KEY = priorBridge;
  }
});
