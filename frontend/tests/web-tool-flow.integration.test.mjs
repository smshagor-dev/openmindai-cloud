import test from 'node:test';
import assert from 'node:assert/strict';
import { nvidiaChat } from '../src/lib/nvidia-chat.ts';
const sse = (delta, reason) => new Response(`data: ${JSON.stringify({choices:[{delta,finish_reason:reason}]})}\n\ndata: [DONE]\n\n`,{headers:{'Content-Type':'text/event-stream'}});
const call = (name,input,id) => sse({tool_calls:[{index:0,id,type:'function',function:{name,arguments:JSON.stringify(input)}}]},'tool_calls');

test('selected web search uses focused native tools, reads the source and saves evidence', async () => {
  const source='https://www.php.net/manual/en/function.array-map.php';
  const inference=[call('web_search',{query:'PHP array_map official documentation'},'search1'),call('read_page',{url:source},'read1'),sse({content:`array_map applies a callback to array elements. [1](${source})`},'stop')];
  const requests=[];
  const response=await nvidiaChat(JSON.stringify({message:'Search for the official PHP documentation on array_map, read it, and explain with its link.',web_search:true}),
    'user-session','https://backend/api/v1',new AbortController().signal,{
      config:{apiKey:'fixture-only',bridgeKey:'k'.repeat(32),model:'nvidia/nemotron-3.5-lightning-30b-a3b'},
      fetcher:async (url,init) => {
        const body=JSON.parse(init.body); requests.push({url,body});
        if(url.endsWith('/begin')) return Response.json({ticket:'a'.repeat(64),messages:[{role:'user',content:'Search the official PHP array_map docs and read them.'}],conversation_id:1,user_message_id:2,message_id:3,sources:[]});
        if(url.endsWith('/tool')) return Response.json(body.action==='search'?{sources:[{url:source,title:'PHP array_map'}]}:{url:source,read_status:'read',page_text:'array_map applies the callback to array elements.'});
        if(url.endsWith('/chat/completions')) return inference.shift();
        if(url.endsWith('/status')) return Response.json({cancelled:false});
        if(url.endsWith('/checkpoint')||url.endsWith('/finish')) return Response.json({stored:true});
        throw Error('Unexpected request');
      }
    });
  const output=await response.text();
  assert.match(output,/array_map applies/);
  const begin=requests.find(r=>r.url.endsWith('/begin')).body;
  assert.equal(begin.web_search,false);
  assert.equal(begin.conversation_tools,true);
  assert.equal(begin.provider_model,'nvidia/nemotron-3.5-lightning-30b-a3b');
  const tools=requests.filter(r=>r.url.endsWith('/tool')).map(r=>r.body);
  assert.equal(tools[0].query,'PHP array_map official documentation');
  assert.equal(tools[1].url,source);
  const finish=requests.find(r=>r.url.endsWith('/finish')).body;
  assert.equal(finish.status,'complete');
  assert.equal(finish.sources[0].read_status,'read');
});

