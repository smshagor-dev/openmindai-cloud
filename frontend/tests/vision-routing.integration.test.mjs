import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import {parseInlineImage} from "../src/lib/vision-input.ts";
import {routePrompt} from "../src/lib/prompt-router.ts";
import {nvidiaChat, selectVisionModel} from "../src/lib/nvidia-chat.ts";

// Small test fixture, not a stored user attachment.
const png = "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+j0ZkAAAAASUVORK5CYII=";
const image = {mime:"image/png",data:png};
const vision = "nvidia/nemotron-3.5-super-vl-120b-a12b";
const fast = "nvidia/nemotron-3.5-lightning-30b-a3b";
const cfg = {apiKey:"secret-key",bridgeKey:"k".repeat(32),model:fast};

test("rejects spoofed and oversized images before making any provider requests", async () => {
  assert.equal(parseInlineImage(undefined),undefined);
  assert.equal(parseInlineImage(image)?.mime, "image/png");
  assert.throws(() => parseInlineImage({mime:"image/jpeg",data:png}), /file type/);
  assert.throws(() => parseInlineImage({mime:"image/svg+xml",data:png}), /PNG/);
  assert.throws(() => parseInlineImage({mime:"image/png",data:"!!not-base64"}), /PNG/);
  assert.throws(() => parseInlineImage({mime:"image/png",data:"x".repeat(6_000_000)}), /PNG/);
});

test("any attached image selects vision without interpreting it as a text-to-image request", async () => {
  const result = await routePrompt("Generate another photo",{
    apiKey:"",fastModel:fast,visionModel:vision,
  },{hasImage:true});
  assert.equal(result.kind,"chat");
  assert.equal(result.model,vision);
});

test("direct image chat sends the image only to NVIDIA, not to Laravel or checkpoint storage", async () => {
  const calls = [];
  const fetcher = async (url,init) => {
    const body = JSON.parse(init.body);
    calls.push({url,body});
    if (url.endsWith("/chat/frontend/begin")) return Response.json({
      ticket:"a".repeat(64), messages:[
        {role:"system",content:"private context"},
        {role:"user",content:"What is shown?"},
      ],conversation_id:15,user_message_id:16,message_id:17,sources:[],
    });
    if (url.endsWith("/chat/frontend/checkpoint")) return Response.json({stored:true});
    if (url.endsWith("/chat/frontend/status")) return Response.json({cancelled:false});
    if (url.endsWith("/chat/frontend/finish")) return Response.json({stored:true});
    return new Response(
      'data: {"choices":[{"delta":{"content":"A small image"}}]}\n\n' +
      "data: [DONE]\n\n",
      {headers:{"Content-Type":"text/event-stream"}},
    );
  };
  const response = await nvidiaChat(JSON.stringify({message:"What is shown?",model_id:-1,image}),
    "user-session","https://backend/api/v1",new AbortController().signal,
    {fetcher,config:cfg});
  assert.equal(response.status,200);
  const text = await response.text();
  assert.match(text,/A small image/);
  assert.match(text,/"saved":true/);
  assert.ok(!text.includes(png));
  const begin = calls.find(c => c.url.endsWith("/begin"));
  assert.equal(begin.body.provider_model,vision);
  assert.ok(!JSON.stringify(begin.body).includes(png));
  assert.ok(!("image" in begin.body));
  const upstream = calls.find(c => c.url.includes("nvidia.com"));
  assert.equal(upstream.body.model,vision);
  assert.equal(upstream.body.max_tokens,undefined);
  assert.equal(upstream.body.messages.at(-1).content[0].type,"text");
  assert.equal(upstream.body.messages.at(-1).content[1].image_url.url,"data:image/png;base64," + png);
  const checkpoints = calls.filter(c => c.url.endsWith("/checkpoint"));
  assert.ok(checkpoints.length > 0);
  assert.equal(checkpoints[0].body.state.modelId,vision);
  assert.equal(checkpoints[0].body.state.imageRequired,true);
  assert.ok(checkpoints.every(c => !JSON.stringify(c.body).includes(png)));
  const completed = calls.find(c => c.url.endsWith("/finish"));
  assert.equal(completed.body.provider_model,vision);
  assert.equal(completed.body.status,"complete");
});

