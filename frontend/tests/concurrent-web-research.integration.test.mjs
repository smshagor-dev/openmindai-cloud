import test from "node:test";
import assert from "node:assert/strict";
import { researchLoop } from "../src/lib/research-loop.ts";
import {
  activeResearchOperations,
  filterResearchSources,
  sourceForCitation,
  researchAnswerBody,
} from "../src/lib/research-sources.ts";
const signal = () => new AbortController().signal;
const flush = () => new Promise((resolve) => setImmediate(resolve));
const source = (n) => ({
  url: `https://primary.example/paper${n}`,
  title: `Paper ${n}`,
  citation: n,
  read_status: "snippet_only",
});

test("independent searches actually execute concurrently, report real completion order and assign deterministic citations", async () => {
  const pending = new Map(),
    activity = [];
  let state;
  const promise = researchLoop({
    signal: signal(),
    sources: [],
    limits: { actions: 3 },
    progress: (_label, _sources, event) => {
      if (event) activity.push(event);
    },
    checkpoint: async (s) => {
      state = structuredClone(s);
    },
    decide: async () =>
      JSON.stringify({
        action: "search_batch",
        queries: ["angle one", "angle two", "angle three"],
      }),
    tool: async (request) =>
      new Promise((resolve) => pending.set(request.query, resolve)),
  });
  await flush();
  assert.equal(pending.size, 3);
  assert.equal(activeResearchOperations(activity).length, 3);
  pending.get("angle three")({ sources: [source(3)] });
  await flush();
  assert.equal(activeResearchOperations(activity).length, 2);
  pending.get("angle one")({ sources: [source(1)] });
  pending.get("angle two")({ sources: [source(2)] });
  const result = await promise;
  assert.equal(result.stop_reason, "budget");
  assert.equal(result.coverage.actions, 3);
  assert.deepEqual(
    result.sources.map((s) => [s.url, s.citation]),
    [1, 2, 3].map((n) => [source(n).url, n]),
  );
  assert.equal(state.pending_batch.length, 0);
  assert.equal(activeResearchOperations(activity).length, 0);
});
test("a failed batch saves successful siblings and resume only retries the pending search", async () => {
  let state;
  await assert.rejects(
    () =>
      researchLoop({
        sources: [],
        signal: signal(),
        progress() {},
        checkpoint: async (value) => {
          state = structuredClone(value);
        },
        decide: async () =>
          JSON.stringify({
            action: "search_batch",
            queries: ["lost request", "successful request"],
          }),
        tool: async (request) => {
          if (request.query === "lost request")
            throw new Error("Dropped connection");
          return { sources: [source(2)] };
        },
      }),
    /Dropped connection/,
  );
  assert.equal(state.sources.length, 1);
  assert.equal(state.actions.length, 1);
  assert.deepEqual(state.pending_batch, [
    { action: "search", query: "lost request" },
  ]);
  const executed = [];
  const result = await researchLoop({
    sources: [],
    state,
    signal: signal(),
    limits: { actions: 1 },
    progress() {},
    decide: async () => {
      throw Error("Pending request should run first");
    },
    tool: async (request) => {
      executed.push(request);
      return { sources: [source(1)] };
    },
  });
  assert.deepEqual(executed, [{ action: "search", query: "lost request" }]);
  assert.equal(result.sources.length, 2);
  assert.equal(result.coverage.actions, 2);
});
test("page budgets split a batch across resumptions without replaying the already inspected page", async () => {
  const urls = [source(1).url, source(2).url, source(3).url];
  let state;
  const options = {
    sources: urls.map((url, i) => source(i + 1)),
    signal: signal(),
    limits: { pages: 1 },
    progress() {},
    checkpoint: async (value) => {
      state = structuredClone(value);
    },
    tool: async (request) => ({
      read_status: "read",
      page_text:
        "Actual inspected evidence about scientific navigation and experimental measurements.",
      mime: "text/html",
    }),
  };
  const first = await researchLoop({
    ...options,
    decide: async () => JSON.stringify({ action: "read_batch", urls }),
  });
  assert.equal(first.stop_reason, "budget");
  assert.equal(first.coverage.pagesRead, 1);
  assert.equal(state.pending_batch.length, 2);
  const read = [];
  const second = await researchLoop({
    ...options,
    state,
    tool: async (request) => {
      read.push(request.url);
      return options.tool(request);
    },
    decide: async () => {
      throw Error("Saved pending batch must run first");
    },
  });
  assert.deepEqual(read, [urls[1]]);
  assert.equal(second.coverage.pagesRead, 2);
  assert.equal(state.pending_batch.length, 1);
});
test("unsafe or oversized batches are rejected before a tool is called", async () => {
  for (const action of [
    { action: "read_batch", urls: [source(1).url, "http://127.0.0.1/secrets"] },
    { action: "search_batch", queries: ["a", "b", "c", "d"] },
  ]) {
    let calls = 0;
    await assert.rejects(
      () =>
        researchLoop({
          sources: [],
          signal: signal(),
          progress() {},
          decide: async () => JSON.stringify(action),
          tool: async () => {
            calls++;
            return {};
          },
        }),
      /unsupported/,
    );
    assert.equal(calls, 0);
  }
});
test("cancellation preserves completed sibling evidence without reporting a completed investigation", async () => {
  const controller = new AbortController();
  let saved;
  await assert.rejects(() =>
    researchLoop({
      sources: [],
      signal: controller.signal,
      progress() {},
      checkpoint: async (state) => {
        saved = structuredClone(state);
      },
      decide: async () =>
        JSON.stringify({
          action: "search_batch",
          queries: ["available", "cancelled"],
        }),
      tool: async (request) => {
        if (request.query === "cancelled") {
          controller.abort();
          throw new DOMException("Cancelled", "AbortError");
        }
        return { sources: [source(1)] };
      },
    }),
  );
  assert.equal(saved.sources.length, 1);
  assert.equal(saved.actions.length, 1);
  assert.equal(saved.pending_batch[0].query, "cancelled");
});
test("repeated planner actions cannot drain unrelated unread results and receive one bounded correction", async () => {
  const inspected={...source(1),read_status:"read",passages:[{id:"p1",text:"Original evidence supports the question.",page:null}]};
  const request={action:"read",url:source(1).url};
  const contexts=[];
  const result=await researchLoop({
    sources:[],state:{sources:[inspected,source(2)],evidence:[],actions:[JSON.stringify(request)]},
    signal:signal(),progress(){},
    decide:async context=>{contexts.push(JSON.parse(context));return JSON.stringify(request);},
    tool:async()=>{throw Error("An unrelated page must not be fetched to fill a source count");},
  });
  assert.equal(contexts.length,2);
  assert.match(contexts[1].next_step_guidance,/explicit evidence gaps/);
  assert.equal(result.stop_reason,"repeated_action");
  assert.equal(result.complete,false);
  assert.equal(result.sources[1].read_status,"snippet_only");
  assert.ok(result.activity.some(event=>event.status==="failed"&&event.label.includes("Repeated action rejected")));
});

