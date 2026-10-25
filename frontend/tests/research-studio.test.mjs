import test from "node:test";
import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import { Cite, plugins } from "@citation-js/core";
import {
  citation,
  bibliography,
  parseReferences,
} from "../src/lib/research.ts";
import { researchRoute, researchDownload } from "../src/lib/research-proxy.ts";
test("research proxy allows only scoped implemented methods and paths", () => {
  assert.ok(researchRoute("POST", "research/projects/1/experiments/2/execute"));
  assert.ok(researchDownload("research/projects/1/exports/2/download"));
  for (const path of [
    "research/projects/1/../../admin",
    "research/projects/1/secrets",
    "research/http://localhost",
    "research/projects/1/exports/2/delete",
  ])
    assert.equal(researchRoute("GET", path), false);
  assert.equal(researchRoute("DELETE", "research/projects/1"), false);
});
test("all six bundled CSL styles render actual reference metadata", async () => {
  for (const style of [
    "apa",
    "ieee",
    "acm",
    "vancouver",
    "chicago",
    "harvard",
  ]) {
    plugins.config
      .get("@csl")
      .styles.add(
        style,
        await readFile(
          new URL(`../public/research-csl/${style}.csl`, import.meta.url),
          "utf8",
        ),
      );
    const source = {
      id: 7,
      title: "Synthetic scientific fixture",
      authors: [{ given: "Test", family: "Fixture" }],
      year: 2025,
      doi: "10.1234/fixture",
    };
    assert.match(
      bibliography([source], style),
      /synthetic scientific fixture/i,
    );
    assert.ok(citation(source, style).trim());
  }
});
test("numeric citations retain manuscript order and BibTeX imports preserve DOI", () => {
  const ordered = [
    {
      id: 22,
      title: "Second library item",
      authors: [{ family: "Second" }],
      year: 2025,
    },
    {
      id: 11,
      title: "First library item",
      authors: [{ family: "First" }],
      year: 2024,
    },
  ];
  assert.match(citation(ordered[0], "ieee", ordered), /1/);
  assert.match(citation(ordered[1], "ieee", ordered), /2/);
  assert.match(bibliography(ordered, "ieee"), /Second/);
  const parsed = parseReferences(
    "@article{fixture,title={Synthetic scientific fixture},author={Fixture, Test},year={2025},doi={10.1234/fixture}}",
  );
  assert.equal(parsed[0].DOI, "10.1234/fixture");
});
