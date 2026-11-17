// Faux Jellyfin pour tester les parcours de l'appli web MirTV sans compte réel.
// Sert l'appli (dossier mirtv/pwa) sous /tv/ et répond aux appels utilisés par l'appli.
// Usage : node faux-jellyfin.js [port]
const http = require("http");
const fs = require("fs");
const path = require("path");

const PORT = Number(process.argv[2] || 8099);
const PWA = path.resolve(__dirname, "../pwa");
const TYPES = { ".html": "text/html; charset=utf-8", ".js": "text/javascript", ".css": "text/css", ".png": "image/png", ".ttf": "font/ttf", ".webmanifest": "application/manifest+json", ".json": "application/json", ".md": "text/plain" };

const UTILISATEURS = {
  "f1-parent": { Id: "u-parent", Name: "f1-parent" },
  "f1-lea": { Id: "u-lea", Name: "f1-lea" },
  "f1-dort": { Id: "u-dort", Name: "f1-dort" },
  // Comptes sans préfixe de famille : on reconnaît l'enfant à ses droits
  "parent1": { Id: "u-parent1", Name: "parent1", Policy: { AccessSchedules: [], EnableAllFolders: false, EnabledFolders: ["films", "enfants"] } },
  "irchad": { Id: "u-irchad", Name: "irchad", Policy: { AccessSchedules: [{ DayOfWeek: "Everyday", StartHour: 0, EndHour: 24 }], EnableAllFolders: false, EnabledFolders: ["enfants"] } },
};
const PLAGE_JAMAIS = [{ DayOfWeek: "Everyday", StartHour: 0, EndHour: 0 }];

const maintenant = () => new Date();
const h = n => new Date(Date.now() + n * 3600 * 1000).toISOString();
const CHAINES = [
  ["c1", "Info Une", "1", "onglet-infos"], ["c2", "Info Deux", "2", "onglet-infos"], ["c3", "Culture Plus", "3", "onglet-culture"],
  ["c4", "Sport Max", "4", "onglet-sport"], ["c5", "Petits Loups", "5", "onglet-enfants"], ["c6", "Dessins Joie", "6", "onglet-enfants"],
  ["c7", "Chaîne En Panne", "7", "onglet-infos"],
].map(([Id, Name, ChannelNumber, tag], i) => ({ Id, Name, ChannelNumber, Type: "TvChannel", Tags: [tag], ImageTags: {}, UserData: { IsFavorite: false },
  CurrentProgram: { Id: "p-" + Id, Name: "Journal de " + Name, StartDate: new Date(Date.now() - 20 * 60000).toISOString(), EndDate: new Date(Date.now() + 40 * 60000).toISOString(),
    ImageTags: i % 3 === 2 ? {} : { Primary: "img" + i } } }));

const VIDEOS = [
  { Id: "v1", Name: "Le Baobab Géant", Tags: ["Nature"], RunTimeTicks: 80 * 60 * 1e7, Overview: "Un voyage au pied du plus grand baobab de Madagascar, avec les familles qui vivent autour. Une deuxième phrase qui ne doit pas s'afficher.", UserData: { PlaybackPositionTicks: 20 * 60 * 1e7, IsFavorite: false }, MediaSources: [{ Id: "v1" }], ImageTags: { Primary: "i1" } },
  { Id: "v2", Name: "La Mer à Diego", Tags: ["Nature"], RunTimeTicks: 50 * 60 * 1e7, Overview: "La baie de Diego-Suarez vue du ciel.", UserData: { PlaybackPositionTicks: 0, IsFavorite: false }, MediaSources: [{ Id: "v2" }], ImageTags: { Primary: "i2" } },
  { Id: "v4", Name: "Court Métrage", Tags: ["Nature"], RunTimeTicks: 20 * 1e7, Overview: "Un film très court.", UserData: { PlaybackPositionTicks: 10 * 1e7, IsFavorite: false }, MediaSources: [{ Id: "v4" }], ImageTags: {} },
  { Id: "v3", Name: "Cuisine Facile", Tags: ["Cuisine", "pas-enfants"], RunTimeTicks: 30 * 60 * 1e7, Overview: "Trois recettes simples.", UserData: { PlaybackPositionTicks: 0, IsFavorite: false }, MediaSources: [{ Id: "v3" }], ImageTags: {} },
];