test('verified Auto Web Search uses Ultra native tools through the authenticated backend bridge', async () => {
  const previous=process.env.FRONTEND_CHAT_BRIDGE_KEY;
  process.env.FRONTEND_CHAT_BRIDGE_KEY='k'.repeat(32);
  try {
    const source='https://www.php.net/manual/en/function.array-map.php';
    const replies=[call('web_search',{query:'PHP array_map official documentation'},'s'),call('read_page',{url:source},'r'),sse({content:`এটি প্রতিটি উপাদানে callback প্রয়োগ করে [1](${source})।`},'stop')];
    const providerRequests=[];
    let finish;
    const response=await nvidiaChat(JSON.stringify({message:'বাংলায় array_map বোঝাও',web_search:true}),
      'user-session','https://backend/api/v1',new AbortController().signal,{
        pollMs:100000,
        fetcher:async(url,init)=>{
          if(url.endsWith('/public-config')) return Response.json({frontend_inference_route:'backend'});
          if(url.endsWith('/chat/hosted-models')) return Response.json({data:[{id:'openmindai-lite',last_test_status:'success'},{id:'openmindai-ultra',last_test_status:'success'}]});
          const body=JSON.parse(init.body);
          if(url.endsWith('/begin')) return Response.json({ticket:'a'.repeat(64),messages:[{role:'user',content:'বাংলায় array_map বোঝাও'}],message_id:3,sources:[]});
          if(url.endsWith('/tool')) return Response.json(body.action==='search'?{sources:[{url:source,title:'PHP array_map'}]}:{url:source,read_status:'read',page_text:'array_map applies the callback to array elements.'});
          if(url.endsWith('/chat/provider')) {
            assert.equal(init.headers.Authorization,'Bearer user-session');
            assert.equal(init.headers['X-Chat-Bridge-Key'],'k'.repeat(32));
            providerRequests.push(body);
            return replies.shift();
          }
          if(url.endsWith('/finish')) {finish=body;return Response.json({stored:true});}
          if(url.endsWith('/checkpoint')||url.endsWith('/status')) return Response.json({stored:true,cancelled:false});
          throw Error('Unexpected request');
        },
      });
    const output=await response.text();
    assert.match(output,/প্রতিটি উপাদানে/);
    assert.equal(providerRequests.length,3);
    assert.ok(providerRequests.every(body=>body.model==='nvidia/nemotron-3-ultra-550b-a55b'&&body.chat_template_kwargs?.enable_thinking===false));
    assert.equal(finish.status,'complete');
    assert.equal(finish.sources[0].read_status,'read');
    assert.ok(!output.includes('k'.repeat(32)));
  } finally {
    if(previous===undefined) delete process.env.FRONTEND_CHAT_BRIDGE_KEY;
    else process.env.FRONTEND_CHAT_BRIDGE_KEY=previous;
  }
});

test('later native searches preserve read evidence and stable source links supplied to the model', async () => {
  const first='https://example.org/first', second='https://example.org/second', third='https://example.org/third';
  const replies=[call('web_search',{query:'original evidence'},'s1'),call('read_page',{url:first},'r1'),call('web_search',{query:'follow-up evidence'},'s2'),call('read_page',{url:second},'r2'),sse({content:`Finding [1](${first}) and comparison [2](${second}).`},'stop')];
  const requests=[];let searches=0,finish;
  const response=await nvidiaChat(JSON.stringify({message:'Search and compare the original evidence.',web_search:true}),
    'user-session','https://backend/api/v1',new AbortController().signal,{
      pollMs:100000,
      config:{apiKey:'fixture-only',bridgeKey:'k'.repeat(32),model:'nvidia/nemotron-3.5-lightning-30b-a3b'},
      fetcher:async(url,init)=>{
        const body=JSON.parse(init.body);
        if(url.endsWith('/begin'))return Response.json({ticket:'a'.repeat(64),messages:[{role:'user',content:'Search and compare.'}],message_id:3,sources:[]});
        if(url.endsWith('/tool')) {
          if(body.action==='search')return Response.json({sources:++searches===1?[{url:first,title:'First'},{url:second,title:'Second'}]:[{url:first,title:'Later snippet',retrieved_at:'search-time'},{url:third,title:'Third'}]});
          return Response.json({url:body.url,read_status:'read',page_text:'Actual retrieved page evidence',retrieved_at:'page-time',passages:[{id:'p1',text:'Actual retrieved page evidence'}]});
        }
        if(url.endsWith('/chat/completions')) {requests.push(body);return replies.shift();}
        if(url.endsWith('/finish')) {finish=body;return Response.json({stored:true});}
        if(url.endsWith('/checkpoint')||url.endsWith('/status'))return Response.json({stored:true,cancelled:false});
        throw Error('Unexpected request');
      }
    });
  await response.text();
  assert.equal(finish.status,'complete');
  assert.deepEqual(finish.sources.map(s=>[s.url,s.citation,s.read_status]),[[first,1,'read'],[second,2,'read'],[third,3,'snippet_only']]);
  assert.equal(finish.sources[0].retrieved_at,'page-time');
  assert.equal(finish.sources[0].title,'First');
  assert.deepEqual(finish.sources[0].passages,[{id:'p1',text:'Actual retrieved page evidence'}]);
  const result=(request,id)=>JSON.parse(request.messages.find(m=>m.role==='tool'&&m.tool_call_id===id).content);
  assert.equal(result(requests[2],'r1').citation_markdown,`[1](${first})`);
  assert.deepEqual(result(requests[3],'s2').sources.map(s=>s.citation_markdown),[`[1](${first})`,`[3](${third})`]);
  assert.equal(result(requests[4],'r2').citation_markdown,`[2](${second})`);
});
