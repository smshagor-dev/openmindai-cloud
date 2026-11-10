import {lookup} from 'node:dns/promises';
import http from 'node:http';
import https from 'node:https';
import ipaddr from 'ipaddr.js';

export function publicAddress(value) {
  try {
    const address = ipaddr.process(value);
    return address.range() === 'unicast';
  } catch { return false; }
}
export async function publicTarget(value, resolve = lookup) {
  const url = new URL(value);
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password ||
      (url.port && !['80', '443'].includes(url.port))) throw Error('Unsupported URL');
  const host = url.hostname.replace(/^\[|\]$/g, '');
  const addresses = await resolve(host, {all:true, verbatim:true});
  if (!addresses.length || addresses.some(item => !publicAddress(item.address))) throw Error('Non-public host');
  return {url, address:addresses[0]};
}
// Never let Chromium resolve or connect to an untrusted host itself. Validate all
// DNS answers, then pin the selected address for the actual TLS/HTTP connection.
export async function fetchPublic(value) {
  const {url, address} = await publicTarget(value);
  return new Promise((resolve, reject) => {
    const request = (url.protocol === 'https:' ? https : http).get(url, {
      agent:false,
      headers:{'User-Agent':'OpenMindAI-Research/1.0', 'Accept-Encoding':'identity'},
      lookup:(_host, _options, callback) => callback(null, address.address, address.family),
    }, response => {
      // Resource redirects fail closed. The primary document's redirects have
      // already been resolved and DNS-pinned by Laravel's WebReader.
      if ((response.statusCode || 500) >= 300) {response.destroy(); reject(Error('Resource unavailable')); return;}
      const chunks = []; let bytes = 0;
      response.on('data', chunk => {
        bytes += chunk.length;
        if (bytes > 2000000) response.destroy(Error('Resource too large'));
        else chunks.push(chunk);
      });
      response.on('error', reject);
      response.on('end', () => resolve({body:Buffer.concat(chunks), contentType:String(response.headers['content-type'] || 'text/plain')}));
    });
    const deadline = setTimeout(() => request.destroy(Error('Resource timed out')), 5000);
    request.on('close', () => clearTimeout(deadline));
    request.on('error', reject);
  });
}