test("a failed vision endpoint never falls back to a text-only model", async () => {
  const models = [];
  let finish;
  const fetcher = async (url, init) => {
    if (url.endsWith("/begin")) return Response.json({
      ticket:"b".repeat(64),messages:[{role:"system",content:"System"},{role:"user",content:"Read this"}],
      conversation_id:2,user_message_id:3,message_id:4,sources:[],
    });
    if (url.endsWith("/checkpoint")) return Response.json({stored:true});
    if (url.endsWith("/finish")) { finish=JSON.parse(init.body); return Response.json({stored:true}); }
    if (url.endsWith("/status")) return Response.json({cancelled:false});
    models.push(JSON.parse(init.body).model);
    return Response.json({error:"rate limit"}, {status:429});
  };
  const response = await nvidiaChat(JSON.stringify({message:"Read this",image,model_id:-1}),
    "token","https://backend",new AbortController().signal,{fetcher,config:cfg});
  await response.text();
  assert.deepEqual(models,[vision]);
  assert.equal(finish.status,"failed");
});

test("resuming an expired image turn fails safely and releases Laravel's lease", async () => {
  const calls = [];
  const fetcher = async (url,init) => {
    calls.push(url);
    if (url.endsWith("/resume")) return Response.json({
      ticket:"c".repeat(64), messages:[],
      checkpoint:{stage:"writing", imageRequired:true, modelId:vision, content:"Partial"},
      conversation_id:1,message_id:3,sources:[],
    });
    if (url.endsWith("/finish")) return Response.json({stored:true});
    throw Error("Unexpected route: " + url);
  };
  const response = await nvidiaChat('{"resume_message_id":3}',"token","https://backend",
    new AbortController().signal,{fetcher,config:cfg});
  assert.equal(response.status,409);
  assert.match((await response.json()).message,/attach it again/);
  assert.ok(calls.some(c=>c.endsWith("/finish")));
});

test("chat retains visual context until explicit clear or conversation switch", () => {
  const file = readFileSync(new URL("../src/components/chat-workspace.tsx", import.meta.url), "utf8");
  assert.match(file, /Keep the transient image in this composer for follow-up questions/);
  assert.match(file, /setInlineImage\(null\)/);
  assert.match(file, /setConversationId\(null\)/);
  assert.match(file, /image: \{mime: inlineImage\.mime, data: inlineImage\.data\}/);
  assert.doesNotMatch(file, /event === "done" && data\.status === "complete" && inlineImage\)\s*setInlineImage\(null\)/);
});

test("vision selection does not depend on the failing Super endpoint", () => {
  const models = [
    {id:-2,slug:"openmindai-super",name:"Super",provider:vision},
    {id:-1001,slug:"nvidia-llama-3.2-11b-vision-instruct",name:"Vision",provider:"meta/llama-3.2-11b-vision-instruct"},
  ];
  assert.equal(selectVisionModel(models)?.provider,"meta/llama-3.2-11b-vision-instruct");
  assert.equal(selectVisionModel(models.slice(1))?.slug,"nvidia-llama-3.2-11b-vision-instruct");
  assert.equal(selectVisionModel([]),undefined);
});


test("image web search reads pixels before starting the real-runtime bridge, without storing image bytes", async () => {
  const calls=[];
  const fetcher=async(url,init)=>{
    const body=JSON.parse(init.body); calls.push({url,body});
    if(url.endsWith('/begin')) return Response.json({ticket:'a'.repeat(64),messages:[{role:'system',content:'Context'},{role:'user',content:'Search official specifications of this board'}],conversation_id:1,message_id:3,user_message_id:2,sources:[],blueprint_available:true});
    if(url.endsWith('/checkpoint')||url.endsWith('/finish'))return Response.json({stored:true});
    if(url.endsWith('/blueprint'))return Response.json({status:'completed',project_id:10,report:'The Raspberry Pi 5 is documented by its manufacturer [1](https://www.raspberrypi.com/products/raspberry-pi-5/).',sources:[{url:'https://www.raspberrypi.com/products/raspberry-pi-5/',title:'Raspberry Pi 5',read_status:'read',passages:[{id:'p1',text:'Raspberry Pi 5 official product documentation.'}]}]});
    if(url.endsWith('/status'))return Response.json({cancelled:false});
    if(body.stream)return new Response('data: '+JSON.stringify({choices:[{delta:{content:'Image: Raspberry Pi 5; visual code `VISION-527`. Official documentation is available [1](https://www.raspberrypi.com/products/raspberry-pi-5/).'},finish_reason:'stop'}]})+'\n\ndata: [DONE]\n\n',{headers:{'Content-Type':'text/event-stream'}});
    return Response.json({choices:[{message:{content:'Visible board label: Raspberry Pi 5. Visual code VISION-527. Exact RAM size is not visible.'},finish_reason:'stop'}]});
  };
  const response=await nvidiaChat(JSON.stringify({message:'Search official specifications of this board',model_id:-4,image,web_search:true}),'session','https://backend',new AbortController().signal,{fetcher,config:cfg});
  const output=await response.text();
  assert.match(output,/Raspberry Pi 5/); assert.match(output,/"saved":true/);
  assert.equal((output.match(/event: meta\n/g)||[]).length,1);
  const visionIndex=calls.findIndex(c=>c.url.includes('nvidia.com'));
  const searchIndex=calls.findIndex(c=>c.url.endsWith('/blueprint'));
  assert.ok(visionIndex>=0 && searchIndex>visionIndex);
  assert.match(calls[searchIndex].body.question,/Visible board label: Raspberry Pi 5/);
  assert.equal(calls[visionIndex].body.messages.at(-1).content[1].type,'image_url');
  const writer=calls.find(c=>c.body.stream);
  assert.ok(writer && calls.indexOf(writer)>searchIndex);
  assert.match(JSON.stringify(writer.body.messages),/VISION-527/);
  assert.match(JSON.stringify(writer.body.messages),/numbering differs/);
  assert.match(output,/VISION-527/);
  assert.doesNotMatch(output,/event: delta\ndata:.*documented by its manufacturer/);
  assert.ok(calls.filter(c=>!c.url.includes('nvidia.com')).every(c=>!JSON.stringify(c.body).includes(png)));
  assert.ok(calls.filter(c=>c.url.endsWith('/checkpoint')).some(c=>c.body.state.imageRequired===false && c.body.state.nativeBlueprint));
  assert.equal(calls.find(c=>c.url.endsWith('/finish')).body.status,'complete');
});

