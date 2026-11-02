/* Cache only public assets. Never cache authenticated pages, API replies or downloads. */
const CACHE = "openmind-public-v1";
self.addEventListener("install", event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(["/offline.html", "/icons/icon-192.png", "/icons/icon-512.png"]))); });
self.addEventListener("activate", event => { event.waitUntil(Promise.all([caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith("openmind-public-") && key !== CACHE).map(key => caches.delete(key)))), self.clients.claim()])); });
self.addEventListener("message", event => { if (event.data?.type === "SKIP_WAITING") self.skipWaiting(); });
self.addEventListener("fetch", event => {
  const url = new URL(event.request.url);
  if (event.request.method !== "GET" || url.origin !== self.location.origin) return;
  if (event.request.mode === "navigate") {
    event.respondWith(fetch(event.request).catch(() => caches.match("/offline.html")));
    return;
  }
  if (!url.pathname.startsWith("/_next/static/") && !/^\/icons\/icon-(192|512)\.png$/.test(url.pathname)) return;
  event.respondWith(caches.open(CACHE).then(async cache => {
    const cached = await cache.match(event.request);
    if (cached) return cached;
    const response = await fetch(event.request);
    if (response.ok && response.type === "basic") {
      await cache.put(event.request, response.clone());
      const keys = await cache.keys();
      if (keys.length > 120) await cache.delete(keys[0]);
    }
    return response;
  }));
});
self.addEventListener("push", event => {
  let data = {};
  try { data = event.data?.json() || {}; } catch { /* Show a generic notification. */ }
  const url = typeof data.url === "string" && /^\/research-studio(?:\?|$)/.test(data.url) ? data.url : "/research-studio";
  event.waitUntil(self.registration.showNotification("OpenMindAI Research", {body: typeof data.body === "string" ? data.body.slice(0,180) : "Your research has an update.",icon:"/icons/icon-192.png",badge:"/icons/icon-192.png",tag:typeof data.tag === "string" ? data.tag : "research-update",data:{url}}));
});
self.addEventListener("notificationclick", event => {
  event.notification.close();
  const target = new URL(event.notification.data?.url || "/research-studio", self.location.origin);
  if (target.origin !== self.location.origin) return;
  event.waitUntil(self.clients.matchAll({type:"window",includeUncontrolled:true}).then(async clients => {
    const client = clients.find(client => new URL(client.url).origin === target.origin);
    if (client) { await client.navigate(target.href); return client.focus(); }
    return self.clients.openWindow(target.href);
  }));
});
