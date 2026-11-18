/* MirTV — service worker : l'appli s'ouvre tout de suite depuis sa copie sur l'écran, et se met à jour en fond
   (la nouvelle version est prise à l'ouverture suivante). Jamais les vidéos, ni les réponses du serveur télé,
   ni le compte. Logos, aperçus et programme passent par le cache normal du navigateur (ils changent souvent). */
const VERSION = "mirtv-web-0.1.23";
const FICHIERS = ["./", "index.html", "css/mirtv.css", "js/config.js", "js/textes.js", "js/app.js", "vendor/hls.min.js", "vendor/qrcode.js",
  "manifest.webmanifest", "icons/icone-192.png", "icons/icone-512.png",
  "fonts/lexend_regular.woff2", "fonts/lexend_semibold.woff2", "fonts/lexend_bold.woff2", "fonts/lexend_extrabold.woff2"];
const A_PART = /\/(logos|apercus|guide|import|vignette-locale)\//;

self.addEventListener("install", e => {
  e.waitUntil(caches.open(VERSION).then(c => c.addAll(FICHIERS)).then(() => self.skipWaiting()));
});
self.addEventListener("activate", e => {
  e.waitUntil(caches.keys().then(l => Promise.all(l.filter(k => k.startsWith("mirtv-web-") && k !== VERSION).map(k => caches.delete(k))))
    .then(() => self.clients.claim()));
});
self.addEventListener("fetch", e => {
  const url = new URL(e.request.url);
  if (e.request.method !== "GET" || url.origin !== location.origin) return;
  const portee = new URL(self.registration.scope).pathname;
  if (!url.pathname.startsWith(portee) || A_PART.test(url.pathname)) return; // API, vidéos, images : sans service worker
  // Copie locale d'abord (ouverture immédiate), mise à jour en fond ; réseau si pas encore de copie
  e.respondWith(caches.open(VERSION).then(async c => {
    const cle = e.request.mode === "navigate" ? "index.html" : e.request;
    const copie = await c.match(cle, { ignoreSearch: e.request.mode === "navigate" });
    const reseau = fetch(e.request).then(r => {
      if (r.ok && r.type === "basic") c.put(cle, r.clone());
      return r;
    });
    if (copie) { e.waitUntil(reseau.catch(() => {})); return copie; }
    try { return await reseau; } catch (err) { return (await c.match("index.html")) || Response.error(); }
  }));
});
