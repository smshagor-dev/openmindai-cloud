import test from 'node:test';
import assert from 'node:assert/strict';
import {automaticCoordinator,automaticSpecialists} from '../src/lib/auto-models.ts';
const model=(slug,status)=>({slug,name:slug,provider:slug,lastTestStatus:status});
test('Auto excludes failed providers and restricted models from delegation',()=>{
 const models=[model('openmindai-super','failed'),model('openmindai-lite','success'),model('openmindai-ultra','success'),model('nvidia-laguna-xs-2.1','failed'),model('nvidia-riva-translate-4b','success'),model('guard','success')];
 const coordinator=automaticCoordinator(models);
 // Failed Super is skipped; verified Ultra coordinates and Lite is never a delegate.
 assert.equal(coordinator.slug,'openmindai-ultra');
 assert.deepEqual(automaticSpecialists(models,coordinator.provider).map(m=>m.slug),[]);
 assert.equal(automaticCoordinator([model('openmindai-lite','success'),model('openmindai-super','success')]).slug,'openmindai-super');
 assert.equal(automaticCoordinator([model('openmindai-lite','failed')]),undefined);
});
test('Auto prioritizes verified 550B for general chat and web search',()=>{
 const lite=model('openmindai-lite','success');
 const superModel=model('openmindai-super','success');
 assert.equal(automaticCoordinator([lite,superModel,model('openmindai-ultra','success')]).slug,'openmindai-ultra');
 for(const status of [null,'not_tested','failed','rate_limited','unreachable','disabled'])
   assert.equal(automaticCoordinator([lite,superModel,model('openmindai-ultra',status)]).slug,'openmindai-super');
});
