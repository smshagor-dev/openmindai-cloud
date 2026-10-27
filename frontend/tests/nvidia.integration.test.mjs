import test from "node:test";
import assert from "node:assert/strict";
import { nvidiaChat } from "../src/lib/nvidia-chat.ts";
const config = {
  apiKey: "secret-nvidia-key",
  bridgeKey: "k".repeat(32),
  model: "nvidia/nemotron-3.5-lightning-30b-a3b",
};
function fixture({
  upstreamStatus = 200,
  truncated = false,
  saveFails = false,
  automaticMemory = false,
  providerUrl = "https://integrate.api.nvidia.com/v1/chat/completions",
  providerAuthorization = "Bearer secret-nvidia-key",
} = {}) {
  const calls = [];
  const fetcher = async (url, options) => {
    calls.push({ url, options, body: JSON.parse(options.body) });
    if (url.endsWith("/begin"))
      return Response.json({
        ticket: "a".repeat(64),
        messages: [
          { role: "system", content: "PRIVATE CONTEXT" },
          { role: "user", content: "Hi" },
        ],
        conversation_id: 1,
        user_message_id: 2,
        message_id: 3,
        sources: [],
        automatic_memory: automaticMemory,
      });
    if (url.endsWith("/finish"))
      return Response.json(
        { stored: !saveFails },
        { status: saveFails ? 503 : 200 },
      );
    if (url.endsWith("/checkpoint")) return Response.json({ stored: true });
    if (url.endsWith("/status")) return Response.json({ cancelled: false });
    assert.equal(url, providerUrl);
    assert.equal(options.headers.Authorization, providerAuthorization);
    if (JSON.parse(options.body).stream === false)
      return Response.json({
        choices: [
          {
            message: {
              content: JSON.stringify({
                action: "finish",
                reason: "Evidence sufficient for this fixture",
              }),
            },
          },
        ],
      });
    return new Response(
      'data: {"choices":[{"delta":{"reasoning_content":"hidden reasoning"}}]}\n\ndata: {"choices":[{"delta":{"content":"Hello"},"finish_reason":"stop"}]}\n\n' +
        (truncated ? "" : "data: [DONE]\n\n"),
      {
        status: upstreamStatus,
        headers: { "Content-Type": "text/event-stream" },
      },
    );
  };
  return { calls, fetcher };
}
test("production-mode hosted chat sends inference from Next.js and saves through Laravel", async () => {
  const previousKey = process.env.NVIDIA_API_KEY;
  const previousBridge = process.env.FRONTEND_CHAT_BRIDGE_KEY;
  try {
    process.env.NVIDIA_API_KEY = "secret-nvidia-key";
    process.env.FRONTEND_CHAT_BRIDGE_KEY = "k".repeat(32);
    const f = fixture();
    const fetcher = (url, options) => {
      if (url.endsWith("/public-config")) return Promise.resolve(Response.json({frontend_inference_route: "direct"}));
      if (url.endsWith("/chat/hosted-models"))
        return Promise.resolve(Response.json({ data: [{ id: "openmindai-lite" }] }));
      return f.fetcher(url, options);
    };
    const result = await nvidiaChat(
      JSON.stringify({ message: "Hi", model_id: -1 }),
      "user-token",
      "https://backend/api/v1",
      new AbortController().signal,
      { fetcher }, // No injected config: use the real server environment.
    );
    const body = await result.text();
    assert.match(body, /Hello/);
    const inference = f.calls.find(x => x.url === "https://integrate.api.nvidia.com/v1/chat/completions");
    assert.ok(inference);
    assert.equal(inference.options.headers.Authorization, "Bearer secret-nvidia-key");
    assert.equal(f.calls.filter(x => x.url.endsWith("/finish")).length, 1);
    assert.equal(f.calls.filter(x => x.url.includes("/provider/invoke")).length, 0);
  } finally {
    if (previousKey === undefined) delete process.env.NVIDIA_API_KEY;
    else process.env.NVIDIA_API_KEY = previousKey;
    if (previousBridge === undefined) delete process.env.FRONTEND_CHAT_BRIDGE_KEY;
    else process.env.FRONTEND_CHAT_BRIDGE_KEY = previousBridge;
  }
});

