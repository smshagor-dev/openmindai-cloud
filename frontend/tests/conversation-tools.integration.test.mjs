import test from "node:test";
import assert from "node:assert/strict";
import {runConversationTools} from "../src/lib/conversation-tools.ts";
const sse = (delta, reason) => new Response(`data: ${JSON.stringify({choices:[{delta,finish_reason:reason}]})}\n\ndata: [DONE]\n\n`,{headers:{"Content-Type":"text/event-stream"}});
const call = (name,input,id) => sse({tool_calls:[{index:0,id,type:"function",function:{name,arguments:JSON.stringify(input)}}]},"tool_calls");
const base = () => ({model:"enabled/coordinator",apiKey:"fixture-only",messages:[{role:"system",content:"context"},{role:"user",content:"Compare sources then create a PDF"}],signal:new AbortController().signal,specialists:[{slug:"code",name:"OpenMindAI Code",provider:"enabled/code"}],web:async()=>({sources:[]}),artifact:async()=>({ok:true,id:1}),progress:()=>{},delta:()=>{}});

test("native tools consume previous results and execute multiple tools before the streamed answer",async()=>{
 const requests=[],actions=[],progress=[],text=[];
 const responses=[call("web_search",{query:"UAV security evidence"},"search1"),call("read_page",{url:"https://example.org/paper"},"read1"),call("create_artifact",{kind:"document_create",prompt:"Evidence summary",format:"pdf"},"file1"),sse({content:"The report is ready with cited evidence."},"stop")];
 const result=await runConversationTools({...base(),fetcher:async(url,init)=>{requests.push(JSON.parse(init.body));return responses.shift();},
 web:async input=>{actions.push(input.action);return {sources:[{url:"https://example.org/paper",title:"Paper"}],page_text:"Verified evidence"};},
 artifact:async input=>{actions.push(input.kind);return {ok:true,id:44};},progress:label=>progress.push(label),delta:chunk=>text.push(chunk)});
 assert.deepEqual(actions,["search","read","document_create"]);
 assert.equal(result.calls,3);
 assert.match(text.join(""),/report is ready/);
 assert.ok(requests[1].messages.some(m=>m.role==="tool" && m.tool_call_id==="search1"));
 assert.ok(requests[3].messages.some(m=>m.role==="tool" && m.content.includes('44')));
 assert.equal(requests[0].tools.length,4);
 assert.ok(progress.includes("Searching the web"));
});

test("tool failures become explicit results and duplicate artifacts are not executed twice",async()=>{
 let generated=0; const requests=[];
 const responses=[call("create_artifact",{kind:"image",prompt:"A forest"},"a"),call("create_artifact",{kind:"image",prompt:"A forest"},"b"),sse({content:"The image service is unavailable."},"stop")];
 await runConversationTools({...base(),fetcher:async(url,init)=>{requests.push(JSON.parse(init.body));return responses.shift();},artifact:async()=>{generated++;throw Error("private provider error");}});
 assert.equal(generated,1);
 const evidence=JSON.stringify(requests[2].messages);
 assert.match(evidence,/already ran/);
 assert.doesNotMatch(evidence,/private provider error/);
});

test("delegation validates the enabled catalog and cancellation prevents execution",async()=>{
 const responses=[call("ask_specialist",{model:"disabled",task:"Write code"},"a"),sse({content:"That specialist is unavailable."},"stop")];
 let requests=0;
 await runConversationTools({...base(),fetcher:async()=>{requests++;return responses.shift();}});
 assert.equal(requests,2);
 const controller=new AbortController();controller.abort();
 await assert.rejects(()=>runConversationTools({...base(),signal:controller.signal,fetcher:async()=>{throw Error("must not execute");}}));
});

