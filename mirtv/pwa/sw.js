/* MirTV — service worker : garde l'appli elle-même en mémoire pour qu'elle s'ouvre vite et sans internet.
   Jamais les vidéos ni les réponses du compte (toujours demandées en direct). */
const VERSION = "mirtv-web-0.1.4";
const FICHIERS = ["./", "index.html", "css/mirtv.css", "js/config.js", "js/textes.js", "js/app.js", "vendor/hls.min.js",
  "manifest.webmanifest", "icons/icone-192.png", "icons/icone-512.png",
  "fonts/lexend_regular.ttf", "fonts/lexend_semibold.ttf", "fonts/lexend_bold.ttf", "fonts/lexend_extrabold.ttf"];

self.addEventListener("install", e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(FICHIERS)).then(() => self.skipWaiting()));
});
self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(l => Promise.all(l.filter(k => k !== VERSION).map(k => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener("fetch", e => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET" || url.origin !== location.origin) return;
  const portee = new URL(self.registration.scope).pathname;
  if (!url.pathname.startsWith(portee)) return; // l'API et les vidéos passent sans cache
  // Réseau d'abord (nouvelle version tout de suite), copie en mémoire si pas d'internet
  e.respondWith(fetch(e.request).then(r => {
    if (r.ok) { const copie = r.clone(); caches.open(VERSION).then(c => c.put(e.request, copie)); }
    return r;
  }).catch(() => caches.match(e.request).then(r => r || caches.match("index.html"))));
});
