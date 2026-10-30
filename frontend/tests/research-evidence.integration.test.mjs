import test from "node:test";
import assert from "node:assert/strict";
import {groundResearchAnswer} from "../src/lib/research-evidence.ts";
test("citation grounding checks the exact number and retrieved URL pair",()=>{
 const sources=[{url:"https://a.example/paper",title:"Paper",citation:1,read_status:"read"},{url:"https://b.example/paper",title:"Other",citation:2,read_status:"snippet_only"}];
 const result=groundResearchAnswer("Supported [1](https://a.example/paper). Mismatch [1](https://b.example/paper). Invented [3](https://fake.example).",sources);
 assert.equal(result.removed,2);assert.match(result.text,/Supported \[1\]/);assert.doesNotMatch(result.text,/https:\/\/fake.example/);
 assert.match(result.footer,/1 pages read/);assert.match(result.footer,/search snippet only/);assert.match(result.footer,/does not establish/);
});