const appels = [];
function servirSource(p, res) {
  // Source de test : flux HLS court (30 s) fabriqué avec ffmpeg, même playlist pour toutes les chaînes
  const nom = p.endsWith(".m3u8") ? "index.m3u8" : path.basename(p);
  const f = path.join(__dirname, "fixtures", "hls", nom);
  if (!fs.existsSync(f)) { res.writeHead(404); return res.end(); }
  res.writeHead(200, { "Content-Type": nom.endsWith(".m3u8") ? "application/vnd.apple.mpegurl" : "video/mp2t", "Access-Control-Allow-Origin": "*" });
  fs.createReadStream(f).pipe(res);
}
let qcPolls = 0;

function json(res, code, obj) {
  res.writeHead(code, { "Content-Type": "application/json", "Date": new Date().toUTCString() });
  res.end(obj === undefined ? "" : JSON.stringify(obj));
}
function utilisateurDuJeton(req) {
  const m = /Token="([^"]+)"/.exec(req.headers.authorization || "") || /api_key=([^&]+)/.exec(req.url);
  if (!m) return null;
  return Object.values(UTILISATEURS).find(u => "jeton-" + u.Id === decodeURIComponent(m[1])) || null;
}
function auth(u) { return { AccessToken: "jeton-" + u.Id, User: u }; }

