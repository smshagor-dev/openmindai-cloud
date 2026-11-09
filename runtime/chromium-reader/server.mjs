// Isolated, authenticated Chromium page renderer. Never expose this service
// directly to untrusted clients; block private-network egress at the network layer.
import { createServer } from "node:http";
import { lookup } from "node:dns/promises";
import { isIP } from "node:net";
import { timingSafeEqual } from "node:crypto";
import ipaddr from "ipaddr.js";
import { chromium } from "playwright";

const token = process.env.CHROMIUM_RENDER_TOKEN || "";
if (token.length < 32) throw new Error("CHROMIUM_RENDER_TOKEN must be at least 32 characters");
const host = process.env.HOST || "127.0.0.1";
const port = Number(process.env.PORT || 3107);
const timeout = Math.min(12000, Math.max(3000, Number(process.env.RENDER_TIMEOUT_MS || 10000)));
const maxJobs = Math.min(4, Math.max(1, Number(process.env.RENDER_CONCURRENCY || 2)));
let active = 0;

const forbidden4 = [
  "0.0.0.0/8", "10.0.0.0/8", "100.64.0.0/10",
  "127.0.0.0/8", "169.254.0.0/16", "172.16.0.0/12",
  "192.0.0.0/24", "192.0.2.0/24", "192.88.99.0/24",
  "192.168.0.0/16", "198.18.0.0/15", "198.51.100.0/24",
  "203.0.113.0/24", "224.0.0.0/4", "240.0.0.0/4",
].map(ipaddr.parseCIDR);
const forbidden6 = [
  "::/128", "::1/128", "::ffff:0:0/96", "100::/64",
  "2001:2::/48", "2001:10::/28", "2001:db8::/32",
  "fc00::/7", "fe80::/10", "ff00::/8",
].map(ipaddr.parseCIDR);
const global6 = ipaddr.parseCIDR("2000::/3");

function publicIp(ip) {
  if (!isIP(ip)) return false;
  const address = ipaddr.parse(ip);
  if (address.kind() === "ipv4") {
    return !forbidden4.some(range => address.match(range));
  }
  return address.range() === "unicast" && address.match(global6)
    && !forbidden6.some(range => address.match(range));
}

async function publicUrl(value) {
  try {
    if (typeof value !== "string" || value.length > 2000) return false;
    const url = new URL(value);
    if (!["https:", "http:"].includes(url.protocol) ||
        url.username || url.password || (url.port && !["80", "443"].includes(url.port))) return false;
    const name = url.hostname.toLowerCase().replace(/\.$/, "");
    if (["localhost"].includes(name) || /\.(?:localhost|local|internal|test|invalid|onion)$/.test(name)) return false;
    const ips = isIP(name) ? [{address:name}] : await lookup(name, {all:true, verbatim:true});
    return ips.length > 0 && ips.every(record => publicIp(record.address));
  } catch { return false; }
}
const browser = await chromium.launch({headless:true});
const reply = (res, status, object) => {
  res.writeHead(status, {"Content-Type":"application/json; charset=utf-8","Cache-Control":"no-store","X-Content-Type-Options":"nosniff"});
  res.end(JSON.stringify(object));
};
async function render(url) {
  const context = await browser.newContext({
    acceptDownloads:false, serviceWorkers:"block", ignoreHTTPSErrors:false,
    javaScriptEnabled:true, viewport:{width:1280,height:900},
  });
  try {
    let requests = 0;
    const page = await context.newPage();
    // HTTP routing does not cover WebSocket traffic. Block it explicitly,
    // including connections initiated by untrusted third-party scripts.
    await page.routeWebSocket("**/*", route => route.close());
    await page.route("**/*", async route => {
      const address = route.request().url();
      if (++requests > 90 || !await publicUrl(address)) {
        await route.abort("blockedbyclient").catch(() => {});
        return;
      }
      await route.continue().catch(() => {});
    });
    const response = await page.goto(url, {waitUntil:"domcontentloaded",timeout});
    if (!response || response.status() >= 400) throw new Error("unavailable");
    await page.waitForTimeout(750);
    const data = await page.evaluate(() => ({
      url: location.href,
      title: document.title || "",
      text: document.body?.innerText || "",
    }));
    if (!await publicUrl(data.url)) throw new Error("unsafe redirect");
    return {url:data.url, title:String(data.title).slice(0,200), text:String(data.text).slice(0,128000)};
  } finally {
    await context.close();
  }
}
const server = createServer(async (req,res) => {
  if (req.method === "GET" && req.url === "/health") return reply(res, 200, {ready:true});
  const supplied = (req.headers.authorization || "").replace(/^Bearer /, "");
  const left = Buffer.from(supplied), right = Buffer.from(token);
  if (left.length !== right.length || !timingSafeEqual(left,right))
    return reply(res, 401, {message:"Unauthorized"});
  if (req.method !== "POST" || req.url !== "/render")
    return reply(res, 404, {message:"Not found"});
  if (active >= maxJobs) return reply(res, 503, {message:"Busy"});
  active++;
  try {
    let raw = "";
    for await (const chunk of req) {
      raw += chunk.toString("utf8");
      if (raw.length > 4096) throw new Error("oversized");
    }
    const url = JSON.parse(raw).url;
    if (!await publicUrl(url)) return reply(res, 422, {message:"Unsupported public URL"});
    const result = await render(url);
    reply(res, 200, result);
  } catch {
    reply(res, 502, {message:"Chromium could not read this public page"});
  } finally { active--; }
});
server.requestTimeout = 20000;
server.listen(port, host, () => process.stdout.write("Chromium reader listening\n"));
for(const s of ["SIGINT","SIGTERM"]) process.on(s, () => {
  server.close(async()=>{await browser.close(); process.exit(0);});
});
