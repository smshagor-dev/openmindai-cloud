import test from "node:test";
import assert from "node:assert/strict";
import {generateHostedImage} from "../src/lib/hosted-image.ts";

const backend = "https://cloud.example.test/api/v1";
const token = "private-account-token";
const prompt = "Draw a watercolor bridge";
const run = (fetcher, input = {prompt}, waitForCompletion = false) =>
  generateHostedImage(JSON.stringify(input), token, backend, new AbortController().signal,
    {fetcher, waitForCompletion});

test("image creation only reaches Laravel; no NVIDIA network access or frontend key", async () => {
  const calls = [];
  const fetcher = async (url, request) => {
    calls.push({url,request,body:JSON.parse(request.body)});
    return Response.json({id:41,ids:[41,42],status:"queued",kind:"image"}, {status:202});
  };
  const result = await run(fetcher,{prompt,count:2,aspect_ratio:"16:9",seed:100});
  assert.equal(result.status,202);
  assert.deepEqual((await result.json()).ids,[41,42]);
  assert.equal(calls.length,1);
  assert.equal(calls[0].url,backend + "/media/cloud-image");
  assert.equal(calls[0].request.headers.Authorization,"Bearer " + token);
  assert.equal(calls[0].body.count,2);
  assert.equal(calls[0].body.aspect_ratio,"16:9");
  assert.ok(!JSON.stringify(calls).includes("NVIDIA_API_KEY"));
  assert.ok(!JSON.stringify(calls).includes("ai.api.nvidia.com"));
});

test("image editor uploads are forwarded only to authenticated backend", async () => {
  const input={prompt:"Change the sky to blue",mode:"edit",image:"iVBORw0KGgo=",count:1};
  const fetcher=async(url,request)=>{
    assert.equal(url,backend+"/media/cloud-image");
    assert.deepEqual(JSON.parse(request.body),input);
    assert.equal(request.headers.Authorization,"Bearer "+token);
    return Response.json({id:42,ids:[42],status:"queued"}, {status:202});
  };
  assert.equal((await run(fetcher,input)).status,202);
});

test("backend failures are surfaced without fabricated success", async () => {
  const response=await run(async()=>Response.json({message:"Daily image request limit reached."},{status:429}));
  assert.equal(response.status,429);
  assert.equal((await response.json()).message,"Daily image request limit reached.");
});

test("invalid or oversized prompts do not call Laravel", async () => {
  let calls=0;const fetcher=async()=>{calls++;return Response.json({});};
  assert.equal((await run(fetcher,{prompt:" "})).status,422);
  assert.equal((await run(fetcher,{prompt:"x".repeat(4001)})).status,422);
  assert.equal((await generateHostedImage("invalid",token,backend,new AbortController().signal,{fetcher})).status,400);
  assert.equal(calls,0);
});

test("malformed task response cannot be reported as saved", async () => {
  const result=await run(async()=>Response.json({id:0,status:"complete"},{status:200}));
  assert.equal(result.status,502);
});


test("Auto image calls return a durable queued task without blocking or polling", async () => {
  let calls = 0;
  const response = await run(async () => {
    calls++;
    return Response.json({id:73,ids:[73],status:"queued",kind:"image"}, {status:202});
  }, {prompt}, true);
  assert.equal(response.status, 202);
  assert.deepEqual(await response.json(), {id:73,ids:[73],status:"queued",kind:"image"});
  assert.equal(calls, 1);
});
