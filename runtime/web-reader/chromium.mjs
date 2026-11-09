import {chromium} from 'playwright';
import {fetchPublic, publicTarget} from './network.mjs';

let raw = '';
for await (const chunk of process.stdin) {
  raw += chunk;
  if (raw.length > 2100000) throw Error('Input too large');
}
const input = JSON.parse(raw);
const target = new URL(input.url);
target.hash = '';
input.url = target.href;
await publicTarget(input.url);
if (typeof input.html !== 'string') throw Error('HTML required');
const browser = await chromium.launch({headless:true, chromiumSandbox:true, timeout:4000,
  executablePath:process.env.WEB_RESEARCH_CHROMIUM_BINARY || undefined,
  // A failed interception must never fall through to a direct browser request.
  proxy:{server:'http://127.0.0.1:9', bypass:'<-loopback>'},
  args:['--force-webrtc-ip-handling-policy=disable_non_proxied_udp'],
});
const deadline = setTimeout(() => {void browser.close();}, 12000);
try {
  const context = await browser.newContext({serviceWorkers:'block', acceptDownloads:false});
  await context.routeWebSocket(/.*/, socket => socket.close());
  let initial = true, requests = 0, bytes = 0;
  await context.route('**/*', async route => {
    try {
      const request = route.request();
      if (initial && request.isNavigationRequest() && request.url() === input.url) {
        initial = false;
        return await route.fulfill({contentType:'text/html', body:input.html});
      }
      if (++requests > 60 || request.method() !== 'GET' ||
          !['script','stylesheet','xhr','fetch'].includes(request.resourceType())) return await route.abort();
      const resource = await fetchPublic(request.url());
      bytes += resource.body.length;
      if (bytes > 8000000) return await route.abort();
      await route.fulfill({status:200, contentType:resource.contentType, body:resource.body});
    } catch {await route.abort().catch(() => {});}
  });
  const page = await context.newPage();
  await page.goto(input.url, {waitUntil:'domcontentloaded', timeout:8000});
  await page.waitForLoadState('networkidle', {timeout:2500}).catch(() => {});
  const document = await page.evaluate(() => {
    for (const node of document.querySelectorAll('script,style,nav,header,footer,noscript,iframe,form')) node.remove();
    return {title:document.title.slice(0,200), text:(document.body?.innerText || '').slice(0,128000)};
  });
  if (!document.text.trim()) throw Error('Empty rendered page');
  process.stdout.write(JSON.stringify({...document, engine:'chromium'}));
} finally {
  clearTimeout(deadline);
  await browser.close();
}