test("Next server connects directly to NVIDIA and persists only final answer to backend", async () => {
  const f = fixture();
  const r = await nvidiaChat(
    JSON.stringify({
      message: "Hi",
      model_id: -1,
      deep_research: true,
      search_time_range: "week",
    }),
    "user-token",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  const text = await r.text();
  assert.match(text, /event: delta/);
  assert.match(text, /Hello/);
  assert.match(text, /"saved":true/);
  for (const secret of [
    "secret-nvidia-key",
    "user-token",
    "PRIVATE CONTEXT",
    "hidden reasoning",
    "a".repeat(64),
  ])
    assert.ok(!text.includes(secret));
  const begin = f.calls.find((x) => x.url.endsWith("/begin"));
  assert.equal(begin.body.deep_research, true);
  assert.equal(begin.body.search_time_range, "week");
  const provider = f.calls.find(
    (x) => x.url.includes("nvidia.com") && x.body.stream,
  );
  assert.ok(provider.body.messages[0].content.includes("PRIVATE CONTEXT"));
  const done = f.calls.find((x) => x.url.endsWith("/finish"));
  assert.ok(done.body.content.startsWith("Hello\n\nSources"));
  assert.match(done.body.content,/Evidence coverage: 0 sources/);
  assert.equal(done.body.research_metadata.status,"partial");
  assert.equal(done.body.status, "complete");
  assert.equal(done.options.headers.Authorization, "Bearer user-token");
  assert.equal(done.options.headers["X-Chat-Bridge-Key"], config.bridgeKey);
  assert.equal(
    f.calls.find((x) => x.url.includes("nvidia.com") && x.body.stream).body
      .stream,
    true,
  );
});
test("research coordinator bounds provider output and limits thinking controls to verified models", async () => {
  for (const [model_id, thinking] of [[-3, false], [-1, false], [-2, undefined]]) {
    const f = fixture();
    const response = await nvidiaChat(
      JSON.stringify({message: "Investigate evidence", deep_research: true, model_id}),
      "user-token", "https://backend/api/v1", new AbortController().signal,
      {...f, config},
    );
    await response.text();
    const planning = f.calls.filter(call => call.url.includes("nvidia.com") && call.body.stream === false);
    assert.ok(planning.length > 0);
    for (const call of planning) {
      assert.equal(call.body.max_tokens, 2048);
      assert.equal(call.body.chat_template_kwargs?.enable_thinking, thinking);
    }
  }
});

test("temporary planner errors retry once with the same deadline and retain an auditable failure when both attempts fail", async () => {
  for (const failures of [1, 2]) {
    const f = fixture();
    let attempts = 0;
    const signals = [];
    const response = await nvidiaChat(
      JSON.stringify({message:"Investigate evidence",deep_research:true,model_id:-3}),
      "user-token","https://backend/api/v1",new AbortController().signal,
      {config,fetcher:async(url,init)=>{
        if(url.includes("nvidia.com") && JSON.parse(init.body).stream===false){
          attempts++;signals.push(init.signal);
          if(attempts<=failures) return new Response("unavailable",{status:503});
        }
        return f.fetcher(url,init);
      }},
    );
    await response.text();
    assert.equal(attempts,2);
    assert.equal(signals[0],signals[1]);
    const saved=f.calls.find(call=>call.url.endsWith("/finish")).body;
    assert.equal(saved.status,failures===1?"complete":"failed");
    assert.ok(saved.research_metadata.activity.some(event=>event.status==="failed"&&event.label.includes("retrying once")));
    assert.ok(f.calls.some(call=>call.url.endsWith("/checkpoint")&&call.body.state.research?.activity?.some(event=>event.label.includes("retrying once"))));
  }
});

test("truncated provider response saves partial answer with failed status", async () => {
  const f = fixture({ truncated: true });
  const r = await nvidiaChat(
    '{"message":"Hi","model_id":-1}',
    "u",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  const text = await r.text();
  assert.match(text, /event: error/);
  const done = f.calls.find((x) => x.url.endsWith("/finish"));
  assert.equal(done.body.content, "Hello");
  assert.equal(done.body.status, "failed");
});
test("provider throttling is recorded as failed without leaking provider body", async () => {
  const f = fixture({ upstreamStatus: 429 });
  const r = await nvidiaChat(
    '{"message":"Hi","model_id":-1}',
    "u",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  assert.match(await r.text(), /NVIDIA rate limit/);
  assert.equal(
    f.calls.find((x) => x.url.endsWith("/finish")).body.status,
    "failed",
  );
});
test("retired model reports an actionable error and saves the failed turn", async () => {
  const f = fixture({ upstreamStatus: 410 });
  const fixtureFetch = f.fetcher;
  f.fetcher = async (url, options) => {
    const response = await fixtureFetch(url, options);
    return url.includes("nvidia.com")
      ? Response.json({ detail: "PRIVATE PROVIDER DETAIL" }, { status: 410 })
      : response;
  };
  const r = await nvidiaChat(
    '{"message":"Hi","model_id":-1}',
    "u",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  const text = await r.text();
  assert.match(text, /model has been retired/);
  assert.match(text, /NVIDIA_MODEL/);
  assert.doesNotMatch(text, /PRIVATE PROVIDER DETAIL|secret-nvidia-key/);
  assert.equal(
    f.calls.find((x) => x.url.endsWith("/finish")).body.status,
    "failed",
  );
});

test("persistence errors retry and never report successful save", async () => {
  const f = fixture({ saveFails: true });
  const r = await nvidiaChat(
    '{"message":"Hi","model_id":-1}',
    "u",
    "https://backend/api/v1",
    new AbortController().signal,
    { ...f, config },
  );
  const text = await r.text();
  assert.match(text, /"saved":false/);
  assert.match(text, /could not be saved/);
  assert.equal(f.calls.filter((x) => x.url.endsWith("/finish")).length, 3);
});
test("missing key fails before any backend/provider request", async () => {
  let called = false;
  const r = await nvidiaChat(
    "{}",
    "u",
    "https://backend",
    new AbortController().signal,
    {
      config: { ...config, apiKey: "" },
      fetcher: async () => {
        called = true;
        throw Error();
      },
    },
  );
  assert.equal(r.status, 503);
  assert.equal(called, false);
});
test("cancelled browser request still finalizes backend lease", async () => {
  const f = fixture();
  const c = new AbortController();
  c.abort();
  const r = await nvidiaChat("{}", "u", "https://backend/api/v1", c.signal, {
    ...f,
    config,
  });
  await r.text();
  assert.equal(
    f.calls.find((x) => x.url.endsWith("/finish")).body.status,
    "cancelled",
  );
  assert.equal(f.calls.filter((x) => x.url.includes("nvidia.com")).length, 0);
});

test("opted-in NVIDIA memory is extracted from user text only after chat persistence", async () => {
  const f = fixture({ automaticMemory: true });
  let memoriesSaved = false;
  const fetcher = async (url, options) => {
    const body = JSON.parse(options.body);
    if (url.includes("nvidia.com") && body.stream === false) {
      assert.ok(f.calls.some((call) => call.url.endsWith("/finish")));
      assert.equal(body.messages[1].content, "I prefer Bangla");
      assert.ok(!JSON.stringify(body).includes("PRIVATE CONTEXT"));
      return Response.json({
        choices: [{ message: { content: '["Prefers Bangla answers"]' } }],
      });
    }
    if (url.endsWith("/memory")) {
      memoriesSaved = true;
      assert.deepEqual(body.memories, ["Prefers Bangla answers"]);
      return Response.json({ saved: 1 });
    }
    return f.fetcher(url, options);
  };
  const result = await nvidiaChat(
    '{"message":"I prefer Bangla","model_id":-1}',
    "user-token",
    "https://backend/api/v1",
    new AbortController().signal,
    { fetcher, config },
  );
  const text = await result.text();
  assert.equal(memoriesSaved, true);
  assert.match(text, /event: memory/);
  assert.match(text, /"saved":true/);
});

test("interrupted NVIDIA answer resumes durable context without creating another turn", async () => {
  const first = fixture({ truncated: true });
  let checkpoint;
  const fetchFirst = async (url, options) => {
    if (url.endsWith("/checkpoint")) {
      checkpoint = JSON.parse(options.body).state;
      return Response.json({ stored: true });
    }
    return first.fetcher(url, options);
  };
  await (
    await nvidiaChat(
      '{"message":"Hi","model_id":-1}',
      "token",
      "https://backend/api/v1",
      new AbortController().signal,
      { config, fetcher: fetchFirst },
    )
  ).text();
  assert.equal(checkpoint.content, "Hello");
  assert.equal(checkpoint.messages.at(-2).content, "Hello");
  let saved;
  const response = await nvidiaChat(
    '{"resume_message_id":3}',
    "token",
    "https://backend/api/v1",
    new AbortController().signal,
    {
      config,
      fetcher: async (url, options) => {
        const body = JSON.parse(options.body);
        if (url.endsWith("/resume")) {
          assert.equal(body.message_id, 3);
          return Response.json({
            ticket: "b".repeat(64),
            message_id: 3,
            user_message_id: 2,
            conversation_id: 1,
            sources: [],
            messages: [{ role: "system", content: "PRIVATE CONTEXT" }],
            checkpoint,
          });
        }
        if (url.endsWith("/checkpoint")) return Response.json({ stored: true });
        if (url.endsWith("/finish")) {
          saved = body;
          return Response.json({ stored: true });
        }
        assert.equal(
          url,
          "https://integrate.api.nvidia.com/v1/chat/completions",
        );
        assert.equal(body.messages.at(-2).content, "Hello");
        return new Response(
          'data: {"choices":[{"delta":{"content":" world"},"finish_reason":"stop"}]}\n\ndata: [DONE]\n\n',
          { headers: { "Content-Type": "text/event-stream" } },
        );
      },
    },
  );
  const output = await response.text();
  assert.equal(saved.content, "Hello world");
  assert.equal(saved.status, "complete");
  assert.match(output, /"content":"Hello"/);
  assert.ok(!output.includes("PRIVATE CONTEXT"));
});

test("backend routing uses Laravel credentials and never requires a frontend provider key", async () => {
  const oldKey = process.env.NVIDIA_API_KEY;
  const oldBridge = process.env.FRONTEND_CHAT_BRIDGE_KEY;
  try {
    delete process.env.NVIDIA_API_KEY;
    process.env.FRONTEND_CHAT_BRIDGE_KEY = "k".repeat(32);
    const f = fixture({providerUrl: "https://backend/api/v1/chat/provider", providerAuthorization: "Bearer user-token"});
    const result = await nvidiaChat(JSON.stringify({message: "Hi", model_id: -1}), "user-token", "https://backend/api/v1", new AbortController().signal, {
      fetcher: (url, options) => {
        if (url.endsWith("/public-config")) return Promise.resolve(Response.json({frontend_inference_route: "backend"}));
        if (url.endsWith("/chat/hosted-models")) return Promise.resolve(Response.json({data: [{id: "openmindai-lite"}]}));
        return f.fetcher(url, options);
      },
    });
    const output = await result.text();
    assert.match(output, /Hello/);
    assert.match(output, /"saved":true/);
    assert.equal(f.calls.some(call => call.url.includes("nvidia.com")), false);
    const inference = f.calls.find(call => call.url.endsWith("/chat/provider"));
    assert.equal(inference.options.headers["X-Chat-Bridge-Key"], "k".repeat(32));
    assert.equal(f.calls.filter(call => call.url.endsWith("/finish")).length, 1);
  } finally {
    if (oldKey === undefined) delete process.env.NVIDIA_API_KEY; else process.env.NVIDIA_API_KEY = oldKey;
    if (oldBridge === undefined) delete process.env.FRONTEND_CHAT_BRIDGE_KEY; else process.env.FRONTEND_CHAT_BRIDGE_KEY = oldBridge;
  }
});

test("blank backend bridge rejection has an actionable error", async () => {
  const response = await nvidiaChat(JSON.stringify({model_id: -1, message: "Hello"}), "token", "https://backend.test/api/v1", new AbortController().signal, {
    config, fetcher: async () => Response.json({message: ""}, {status: 403}),
  });
  assert.equal(response.status, 403);
  assert.match((await response.json()).message, /FRONTEND_CHAT_BRIDGE_KEY/);
});

test("long code responses have an explicit budget and continue after a token boundary", async () => {
  const baseFixture = fixture();
  let streams = 0;
  const fetcher = async (url, options) => {
    if (url === "https://integrate.api.nvidia.com/v1/chat/completions" && JSON.parse(options.body).stream) {
      const input = JSON.parse(options.body);
      assert.equal(input.max_tokens, 16384);
      streams++;
      if (streams === 2) assert.match(input.messages.at(-1).content, /Continue exactly/);
      const text = streams === 1 ? "```html\n<html><body>" : "Bottle design</body></html>\n```";
      return new Response(`data: ${JSON.stringify({choices: [{delta: {content: text}, finish_reason: streams === 1 ? "length" : "stop"}]})}\n\ndata: [DONE]\n\n`, {headers: {"Content-Type": "text/event-stream"}});
    }
    return baseFixture.fetcher(url, options);
  };
  const response = await nvidiaChat(JSON.stringify({model_id: -1, message: "make a html css design for a water bottle company with 3d design"}), "token", "https://backend.test/api/v1", new AbortController().signal, {config, fetcher, pollMs: 100000});
  const body = await response.text();
  assert.equal(streams, 2);
  assert.match(body, /Bottle design<\/body><\/html>/);
  assert.match(body, /"status":"complete"/);
});

test("a catalog model selected in the picker reaches backend inference with its exact provider ID", async () => {
  const { hostedCatalog } = await import("../src/lib/nvidia-chat.ts");
  const previousBridge = process.env.FRONTEND_CHAT_BRIDGE_KEY;
  const rows = [{id: "openmindai-flash", name: "Flash", provider_model: "deepseek-ai/flash"}];
  const selected = hostedCatalog(rows)[0];
  process.env.FRONTEND_CHAT_BRIDGE_KEY = "k".repeat(32);
  try {
    const f = fixture({providerUrl: "https://backend/api/v1/chat/provider", providerAuthorization: "Bearer user-token"});
    const fetcher = async (url, options) => {
      if (url.endsWith("/public-config")) return Response.json({frontend_inference_route: "backend"});
      if (url.endsWith("/chat/hosted-models")) return Response.json({data: rows});
      return f.fetcher(url, options);
    };
    const response = await nvidiaChat(JSON.stringify({message: "Hi", model_id: selected.id}), "user-token",
      "https://backend/api/v1", new AbortController().signal, {fetcher});
    assert.match(await response.text(), /Hello/);
    const request = f.calls.find(call => call.url.endsWith("/chat/provider"));
    assert.equal(request.body.model, rows[0].provider_model);
    assert.equal(f.calls.some(call => call.url.includes("nvidia.com")), false);
    const rejected = await nvidiaChat(JSON.stringify({message: "Hi", model_id: selected.id - 1}), "user-token",
      "https://backend/api/v1", new AbortController().signal, {fetcher});
    assert.equal(rejected.status, 404);
  } finally {
    if (previousBridge === undefined) delete process.env.FRONTEND_CHAT_BRIDGE_KEY;
    else process.env.FRONTEND_CHAT_BRIDGE_KEY = previousBridge;
  }
});

test("missing model selection defaults to Auto, while explicit choices suppress automatic tools", async () => {
  const previous = process.env.FRONTEND_CHAT_BRIDGE_KEY;
  process.env.FRONTEND_CHAT_BRIDGE_KEY = "k".repeat(32);
  const rows = [{id:"openmindai-lite"}, {id:"openmindai-ultra"}, {id:"nvidia-laguna-xs-2.1",name:"Code",provider_model:"provider/code"}];
  try {
    for (const scenario of [
      {message:"Debug my app", expected:"nvidia/nemotron-3.5-lightning-30b-a3b", tools:true},
      {message:"latest news", expected:"nvidia/nemotron-3.5-lightning-30b-a3b", tools:true},
      {message:"deep research on UAV security", expected:"nvidia/nemotron-3.5-lightning-30b-a3b", tools:true},
      {message:"Explain photo composition", model_id:-1, expected:config.model},
    ]) {
      const f = fixture({providerUrl:"https://backend/api/v1/chat/provider", providerAuthorization:"Bearer user-token"});
      const fetcher = async (url, init) => {
        if (url.endsWith("/public-config")) return Response.json({frontend_inference_route:"backend"});
        if (url.endsWith("/chat/hosted-models")) return Response.json({data:rows});
        return f.fetcher(url, init);
      };
      const result = await nvidiaChat(JSON.stringify(scenario), "user-token", "https://backend/api/v1", new AbortController().signal, {fetcher});
      assert.match(await result.text(), /Hello/);
      const generation = f.calls.find(call => call.url.endsWith("/chat/provider"));
      assert.equal(generation.body.model, scenario.expected);
      const begin = f.calls.find(call => call.url.endsWith("/begin"));
      assert.equal(Boolean(begin.body.conversation_tools), Boolean(scenario.tools));
      assert.equal(Boolean(generation.body.tools), Boolean(scenario.tools));
      assert.equal(f.calls.some(call => call.url.includes("nvidia.com")), false);
    }
  } finally {
    if (previous === undefined) delete process.env.FRONTEND_CHAT_BRIDGE_KEY;
    else process.env.FRONTEND_CHAT_BRIDGE_KEY = previous;
  }
});


test("a clear image request executes once without relying on a text model and persists its queued task", async () => {
  const previous = process.env.FRONTEND_CHAT_BRIDGE_KEY;
  process.env.FRONTEND_CHAT_BRIDGE_KEY = "k".repeat(32);
  const f = fixture();
  let streams = 0;
  const generation = [];
  const fetcher = async (url, init) => {
    if (url.endsWith("/public-config")) return Response.json({frontend_inference_route:"backend"});
    if (url.endsWith("/chat/hosted-models")) return Response.json({data:[{id:"openmindai-ultra"}]});
    if (url.endsWith("/media/cloud-image")) return Response.json({id:74,ids:[74],status:"queued",kind:"image"},{status:202});
    if (url.endsWith("/chat/provider")) {
      generation.push(JSON.parse(init.body));
      const delta = streams++ === 0
        ? {tool_calls:[{index:0,id:"image-task",type:"function",function:{name:"create_artifact",arguments:JSON.stringify({kind:"image",prompt:"A friendly dog"})}}]}
        : {content:"Your image request is queued and will update when ready."};
      const reason = streams === 1 ? "tool_calls" : "stop";
      return new Response(`data: ${JSON.stringify({choices:[{delta,finish_reason:reason}]})}\n\ndata: [DONE]\n\n`,{headers:{"Content-Type":"text/event-stream"}});
    }
    return f.fetcher(url,init);
  };
  try {
    const response = await nvidiaChat(JSON.stringify({message:"Generate a dog image"}),"token","https://backend/api/v1",new AbortController().signal,{fetcher,pollMs:100000});
    const body = await response.text();
    assert.match(body,/event: artifact/);
    assert.match(body,/"id":74/);
    assert.equal(streams,0);
    assert.match(body,/request was accepted/);
    assert.doesNotMatch(body,/Created\. Preview/);
    const finish = f.calls.find(call => call.url.endsWith("/finish"));
    assert.match(finish.body.content, /\[View generated image\]\(\/api\/backend\/media\/74\/output\)/);
    const checkpoint = f.calls.find(call => call.url.endsWith("/checkpoint") && call.body.state.content?.includes("/media/74/output"));
    assert.ok(checkpoint, "accepted task is checkpointed before returning its ID");
  } finally {
    if (previous === undefined) delete process.env.FRONTEND_CHAT_BRIDGE_KEY;
    else process.env.FRONTEND_CHAT_BRIDGE_KEY = previous;
  }
});

test("manual tools and typed generation work with an explicitly selected text model without a refusal or duplicate request", async () => {
  for (const scenario of [
    {message:"একটি সুন্দর ছবি বানিয়ে দাও",model_id:-3},
    {message:"A lake at sunrise",model_id:-1,generation_kind:"image"},
  ]) {
    const f = fixture(); let created = 0;
    const response = await nvidiaChat(JSON.stringify(scenario),"user-token","https://backend/api/v1",new AbortController().signal,{
      config,pollMs:100000,fetcher:async(url,init) => {
        if (url.endsWith("/media/cloud-image")) {created++;assert.equal(init.headers.Authorization,"Bearer user-token");return Response.json({id:91,ids:[91],status:"queued"},{status:202});}
        return f.fetcher(url,init);
      },
    });
    const stream = await response.text();
    assert.equal(created,1);
    assert.match(stream,/event: artifact/);
    assert.doesNotMatch(stream,/Sorry|cannot generate|Created\. Preview/);
    assert.match(f.calls.find(call=>call.url.endsWith("/finish")).body.content,/\/media\/91\/output/);
    assert.equal(f.calls.some(call=>call.url.includes("nvidia.com")),false);
  }
});

test("document creation in chat renders real PDF and DOCX bytes and persists its preview link", async () => {
  for (const format of ["pdf","docx"]) {
    const f=fixture(); let output;
    const response=await nvidiaChat(JSON.stringify({model_id:-3,message:`Create a ${format} document about trees`,generation_kind:"document_create"}),"token","https://backend/api/v1",new AbortController().signal,{
      config,pollMs:100000,fetcher:async(url,init)=>{
        if(url.endsWith("/media/hosted/task/begin"))return Response.json({id:92});
        if(url.endsWith("/chat/completions")){assert.equal(JSON.parse(init.body).model,"nvidia/nemotron-3-ultra-550b-a55b");return Response.json({choices:[{message:{content:"Tree report\n\nTrees absorb carbon dioxide."}}]});}
        if(url.endsWith("/media/92/hosted/task/complete")){output=JSON.parse(init.body);return Response.json({id:92,status:"complete"});}
        return f.fetcher(url,init);
      },
    });
    const stream=await response.text();
    assert.ok(output,stream);
    const bytes=Buffer.from(output.data,"base64");
    assert.equal(bytes.subarray(0,format==="pdf"?5:2).toString(),format==="pdf"?"%PDF-":"PK");
    assert.match(f.calls.find(call=>call.url.endsWith("/finish")).body.content,/View generated document.*\/media\/92\/output/);
    assert.match(stream,/Created\. Preview/);
  }
});

test("generation errors never fabricate an artifact and invalid tool selections create no tasks", async () => {
  const f=fixture(); let creates=0;
  const fetcher=async(url,init)=>{
    if(url.endsWith("/media/cloud-image")){creates++;return Response.json({message:"Unavailable"},{status:503});}
    return f.fetcher(url,init);
  };
  const response=await nvidiaChat(JSON.stringify({message:"Generate an image",model_id:-1}),"token","https://backend/api/v1",new AbortController().signal,{config,fetcher,pollMs:100000});
  const stream=await response.text();
  assert.equal(creates,1);assert.match(stream,/event: error/);assert.doesNotMatch(stream,/event: artifact/);
  assert.equal(f.calls.find(call=>call.url.endsWith("/finish")).body.status,"failed");
  const invalid=await nvidiaChat(JSON.stringify({message:"A tree",generation_kind:"http://internal"}),"token","https://backend/api/v1",new AbortController().signal,{config,fetcher});
  assert.equal(invalid.status,422);assert.equal(creates,1);
  const invalidPrompt=await nvidiaChat(JSON.stringify({message:{text:"A tree"},generation_kind:"document_create"}),"token","https://backend/api/v1",new AbortController().signal,{config,fetcher});
  assert.equal(invalidPrompt.status,422);assert.equal(creates,1);
});