test("long answers continue without replaying completed tools",async()=>{
 const requests=[];
 const responses=[call("create_artifact",{kind:"document_create",prompt:"Report"},"file1"),sse({content:"First half. "},"length"),sse({content:"Final half."},"stop")];
 let generated=0;
 const result=await runConversationTools({...base(),fetcher:async(url,init)=>{requests.push(JSON.parse(init.body));return responses.shift();},artifact:async()=>{generated++;return {ok:true,id:8};}});
 assert.equal(result.text,"First half. Final half.");
 assert.equal(generated,1);
 assert.ok(requests[2].messages.some(m=>m.role==="tool" && m.tool_call_id==="file1"));
});

test("coding delegation uses the enabled specialist and returns its evidence to the coordinator", async()=>{
 const requests=[];
 let round=0;
 await runConversationTools({...base(),model:"nvidia/nemotron-3.5-lightning-30b-a3b",fetcher:async(url,init)=>{
  const body=JSON.parse(init.body);requests.push(body);
  if(body.stream===false) return Response.json({choices:[{message:{content:"Fixed the null check with a regression test."}}]});
  return round++===0 ? call("ask_specialist",{model:"code",task:"Fix the null crash in the supplied handler and explain the test."},"coding") : sse({content:"The handler now checks null."},"stop");
 }});
 assert.equal(requests[0].chat_template_kwargs.enable_thinking,false);
 assert.equal(requests[1].model,"enabled/code");
 assert.match(requests[1].messages[1].content,/null crash/);
 assert.ok(requests[2].messages.some(m=>m.role==="tool"&&m.content.includes("regression test")));
});

test('selected Web Search requires search then page reading before the final answer',async()=>{
 const requests=[],actions=[];
 const responses=[call('web_search',{query:'PHP array_map official documentation'},'s'),call('read_page',{url:'https://www.php.net/manual/en/function.array-map.php'},'r'),sse({content:'বাংলা উত্তর: callback receives one argument for each array. https://www.php.net/manual/en/function.array-map.php'},'stop')];
 const result=await runConversationTools({...base(),webSearchSelected:true,fetcher:async(u,i)=>{requests.push(JSON.parse(i.body));return responses.shift();},web:async a=>{actions.push(a.action);return a.action==='search'?{sources:[{url:'https://www.php.net/manual/en/function.array-map.php',title:'array_map'}]}:{read_status:'read',page_text:'The callback accepts one argument per array.'};}});
 assert.equal(requests[0].tool_choice,'required');
 assert.equal(requests[1].tool_choice,'required');
 assert.deepEqual(requests[1].tools.map(t=>t.function.name),['read_page']);
 assert.deepEqual(actions,['search','read']);
 assert.match(result.text,/callback/);
});
test('an explicit no-browse instruction can decline the selected search without fabricated verification',async()=>{
 let webCalls=0;
 const responses=[call('decline_web_search',{reason:'The user explicitly says do not browse.'},'n'),sse({content:'Answering without browsing.'},'stop')];
 await runConversationTools({...base(),webSearchSelected:true,fetcher:async()=>responses.shift(),web:async()=>{webCalls++;}});
 assert.equal(webCalls,0);
});