test("failed image reading does not search using guessed image identity", async()=>{
  let searches=0,final;
  const fetcher=async(url,init)=>{
    if(url.endsWith('/begin'))return Response.json({ticket:'a'.repeat(64),messages:[{role:'user',content:'Search this image'}],conversation_id:1,message_id:3,sources:[],blueprint_available:true});
    if(url.endsWith('/checkpoint'))return Response.json({stored:true});
    if(url.endsWith('/finish')){final=JSON.parse(init.body);return Response.json({stored:true});}
    if(url.endsWith('/blueprint')){searches++;return Response.json({});}
    return Response.json({error:'unavailable'},{status:503});
  };
  await(await nvidiaChat(JSON.stringify({message:'Search this image',model_id:-4,image,web_search:true}),'session','https://backend',new AbortController().signal,{fetcher,config:cfg})).text();
  assert.equal(searches,0); assert.equal(final.status,'failed');
});

test("interrupted final writing retains native completion and image observations without restarting research", async()=>{
  let checkpoint={stage:'writing',nativeBlueprint:true,nativeReportReady:true,imageObservation:'Visual code VISION-527',imageRequired:false,messages:[{role:'user',content:'Read the code and verify the board'},{role:'system',content:'Image observation: VISION-527; retrieved official passages.'}],content:'',modelId:fast};
  let nativeCalls=0,streams=0,lastFinish;
  const fetcher=async(url,init)=>{
    const body=JSON.parse(init.body);
    if(url.endsWith('/resume'))return Response.json({ticket:'a'.repeat(64),messages:checkpoint.messages,checkpoint,conversation_id:1,message_id:3,user_message_id:2,sources:[]});
    if(url.endsWith('/checkpoint')){checkpoint=body.state;return Response.json({stored:true});}
    if(url.endsWith('/finish')){lastFinish=body;return Response.json({stored:true});}
    if(url.endsWith('/status'))return Response.json({cancelled:false});
    if(url.endsWith('/blueprint')){nativeCalls++;throw new Error('Research must not restart');}
    streams++;
    assert.match(JSON.stringify(body.messages),/VISION-527/);
    return new Response('data: '+JSON.stringify({choices:[{delta:{content:streams===1?'Visual code `VISION-527`. ':'Verified answer.'},finish_reason:'stop'}]})+'\n\n'+(streams===1?'':'data: [DONE]\n\n'),{headers:{'Content-Type':'text/event-stream'}});
  };
  const request=()=>nvidiaChat(JSON.stringify({resume_message_id:3,model_id:-4}),'session','https://backend',new AbortController().signal,{fetcher,config:cfg});
  await(await request()).text();
  assert.equal(lastFinish.status,'failed');
  assert.equal(checkpoint.nativeReportReady,true);
  assert.equal(checkpoint.nativeBlueprint,true);
  assert.equal(checkpoint.imageObservation,'Visual code VISION-527');
  await(await request()).text();
  assert.equal(lastFinish.status,'complete');
  assert.equal(nativeCalls,0);
});
