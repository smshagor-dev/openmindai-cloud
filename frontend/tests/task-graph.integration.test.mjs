import test from 'node:test';
import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import {createRequire} from 'node:module';
import React from 'react';
import {renderToStaticMarkup} from 'react-dom/server';
import {transformSync} from 'next/dist/build/swc/index.js';
import * as layout from '../src/lib/task-graph-layout.ts';

const tasks = [
  {id:1,agent:'planning',status:'completed',key:'Plan the study'},
  {id:2,agent:'discovery',status:'completed',key:'Find primary sources'},
  {id:3,agent:'investigation',status:'running',key:'Read source evidence'},
  {id:4,agent:'verification',status:'queued',key:'Verify claims'},
  {id:5,agent:'manuscript',status:'failed',key:'Draft manuscript',error_code:'provider_unavailable'},
];
const dependencies = [{task_id:2,depends_on:1},{task_id:3,depends_on:1},{task_id:4,depends_on:2},{task_id:4,depends_on:3},{task_id:5,depends_on:4}];

test('architecture retains real branching and fan-in without laying overlapping nodes', () => {
 const graph=layout.taskGraphLayout(tasks,dependencies);
 assert.deepEqual(graph.edges,dependencies);
 assert.equal(graph.positions.get(2).y,graph.positions.get(3).y);
 assert.ok(graph.positions.get(4).y>graph.positions.get(3).y);
 for (const [id,point] of graph.positions) {
   assert.ok(point.x>=0&&point.x+layout.NODE_WIDTH<=graph.width);
   assert.ok(point.y>=0&&point.y+layout.NODE_HEIGHT<=graph.height);
   for (const [otherId,other] of graph.positions) {
     if(id===otherId)continue;
     assert.ok(Math.abs(point.x-other.x)>=layout.NODE_WIDTH || Math.abs(point.y-other.y)>=layout.NODE_HEIGHT);
   }
 }
});
test('large parallel stages wrap at three columns and invalid dependency IDs cannot make phantom nodes', () => {
 const many=Array.from({length:10},(_,i)=>({id:i+1,agent:'discovery',status:'queued'}));
 const graph=layout.taskGraphLayout(many,[{task_id:1,depends_on:999}]);
 assert.equal(graph.edges.length,0);
 assert.equal(graph.positions.size,10);
 assert.equal(new Set([...graph.positions.values()].map(p=>p.y)).size,4);
 assert.doesNotThrow(()=>layout.taskGraphLayout(tasks,[{task_id:1,depends_on:2},{task_id:2,depends_on:1}]));
});
const source=await readFile(new URL('../src/components/research/task-graph.tsx',import.meta.url),'utf8');
const compiled=transformSync(source,{filename:'task-graph.tsx',module:{type:'commonjs'},jsc:{parser:{syntax:'typescript',tsx:true},transform:{react:{runtime:'automatic'}},target:'es2020'}}).code;
const require=createRequire(import.meta.url), module={exports:{}};
new Function('require','module','exports',compiled)(name=>name==='@/lib/task-graph-layout'?layout:require(name),module,module.exports);
const TaskGraph=module.exports.default;
test('architecture renders actual statuses and connections, and animates only the active prerequisite edge', () => {
 const html=renderToStaticMarkup(React.createElement(TaskGraph,{tasks,dependencies}));
 assert.match(html,/Agent workflow/);
 assert.equal((html.match(/marker-end=/g)||[]).length,dependencies.length);
 assert.equal((html.match(/class="active" d=/g)||[]).length,1);
 assert.match(html,/1 running/);
 assert.match(html,/40% complete/);
 assert.match(html,/aria-label="Reset workflow layout"/);
 assert.match(html,/Alt plus arrow keys/);
 const stopped=renderToStaticMarkup(React.createElement(TaskGraph,{tasks:tasks.map(t=>({...t,status:'completed'})),dependencies}));
 assert.doesNotMatch(stopped,/class="active" d=/);
 assert.match(stopped,/Workflow complete/);
});
test('an empty workflow renders an honest starting state',()=>{
 const html=renderToStaticMarkup(React.createElement(TaskGraph,{tasks:[],dependencies:[]}));
 assert.match(html,/Start research/);
 assert.doesNotMatch(html,/rs-flow-node|running/);
});
