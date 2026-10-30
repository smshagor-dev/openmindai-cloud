import test from "node:test";
import assert from "node:assert/strict";
import { hostedMediaRequest } from "../src/lib/hosted-media.ts";
import { renderDocx, renderPdf } from "../src/lib/document-files.ts";

const cfg = { apiKey: "private-test-key", bridgeKey: "z".repeat(32), chatModel: "test/chat" };
const wav = Buffer.alloc(52);
wav.write("RIFF", 0); wav.writeUInt32LE(wav.length - 8, 4);
wav.write("WAVE", 8); wav.write("fmt ", 12); wav.writeUInt32LE(16, 16);
wav.writeUInt16LE(1, 20); wav.writeUInt16LE(1, 22);
wav.writeUInt32LE(16000, 24); wav.writeUInt32LE(32000, 28);
wav.writeUInt16LE(2, 32); wav.writeUInt16LE(16, 34);
wav.write("data", 36); wav.writeUInt32LE(8, 40);
const video = Buffer.from([0, 0, 0, 16, 102, 116, 121, 112, 105, 115, 111, 109, 0, 0, 0, 0]);

function scenario({ oversizedSpeech = false } = {}) {
  const calls = [];
  const fetcher = async (url, init) => {
    calls.push({ url, init });
    if (url.endsWith("/media/hosted/task/begin"))
      return Response.json({ id: 14, status: "running" }, { status: 202 });
    if (url.endsWith("/media/14/hosted/task/complete"))
      return Response.json({ id: 14, status: "complete" });
    if (url.endsWith("/media/14/hosted/task/fail"))
      return new Response(null, { status: 204 });
    if (url.endsWith("/audio/transcriptions"))
      return Response.json({ text: "This is a sample transcript." });
    if (url.endsWith("/audio/synthesize"))
      return new Response(wav, { headers: {
        "Content-Type": "audio/wav",
        ...(oversizedSpeech ? { "Content-Length": "20000001" } : {}),
      } });
    if (url.endsWith("/cosmos3-nano"))
      return Response.json({ b64_video: video.toString("base64") });
    if (url.endsWith("/chat/completions"))
      return Response.json({ choices: [{ message: { content: "# Report\nProject summary." } }] });
    throw Error("Unexpected URL: " + url);
  };
  return { fetcher, calls };
}
function run(s, kind, extras = {}) {
  return hostedMediaRequest(
    JSON.stringify({ kind, prompt: kind === "transcription" ? "" : "Example project", ...extras }),
    "signed-in-user",
    "https://private-backend/api/v1",
    new AbortController().signal,
    { fetcher: s.fetcher, config: cfg },
  );
}
test("speech-to-text uploads WAV through authenticated hosted transcription API", async () => {
  const s = scenario();
  const result = await run(s, "transcription", { input: wav.toString("base64") });
  assert.equal(result.status, 201);
  assert.equal(s.calls.length, 3);
  const form = s.calls[1].init.body;
  assert.equal(form.get("language"), "en-US");
  assert.equal(form.get("file").type, "audio/wav");
  assert.equal(s.calls[1].init.headers.Authorization, "Bearer private-test-key");
  assert.equal(s.calls[0].init.headers["X-Chat-Bridge-Key"], cfg.bridgeKey);
  assert.equal(JSON.parse(s.calls[2].init.body).text, "This is a sample transcript.");
});
test("rejects invalid audio before provider invocation", async () => {
  const s = scenario();
  const result = await run(s, "transcription", { input: Buffer.from("bad").toString("base64") });
  assert.equal(result.status, 422);
  assert.equal(s.calls.length, 0, "Reject malformed audio before quota reservation.");
});
test("oversized generated audio is never read into memory or saved", async () => {
  const s = scenario({ oversizedSpeech: true });
  const result = await run(s, "speech");
  assert.equal(result.status, 502);
  assert.equal(s.calls.length, 3);
  assert.equal(s.calls[2].url.endsWith("/fail"), true);
});
test("audio generation sends text and stores validated WAV", async () => {
  const s = scenario();
  const result = await run(s, "speech");
  assert.equal(result.status, 201);
  assert.equal(s.calls[1].init.body.get("voice"), "Magpie-Multilingual.EN-US.Aria");
  const complete = JSON.parse(s.calls[2].init.body);
  assert.equal(complete.mime, "audio/wav");
  assert.deepEqual(Buffer.from(complete.data, "base64"), wav);
});
test("hosted speech uses documented Spanish and French voice selections", async () => {
  for (const [language, voice] of [
    ["es-US", "Magpie-Multilingual.ES-US.Diego"],
    ["fr-FR", "Magpie-Multilingual.FR-FR.Louise"],
  ]) {
    const s = scenario();
    const result = await run(s, "speech", { language });
    assert.equal(result.status, 201);
    const form = s.calls[1].init.body;
    assert.equal(form.get("language"), language);
    assert.equal(form.get("voice"), voice);
  }
});