const serveur = http.createServer((req, res) => {
  const url = new URL(req.url, "http://x");
  const p = url.pathname;
  let corps = "";
  req.on("data", d => { corps += d; });
  req.on("end", () => {
    appels.push({ methode: req.method, chemin: p, requete: url.search, corps });
    // Comme le vrai serveur : un en-tête avec des caractères non ASCII est refusé (400)
    if (/[^\x20-\x7E]/.test(req.headers.authorization || "")) { res.writeHead(400); return res.end(); }
    // Appli web
    if (p.startsWith("/tv/logos/")) {
      const f = path.join(__dirname, "fixtures", "logos", path.basename(decodeURIComponent(p)));
      if (!fs.existsSync(f)) { res.writeHead(404); return res.end(); }
      res.writeHead(200, { "Content-Type": f.endsWith(".json") ? "application/json" : "image/png" });
      return fs.createReadStream(f).pipe(res);
    }
    let mi;
    if ((mi = /^\/Items\/p-c(\d+)\/Images\/Primary$/.exec(p)) || (mi = /^\/Items\/v(\d+)\/Images\/Primary$/.exec(p))) {
      const f = path.join(__dirname, "fixtures", "images", `prog${Number(mi[1]) % 7}.jpg`);
      res.writeHead(200, { "Content-Type": "image/jpeg" });
      return fs.createReadStream(f).pipe(res);
    }
    if (p === "/tv" ) { res.writeHead(302, { Location: "/tv/" }); return res.end(); }
    if (p.startsWith("/tv/")) {
      const f = path.join(PWA, decodeURIComponent(p.slice(4)) || "index.html");
      const fichier = fs.existsSync(f) && fs.statSync(f).isDirectory() ? path.join(f, "index.html") : f;
      if (!fichier.startsWith(PWA) || !fs.existsSync(fichier)) { res.writeHead(404); return res.end(); }
      res.writeHead(200, { "Content-Type": TYPES[path.extname(fichier)] || "application/octet-stream" });
      return fs.createReadStream(fichier).pipe(res);
    }
    if (p === "/__appels") return json(res, 200, appels);
    if (p === "/__reset") { appels.length = 0; qcPolls = 0; CHAINES.forEach(c => { c.UserData.IsFavorite = false; }); VIDEOS.forEach(v => { v.UserData.IsFavorite = false; }); return json(res, 200, {}); }

    // Connexion
    if (p === "/QuickConnect/Enabled") return json(res, 200, true);
    if (p === "/QuickConnect/Initiate") return json(res, 200, { Code: "482913", Secret: "secret-qc" });
    if (p === "/QuickConnect/Connect") { qcPolls++; return json(res, 200, { Authenticated: qcPolls >= 1 }); }
    if (p === "/Users/AuthenticateWithQuickConnect") return json(res, 200, auth(UTILISATEURS["f1-parent"]));
    if (p === "/Users/AuthenticateByName") {
      const { Username, Pw } = JSON.parse(corps || "{}");
      if (Username === "plein") { res.writeHead(401, { "Content-Type": "text/plain" }); return res.end("User is at their maximum number of sessions."); }
      const u = UTILISATEURS[Username];
      if (!u || Pw !== "test") { res.writeHead(401); return res.end("Error processing request."); }
      return json(res, 200, auth(u));
    }
    if (p === "/System/Ping") return json(res, 200, "ok");
    if (p.startsWith("/source/")) return servirSource(p, res);

    const u = utilisateurDuJeton(req);
    if (!u) { res.writeHead(401); return res.end(); }
    const enfant = !["f1-parent", "parent1"].includes(u.Name);

    let m;
    if ((m = /^\/Users\/([^/]+)$/.exec(p))) return json(res, 200, { Id: u.Id, Name: u.Name, Policy: { AccessSchedules: u.Name === "f1-dort" ? PLAGE_JAMAIS : (u.Policy ? u.Policy.AccessSchedules : []) } });
    if (p === "/LiveTv/Channels") {
      let l = CHAINES;
      if (enfant) l = l.filter(c => c.Tags.includes("onglet-enfants"));
      return json(res, 200, { Items: l, TotalRecordCount: l.length });
    }
    if (p === "/LiveTv/Programs") {
      const ids = (url.searchParams.get("ChannelIds") || CHAINES.map(c => c.Id).join(",")).split(",");
      const items = [];
      ids.forEach(id => {
        items.push({ ChannelId: id, Name: "Programme actuel " + id, StartDate: h(-0.5), EndDate: h(0.5) });
        items.push({ ChannelId: id, Name: "Programme suivant " + id, StartDate: h(0.5), EndDate: h(1.5) });
        items.push({ ChannelId: id, Name: "Film du soir " + id, StartDate: h(9), EndDate: h(11) });
      });
      return json(res, 200, { Items: items });
    }
    if ((m = /^\/Items\/([^/]+)\/PlaybackInfo$/.exec(p))) {
      const src = m[1] === "c7" ? `http://127.0.0.1:${PORT}/source-absente/c7.m3u8` : `http://127.0.0.1:${PORT}/source/${m[1]}.m3u8`;
      return json(res, 200, { MediaSources: [{ Id: m[1], Path: src, Protocol: "Http" }] });
    }
    if (p === "/UserViews") return json(res, 200, { Items: [{ Id: "lib-films", Name: "Films et vidéos" }, { Id: "lib-enfants", Name: "Enfants" }] });
    if ((m = /^\/Users\/[^/]+\/Items\/Resume$/.exec(p))) return json(res, 200, { Items: VIDEOS.filter(v => v.UserData.PlaybackPositionTicks > 0) });
    if ((m = /^\/Users\/[^/]+\/Items\/([^/]+)$/.exec(p))) return json(res, 200, VIDEOS.find(v => v.Id === m[1]) || VIDEOS[0]);
    if (/^\/Users\/[^/]+\/Items$/.test(p)) {
      let l = VIDEOS;
      if (enfant) l = l.filter(v => !v.Tags.includes("pas-enfants"));
      if (url.searchParams.get("Filters") === "IsFavorite") l = l.filter(v => v.UserData.IsFavorite);
      return json(res, 200, { Items: l });
    }
    if ((m = /^\/Users\/[^/]+\/FavoriteItems\/([^/]+)$/.exec(p))) {
      const it = CHAINES.find(c => c.Id === m[1]) || VIDEOS.find(v => v.Id === m[1]);
      if (it) it.UserData.IsFavorite = req.method === "POST";
      return json(res, 200, { IsFavorite: req.method === "POST" });
    }
    if (p === "/QuickConnect/Authorize") return url.searchParams.get("code") === "482913" ? json(res, 200, true) : (res.writeHead(404), res.end());
    if (p === "/Sessions") return json(res, 200, [{ Id: "s-autre", DeviceId: "autre", DeviceName: "Télé du salon", UserName: "f1-parent" }]);
    if (p.startsWith("/Sessions/")) return json(res, 204);
    if (p === "/Videos/v4/stream") {
      const f = path.join(__dirname, "fixtures", "video.mp4"), taille = fs.statSync(f).size, plage = /bytes=(\d+)-(\d*)/.exec(req.headers.range || "");
      if (plage) {
        const debut = Number(plage[1]), fin = plage[2] ? Number(plage[2]) : taille - 1;
        res.writeHead(206, { "Content-Type": "video/mp4", "Content-Range": `bytes ${debut}-${fin}/${taille}`, "Accept-Ranges": "bytes", "Content-Length": fin - debut + 1 });
        return fs.createReadStream(f, { start: debut, end: fin }).pipe(res);
      }
      res.writeHead(200, { "Content-Type": "video/mp4", "Accept-Ranges": "bytes", "Content-Length": taille });
      return fs.createReadStream(f).pipe(res);
    }
    if (p.startsWith("/Videos/")) { res.writeHead(404); return res.end(); }
    res.writeHead(404); res.end();
  });
});
serveur.listen(PORT, "127.0.0.1", () => console.log("Faux Jellyfin MirTV sur http://127.0.0.1:" + PORT + "/tv/"));
