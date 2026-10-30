import test from 'node:test';
import assert from 'node:assert/strict';
import {automaticCoordinator,automaticWebCoordinator,automaticSpecialists} from '../src/lib/auto-models.ts';
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
test('selected Web Search prefers verified Ultra while general chat and unavailable Ultra preserve fallback routing',()=>{
 const lite=model('openmindai-lite','success');
 assert.equal(automaticWebCoordinator([lite,model('openmindai-ultra','success')]).slug,'openmindai-ultra');
 assert.equal(automaticCoordinator([lite,model('openmindai-ultra','success')]).slug,'openmindai-ultra');
 for(const status of [null,'not_tested','failed','rate_limited','unreachable','disabled'])
   assert.equal(automaticWebCoordinator([lite,model('openmindai-ultra',status)]).slug,'openmindai-lite');
 assert.equal(automaticWebCoordinator([model('openmindai-ultra','failed')]),undefined);
});
