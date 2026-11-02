import test from 'node:test';
import assert from 'node:assert/strict';
import {repairWebNumbers} from '../src/lib/web-answer-repair.ts';
import {groundResearchAnswer} from '../src/lib/research-evidence.ts';
const url='https://www.raspberrypi.com/documentation/power';
const sources=[{url,title:'Official power requirements',citation:1,read_status:'read',passages:[{id:'p1',text:'The required input voltage is 5 V DC and the input current is 5 A.'}]}];
const original='Image observations: visual code VISION-813.\n\nThe recommended power supply is 27 W with 5 V and 5 A input [1]('+url+').';
const corrected='Image observations: visual code VISION-813.\n\nThe documented power requirements are 5 V DC and 5 A input [1]('+url+').';
const options={text:original,sources,messages:[{role:'user',content:'Read the image and verify the power requirements.'}],imageObservation:'Visual code VISION-813',model:'verified-model',apiKey:'synthetic-test-key',signal:new AbortController().signal};
test('unsupported numeric detail is corrected against passages without deleting the image code or imposing a token cap',async()=>{
  let request;
  const result=await repairWebNumbers({...options,fetcher:async(_,init)=>{request=JSON.parse(init.body);return Response.json({choices:[{message:{content:corrected},finish_reason:'stop'}]});}});
  assert.equal(result,corrected);
  assert.match(JSON.stringify(request.messages),/numerical evidence audit/);
  assert.equal(request.max_tokens,undefined);
  assert.ok(!groundResearchAnswer(result,sources).audit.some(a=>a.status==='numeric_mismatch'));
});
test('provider failure, a still-unsupported repair and a lost visual code retain the original warning',async()=>{
  for(const reply of [null,original,corrected.replace('VISION-813','other-code')]){
    const result=await repairWebNumbers({...options,fetcher:async()=>reply===null?Response.json({error:'unavailable'},{status:503}):Response.json({choices:[{message:{content:reply},finish_reason:'stop'}]})});
    assert.equal(result,original);
    assert.ok(groundResearchAnswer(result,sources).audit.some(a=>a.status==='numeric_mismatch'));
  }
});
test('already-supported answers make no additional provider request',async()=>{
  const result=await repairWebNumbers({...options,text:corrected,fetcher:async()=>{throw new Error('Unnecessary repair');}});
  assert.equal(result,corrected);
});
