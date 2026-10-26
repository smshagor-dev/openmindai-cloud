import test from "node:test";
import assert from "node:assert/strict";
import {
  groundResearchAnswer,
  normalizePassageCitations,
  selectPassages,
  duplicateOf,
} from "../src/lib/research-evidence.ts";
import { researchLoop } from "../src/lib/research-loop.ts";
import { compactResearchMetadata } from "../src/lib/research-status.ts";
const source = {
  url: "https://primary.example/paper",
  title: "GPS measurements",
  citation: 1,
  read_status: "read",
  passages: [
    {
      id: "p1",
      text: "GPS navigation reached 97.5 percent accuracy in the published baseline measurements.",
      page: null,
    },
  ],
};

test("live compound passage citations become inspectable source links only for actually retrieved passages", () => {
  const paper={...source,passages:[...source.passages,{id:"p2",text:"A second actual passage.",page:null}]};
  const result=groundResearchAnswer("Evidence [1, p1] and [1, p1-p2]. Unknown [1, p3] [99, p1]. `literal [99, p1]`",[paper]);
  assert.equal(result.removed,2);
  assert.match(result.text,/\[1\]\(https:\/\/primary.example\/paper\) \(passage p1\)/);
  assert.match(result.text,/passages p1–p2/);
  assert.match(result.text,/`literal \[99, p1\]`/);
  assert.equal(normalizePassageCitations("[1, p1]",[{...paper,read_status:"snippet_only"}]).removed,1);
  assert.equal(normalizePassageCitations("[1, p2-p1] [1, p1-p99999]",[paper]).removed,2);
});