test("citation inspection only opens the exact retrieved numeric citation and never an invented link", () => {
  const sources = [source(1), source(2)];
  assert.equal(sourceForCitation(sources[0].url, ["1"], sources), sources[0]);
  assert.equal(sourceForCitation(sources[1].url, "1", sources), undefined);
  assert.equal(
    sourceForCitation("javascript:alert(1)", "1", sources),
    undefined,
  );
  assert.equal(
    sourceForCitation(sources[0].url, "Read more", sources),
    undefined,
  );
});
test("source search and evidence filters retain Unicode and distinguish partial or unavailable evidence", () => {
  const sources = [
    { ...source(1), title: "বাংলা GPS গবেষণা", read_status: "read" },
    {
      ...source(2),
      title: "Mirror",
      read_status: "read",
      duplicate_of: source(1).url,
    },
    { ...source(3), read_status: "unavailable" },
  ];
  assert.equal(filterResearchSources(sources, "বাংলা GPS", "read").length, 1);
  assert.equal(filterResearchSources(sources, "", "limited").length, 2);
  assert.equal(filterResearchSources(sources, "missing").length, 0);
});
test("the source drawer can replace only the recorded host footer while full export content stays intact", () => {
  const body = "বাংলা report 🛰️ [1](https://primary.example/paper1)",
    full = body + "\n\nSources\nRetrieved index";
  assert.equal(researchAnswerBody(full, body.length, true), body);
  assert.equal(researchAnswerBody(full, body.length, false), full);
  for (const invalid of [-1, Infinity, full.length + 1, "2"])
    assert.equal(researchAnswerBody(full, invalid, true), full);
  assert.ok(full.endsWith("Retrieved index"));
});