test("invalid speech language is rejected before reserving task quota", async () => {
  const s = scenario();
  const result = await run(s, "speech", { language: "xx-XX" });
  assert.equal(result.status, 422);
  assert.equal(s.calls.length, 0);
});

test("video generation follows documented text-to-video API contract", async () => {
  const s = scenario();
  const result = await run(s, "video");
  assert.equal(result.status, 201);
  const request = JSON.parse(s.calls[1].init.body);
  assert.equal(request.model_mode, "text2video");
  assert.equal(request.resolution, "480_16_9");
  const output = JSON.parse(s.calls[2].init.body);
  assert.equal(output.mime, "video/mp4");
  assert.deepEqual(Buffer.from(output.data, "base64"), video);
});
test("generates Word documents with a real archive and Unicode XML", async () => {
  const s = scenario();
  const result = await run(s, "document_create", { format: "docx" });
  assert.equal(result.status, 201);
  const data = JSON.parse(s.calls[2].init.body);
  const docx = Buffer.from(data.data, "base64");
  assert.equal(data.mime, "application/vnd.openxmlformats-officedocument.wordprocessingml.document");
  assert.equal(docx.toString("ascii", 0, 2), "PK");
  const manual = renderDocx("বাংলা & English");
  assert.ok(manual.includes(Buffer.from("word/document.xml")));
  assert.equal(manual.toString("ascii", 0, 2), "PK");
});
test("generates a PDF with header and cross-reference table", async () => {
  const s = scenario();
  const result = await run(s, "document_create", { format: "pdf" });
  assert.equal(result.status, 201);
  const data = JSON.parse(s.calls[2].init.body);
  assert.equal(data.mime, "application/pdf");
  const pdf = Buffer.from(data.data, "base64");
  assert.equal(pdf.toString("ascii", 0, 8), "%PDF-1.4");
  assert.ok(pdf.toString("latin1").includes("xref"));
  assert.throws(() => renderPdf("বাংলা"), /Choose DOCX/);
});
test("PDF output keeps WinAnsi Euro, ellipsis and trademark glyphs", () => {
  const pdf = renderPdf("Price €20 … brand™");
  const result = pdf.toString("latin1");
  assert.equal(pdf.subarray(0, 5).toString("ascii"), "%PDF-");
  // The built-in Helvetica font uses Windows-1252 single-byte glyph codes.
  assert.ok(result.includes(String.fromCharCode(0x80)), "Euro");
  assert.ok(result.includes(String.fromCharCode(0x85)), "Ellipsis");
  assert.ok(result.includes(String.fromCharCode(0x99)), "Trademark");
});

test("PDF breaks long URLs and unspaced words within the page width", () => {
  const pdf = renderPdf("X".repeat(240));
  const commands = [...pdf.toString("latin1").matchAll(/Tm \(([^)]*)\) Tj/g)];
  assert.deepEqual(commands.map(match => match[1].length), [87, 87, 66]);
});

test("expired backend session is returned as 401 without contacting provider", async () => {
  const calls = [];
  const response = await hostedMediaRequest(
    JSON.stringify({ kind: "speech", prompt: "Hello" }),
    "expired-user",
    "https://private-backend/api/v1",
    new AbortController().signal,
    {
      config: cfg,
      fetcher: async (url) => {
        calls.push(url);
        return Response.json({ message: "Unauthorized" }, { status: 401 });
      },
    },
  );
  assert.equal(response.status, 401);
  assert.equal(calls.length, 1);
  assert.ok(calls[0].endsWith("/media/hosted/task/begin"));
});