test("grounding removes invented named citations but preserves fenced code and normalizes retrieved citations", () => {
  const result = groundResearchAnswer(
    "GPS navigation reached 97.5% accuracy in published measurements [paper](https://primary.example/paper).\n\nFake [paper](https://invented.example/fake).\n\n```md\n[example](https://example.com)\n```",
    [source],
  );
  assert.equal(result.removed, 1);
  assert.match(result.text, /\[1\]\(https:\/\/primary.example/);
  assert.match(result.text, /```md\n\[example\]/);
  assert.equal(result.audit[0].status, "passage_match");
});
test("numeric checks retain decimal percentages and flag contradictory English/Bengali claims", () => {
  for (const text of [
    "GPS navigation reached 98.5 percent accuracy in published baseline measurements [1].",
    "GPS navigation published baseline measurements এ ৯৮.৫ শতাংশ accuracy পাওয়া গেছে [1].",
  ]) {
    const result = groundResearchAnswer(text, [source]);
    assert.equal(result.audit[0].status, "numeric_mismatch");
  }
});
test("passage selection finds evidence late in long documents without inventing PDF pages", () => {
  const text =
    "Background unrelated introduction with ordinary general material. ".repeat(
      1000,
    ) +
    "GPS navigation reached 97.5 percent accuracy in the published baseline measurements.";
  const selected = selectPassages(text, "GPS navigation accuracy");
  assert.ok(selected.some((p) => p.text.includes("97.5")));
  assert.ok(selected.every((p) => p.page === null));
});
test("syndicated passages are flagged rather than counted as independent corroboration", () => {
  const text =
    "The study measured navigation accuracy across multiple environments using an independently collected scientific dataset and compared several algorithms with a baseline under the same experimental conditions to estimate uncertainty.";
  const one = { ...source, passages: [{ id: "p1", text, page: null }] };
  assert.equal(
    duplicateOf({ ...one, url: "https://mirror.example/copy" }, [one]),
    one.url,
  );
  assert.equal(
    duplicateOf(
      { ...one, passages: [{ id: "p1", text: "Different", page: null }] },
      [one],
    ),
    null,
  );
});
test("planner objectives require inspected source IDs; snippets and unknown references cannot close gaps", async () => {
  const actions = [
    {
      action: "plan",
      objectives: [null, { text: "বাংলায় GPS গবেষণার ফলাফল ব্যাখ্যা" }],
    },
    { action: "read", url: source.url },
    {
      action: "finish",
      reason: "Some gaps remain",
      coverage: [{ id: "o1", status: "supported", source_ids: [99] }],
    },
  ];
  const result = await researchLoop({
    sources: [],
    signal: new AbortController().signal,
    progress() {},
    decide: async () => JSON.stringify(actions.shift()),
    tool: async () => ({
      page_text: source.passages[0].text,
      passages: source.passages,
      read_status: "read",
      mime: "text/html",
    }),
  });
  assert.equal(result.complete, false);
  assert.equal(result.plan[0].status, "unresolved");
  assert.equal(result.stop_reason, "planner_finished");
  assert.ok(
    result.activity.some(
      (e) => e.phase === "reading" && e.status === "completed",
    ),
  );
});
test("budget checkpoints resume with a fresh bounded burst and retain the same research objectives", async () => {
  let state;
  const first = await researchLoop({
    sources: [],
    signal: new AbortController().signal,
    limits: { actions: 1 },
    progress() {},
    checkpoint: async (s) => {
      state = structuredClone(s);
    },
    decide: async () =>
      JSON.stringify({ action: "search", query: "GPS measurements" }),
    tool: async () => ({ sources: [source] }),
  });
  assert.equal(first.stop_reason, "budget");
  assert.equal(first.complete, false);
  const actions = [
    { action: "read", url: source.url },
    { action: "finish", reason: "Inspected primary evidence" },
  ];
  const second = await researchLoop({
    sources: [],
    state,
    signal: new AbortController().signal,
    limits: { actions: 2 },
    progress() {},
    decide: async () => JSON.stringify(actions.shift()),
    tool: async () => ({
      page_text: source.passages[0].text,
      passages: [{ ...source.passages[0], page: 42 }],
      mime: "text/html",
      read_status: "read",
    }),
  });
  assert.equal(second.stop_reason, "planner_finished");
  assert.equal(second.complete, true);
  assert.equal(second.coverage.actions, 2);
  assert.equal(second.sources[0].passages[0].page, null);
});
test("public activity checkpoints stay within the backend metadata limit for multilingual runs", () => {
  const value = compactResearchMetadata({
    version: 1,
    status: "running",
    plan: [],
    activity: Array.from({ length: 120 }, (_, i) => ({
      id: i,
      at: new Date().toISOString(),
      phase: "searching",
      status: "completed",
      label: "বাংলা".repeat(320),
      query: "বাংলা".repeat(500),
      url: "https://example.org/" + "a".repeat(1900),
    })),
    audit: Array.from({ length: 100 }, () => ({
      text: "বাংলা".repeat(100),
      citations: [1],
      status: "needs_review",
      passages: [],
    })),
  });
  assert.ok(Buffer.byteLength(JSON.stringify(value)) < 200000);
});

test("hosted research pauses without writing a report and resumes the saved evidence on the same turn", async () => {
  const { nvidiaChat } = await import("../src/lib/nvidia-chat.ts");
  let checkpoint,
    saved,
    plans = 0,
    streams = 0;
  const run = async (resume = false) => {
    const result = await nvidiaChat(
      JSON.stringify(
        resume
          ? { resume_message_id: 7 }
          : {
              message: "Investigate GPS accuracy",
              deep_research: true,
              model_id: -1,
            },
      ),
      "user",
      "https://backend/api/v1",
      new AbortController().signal,
      {
        config: {
          apiKey: "private",
          bridgeKey: "b".repeat(32),
          model: "fixture",
        },
        fetcher: async (endpoint, options) => {
          const body = JSON.parse(options.body);
          if (endpoint.endsWith("/begin") || endpoint.endsWith("/resume"))
            return Response.json({
              ticket: "a".repeat(64),
              message_id: 7,
              sources: resume ? checkpoint.sources : [],
              messages: [
                { role: "system", content: "System" },
                { role: "user", content: "Investigate GPS accuracy" },
              ],
              deep_research: true,
              ...(resume ? { checkpoint } : {}),
            });
          if (endpoint.endsWith("/checkpoint")) {
            checkpoint = structuredClone(body.state);
            return Response.json({ stored: true });
          }
          if (endpoint.endsWith("/finish")) {
            saved = body;
            return Response.json({ stored: true });
          }
          if (endpoint.endsWith("/tool"))
            return Response.json(
              body.action === "read"
                ? {
                    read_status: "read",
                    page_text: source.passages[0].text,
                    passages: source.passages,
                    mime: "text/html",
                  }
                : { sources: [{ ...source, url: source.url + "?q=" + plans }] },
            );
          if (endpoint.endsWith("/status"))
            return Response.json({ cancelled: false });
          if (body.stream) {
            streams++;
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
          }
          const decision = !resume
            ? { action: "search", query: "GPS accuracy " + ++plans }
            : checkpoint.research.sources.some((s) => s.read_status === "read")
              ? { action: "finish", reason: "Read evidence available" }
              : { action: "read", url: checkpoint.research.sources[0].url };
          return Response.json({
            choices: [{ message: { content: JSON.stringify(decision) } }],
          });
        },
      },
    );
    return result.text();
  };
  const paused = await run();
  assert.equal(streams, 0);
  assert.equal(saved.status, "failed");
  assert.equal(saved.research_metadata.status, "paused");
  assert.equal(checkpoint.stage, "research");
  assert.match(paused, /"resumable":true/);
  assert.doesNotMatch(paused, /event: error/);
  const continued = await run(true);
  assert.equal(streams, 1);
  assert.equal(saved.status, "complete");
  assert.equal(saved.research_metadata.status, "complete");
  assert.match(saved.content, /97.5/);
  assert.match(continued, /"saved":true/);
});

test("research without retrievable sources never promotes model-generated links to evidence", () => {
  const result = groundResearchAnswer(
    "A claimed result [study](https://invented.example/fake).",
    [],
  );
  assert.equal(result.removed, 1);
  assert.doesNotMatch(result.text, /invented.example/);
  assert.match(result.footer, /0 sources/);
});