test('selected search outage retries once and finishes in requested Bangla without an invented model answer',async()=>{
 let webCalls=0,modelCalls=0;const chunks=[];
 const result=await runConversationTools({...base(),webSearchSelected:true,
 messages:[{role:'user',content:'Search for Md Shahanur Islam Shagor and summarize in Bangla.'}],
 fetcher:async()=>{modelCalls++;assert.equal(modelCalls,1);return call('web_search',{query:'"Md Shahanur Islam Shagor"'},'s');},
 web:async()=>{webCalls++;throw Error('upstream private error');},delta:chunk=>chunks.push(chunk)});
 assert.equal(webCalls,2);assert.equal(modelCalls,1);
 assert.match(result.text,/যাচাইযোগ্য/);assert.equal(chunks.join(''),result.text);
 assert.doesNotMatch(result.text,/known_people|database|upstream private/);
});
test('a transient search outage recovers before reading sources and answering',async()=>{
 let searches=0;const responses=[call('web_search',{query:'Md Shahanur Islam Shagor'},'s'),call('read_page',{url:'https://example.org/profile'},'r'),sse({content:'Verified profile: https://example.org/profile'},'stop')];
 const result=await runConversationTools({...base(),webSearchSelected:true,
 fetcher:async()=>responses.shift(),web:async input=>{
 if(input.action==='read')return {read_status:'read',page_text:'Public professional profile'};
 if(++searches===1)throw Error('temporary outage');
 return {sources:[{url:'https://example.org/profile',title:'Public profile'}]};
 }});
 assert.equal(searches,2);assert.match(result.text,/Verified profile/);
});
test('empty selected search results cannot produce a fabricated biography',async()=>{
 let searches=0;
 const result=await runConversationTools({...base(),webSearchSelected:true,
 fetcher:async()=>call('web_search',{query:'Person name'},'s'),web:async()=>{searches++;return {sources:[]};}});
 assert.equal(searches,2);assert.match(result.text,/cannot verify/);
});
test('a search outage still reads a user-supplied public page and never asks for the same link again',async()=>{
 for(const readable of [true,false]){
  let searches=0,reads=0;const chunks=[];
  const url='https://www.php.net/manual/en/function.array-map.php';
  const responses=[call('web_search',{query:'PHP array_map documentation'},'s'),call('read_page',{url},'r'),sse({content:'প্রদত্ত পৃষ্ঠা থেকে যাচাই করা উত্তর।'},'stop')];
  const result=await runConversationTools({...base(),webSearchSelected:true,
   messages:[{role:'user',content:`বাংলায় ব্যাখ্যা করো ${url}`}],
   fetcher:async()=>responses.shift(),delta:chunk=>chunks.push(chunk),web:async input=>{
    if(input.action==='search'){searches++;return {sources:[]};}
    reads++;return {url,read_status:readable?'read':'unavailable',page_text:readable?'array_map applies a callback to array elements.':''};
   }});
  assert.equal(searches,2);assert.equal(reads,1);
  assert.equal(chunks.join(''),result.text);
  assert.match(result.text,readable?/যাচাই করা উত্তর/:/পৃষ্ঠাটিও পড়া যায়নি/);
  assert.doesNotMatch(result.text,/link দিলে|Share an official/);
 }
});

test('cancellation during search outage prevents retry and fallback',async()=>{
 const controller=new AbortController();let searches=0;
 await assert.rejects(()=>runConversationTools({...base(),webSearchSelected:true,signal:controller.signal,
 fetcher:async()=>call('web_search',{query:'Person name'},'s'),web:async()=>{searches++;controller.abort();throw Error('cancelled');}}));
 assert.equal(searches,1);
});

test("planning text before a tool call is not shown, while the final answer streams",async()=>{
 const text=[];
 const narrate=new Response(`data: ${JSON.stringify({choices:[{delta:{content:"[User request] Write a palindrome checker.\n\nI'll produce a short function."}}]})}\n\ndata: ${JSON.stringify({choices:[{delta:{tool_calls:[{index:0,id:"s1",type:"function",function:{name:"ask_specialist",arguments:JSON.stringify({model:"code",task:"palindrome"})}}]},finish_reason:"tool_calls"}]})}\n\ndata: [DONE]\n\n`,{headers:{"Content-Type":"text/event-stream"}});
 const responses=[narrate,Response.json({choices:[{message:{content:"def is_palindrome(s): ..."}}]}),sse({content:"Here is the function."},"stop")];
 const result=await runConversationTools({...base(),fetcher:async()=>responses.shift(),delta:chunk=>text.push(chunk)});
 assert.equal(text.join(""),"Here is the function.");
 assert.equal(result.text,"Here is the function.");
 const long="Answer ".repeat(80);
 const streamed=[];
 await runConversationTools({...base(),fetcher:async()=>sse({content:long},"stop"),delta:chunk=>streamed.push(chunk)});
 assert.equal(streamed.join(""),long);
});
