import test from 'node:test';
import assert from 'node:assert/strict';
import {publicAddress, publicTarget} from './network.mjs';

test('browser egress rejects private, reserved and mapped addresses', () => {
  for (const ip of ['127.0.0.1','10.0.0.1','169.254.169.254','100.64.0.1','198.18.0.1','192.0.2.1','::1','fc00::1','fe80::1','::ffff:127.0.0.1','2001:db8::1']) {
    assert.equal(publicAddress(ip),false,ip);
  }
  assert.equal(publicAddress('8.8.8.8'),true);
  assert.equal(publicAddress('2606:4700:4700::1111'),true);
});
test('all DNS answers are checked and unsafe URL forms fail closed', async () => {
  const mixed=async()=>[{address:'8.8.8.8',family:4},{address:'127.0.0.1',family:4}];
  await assert.rejects(publicTarget('https://example.org',mixed),/Non-public/);
  for (const value of ['file:///etc/passwd','http://user:pass@example.org','http://example.org:3000']) {
    await assert.rejects(publicTarget(value,mixed),/Unsupported/);
  }
  const safe=await publicTarget('https://example.org',async()=>[{address:'8.8.8.8',family:4}]);
  assert.equal(safe.address.address,'8.8.8.8');
});