test("hosted chat sends concurrent batch requests through the owned bridge and streams source events into a persisted report", async () => {
  const { nvidiaChat } = await import("../src/lib/nvidia-chat.ts");
  let saved,
    step = 0,
    active = 0,
    maximum = 0;
  const question = "বাংলায় GPS navigation research করুন";
  const response = await nvidiaChat(
    JSON.stringify({ message: question, model_id: -1, deep_research: true }),
    "owned-token",
    "https://backend/api/v1",
    signal(),
    {
      config: {
        apiKey: "PRIVATE_PROVIDER",
        bridgeKey: "b".repeat(32),
        model: "fixture",
      },
      fetcher: async (url, options) => {
        const body = JSON.parse(options.body);
        if (url.endsWith("/begin"))
          return Response.json({
            ticket: "a".repeat(64),
            message_id: 7,
            sources: [],
            messages: [
              { role: "system", content: "System" },
              { role: "user", content: question },
            ],
          });
        if (url.endsWith("/checkpoint") || url.endsWith("/status"))
          return Response.json({ stored: true, cancelled: false });
        if (url.endsWith("/finish")) {
          saved = body;
          return Response.json({ stored: true });
        }
        if (url.endsWith("/tool")) {
          assert.equal(options.headers.Authorization, "Bearer owned-token");
          assert.equal(body.question, question);
          active++;
          maximum = Math.max(maximum, active);
          await new Promise((resolve) => setTimeout(resolve, 3));
          active--;
          if (body.action === "search")
            return Response.json({
              sources: [source(body.query === "GPS accuracy" ? 1 : 2)],
            });
          return Response.json({
            read_status: "read",
            mime: "text/html",
            page_text:
              "GPS navigation reached 97.5 percent accuracy in published baseline measurements.",
            passages: [
              {
                id: "p1",
                text: "GPS navigation reached 97.5 percent accuracy in published baseline measurements.",
                page: null,
              },
            ],
          });
        }
        if (body.stream)
          return new Response(
            "data: " +
              JSON.stringify({
                choices: [
                  {
                    delta: {
                      content:
                        "GPS navigation reached 97.5 percent accuracy in published baseline measurements [1].",
                    },
                    finish_reason: "stop",
                  },
                ],
              }) +
              "\n\ndata: [DONE]\n\n",
            { headers: { "Content-Type": "text/event-stream" } },
          );
        const actions = [
          {
            action: "search_batch",
            queries: ["GPS accuracy", "GPS limitations"],
          },
          { action: "read_batch", urls: [source(1).url, source(2).url] },
          { action: "finish", reason: "Primary evidence inspected" },
        ];
        return Response.json({
          choices: [{ message: { content: JSON.stringify(actions[step++]) } }],
        });
      },
    },
  );
  const sse = await response.text();
  assert.equal(maximum, 2);
  assert.equal(saved.status, "complete");
  assert.equal(saved.sources.length, 2);
  assert.equal(saved.research_metadata.mode, "deep_research");
  assert.equal(saved.research_metadata.coverage.pagesRead, 2);
  assert.ok(
    saved.research_metadata.activity.some((event) => event.operation_id),
  );
  assert.ok(saved.research_metadata.answer_body_length < saved.content.length);
  assert.match(sse, /event: research/);
  assert.doesNotMatch(sse, /PRIVATE_PROVIDER|owned-token/);
});