test("cancelled request never contacts media inference once task begins", async () => {
  const controller = new AbortController();
  const s = scenario();
  const response = await hostedMediaRequest(
    JSON.stringify({ kind: "speech", prompt: "Hello" }),
    "signed-in-user",
    "https://private-backend/api/v1",
    controller.signal,
    {
      config: cfg,
      fetcher: async (url, init) => {
        const result = await s.fetcher(url, init);
        if (url.endsWith("/media/hosted/task/begin")) controller.abort();
        return result;
      },
    },
  );
  assert.equal(response.status, 499);
  assert.deepEqual(s.calls.map(x => x.url.split("/").at(-1)), ["begin", "fail"]);
});

test("cancel after inference discards output without persisting completed media", async () => {
  const controller = new AbortController();
  const s = scenario();
  const response = await hostedMediaRequest(
    JSON.stringify({ kind: "speech", prompt: "Hello" }),
    "signed-in-user",
    "https://private-backend/api/v1",
    controller.signal,
    {
      config: cfg,
      fetcher: async (url, init) => {
        const result = await s.fetcher(url, init);
        if (url.endsWith("/audio/synthesize")) controller.abort();
        return result;
      },
    },
  );
  assert.equal(response.status, 499);
  assert.deepEqual(s.calls.map(x => x.url.split("/").at(-1)), ["begin", "synthesize", "fail"]);
});

test("deleted media task cannot be mistaken for storage failure", async () => {
  const s = scenario();
  const response = await hostedMediaRequest(
    JSON.stringify({ kind: "speech", prompt: "Hello" }),
    "signed-in-user",
    "https://private-backend/api/v1",
    new AbortController().signal,
    {
      config: cfg,
      fetcher: (url, init) => url.endsWith("/hosted/task/complete")
        ? Promise.resolve(Response.json({ message: "Not found" }, { status: 404 }))
        : s.fetcher(url, init),
    },
  );
  assert.equal(response.status, 409);
  assert.match((await response.json()).message, /deleted or cancelled/);
  assert.equal(s.calls.some(x => x.url.endsWith("/audio/synthesize")), true);
});

test("invalid requests and absent server credentials never contact provider", async () => {
  const s = scenario();
  const first = await hostedMediaRequest(
    JSON.stringify({ kind: "video", prompt: "Test" }),
    "user", "https://backend", new AbortController().signal,
    { fetcher: s.fetcher, config: { ...cfg, apiKey: "" } },
  );
  assert.equal(first.status, 503);
  const second = await run(s, "speech", { prompt: "x".repeat(1001) });
  assert.equal(second.status, 422);
  assert.equal(s.calls.length, 0);
});


test("document creation uses backend inference without a frontend NVIDIA key", async () => {
  const calls = [];
  const response = await hostedMediaRequest(JSON.stringify({kind:"document_create",prompt:"Write a short test",format:"docx"}),
    "signed-in-user", "https://backend/api/v1", new AbortController().signal, {
      config:{apiKey:"",bridgeKey:"z".repeat(32)},
      fetcher:async (url, init) => {
        calls.push({url,init});
        if (url.endsWith("/chat/provider")) return Response.json({choices:[{message:{content:"OpenMindAI test document"}}]});
        if (url.endsWith("/begin")) return Response.json({id:14,status:"running"},{status:202});
        if (url.endsWith("/complete")) {
          assert.match(Buffer.from(JSON.parse(init.body).data,"base64").toString("hex"), /^504b/);
          return Response.json({id:14,status:"complete"});
        }
        throw Error("Unexpected request");
      }
    });
  assert.equal(response.status,201);
  const inference=calls.find(call=>call.url.endsWith("/chat/provider"));
  assert.equal(inference.init.headers.Authorization,"Bearer signed-in-user");
  assert.equal(inference.init.headers["X-Chat-Bridge-Key"],"z".repeat(32));
  assert.ok(calls.every(call=>call.url.startsWith("https://backend/api/v1/")));
});
