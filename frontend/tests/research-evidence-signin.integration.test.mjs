import test from "node:test";
import assert from "node:assert/strict";
import { evidenceUrl } from "../src/lib/research-evidence.ts";
import { researchIntent } from "../src/lib/prompt-router.ts";

test("sign-in walls are never accepted as research evidence", () => {
  for (const url of ["https://accounts.google.com/ServiceLogin?continue=x", "https://login.microsoftonline.com/common",
    "https://example.com/login", "https://example.com/en-us/signin/", "https://news.example.com/oauth2/authorize"])
    assert.equal(evidenceUrl(url), null, url);
  for (const url of ["https://www.lazard.com/research-insights/levelized-cost-of-energyplus/", "https://example.com/authors/jane", "https://example.com/blog/login-security-tips"])
    assert.ok(evidenceUrl(url), url);
});

test("current-information prompts are recognised for automatic web search", () => {
  assert.equal(researchIntent("latest news about NVIDIA today").webSearch, true);
  assert.equal(researchIntent("আজকের খবর কী?").webSearch, true);
  assert.equal(researchIntent("In two sentences, what is photosynthesis?").webSearch, undefined);
  assert.equal(researchIntent("latest news, but do not search the web").webSearch, undefined);
});
