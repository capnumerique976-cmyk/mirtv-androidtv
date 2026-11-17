// MirTV — test de lecture de flux dans un vrai navigateur, avec hls.js comme l'appli.
// À coller dans la console d'une page https://mirtv.rahiss.com/tv/ (window.Hls y est chargé), puis :
//   await __testerCandidats("/tv/import/candidats.json")   → window.__flux.resultats
// Pour chaque chaîne, ses flux sont essayés dans l'ordre jusqu'au premier qui donne une image.
// Bande passante réduite : plus petite qualité, 4 s de réserve, arrêt dès la première image.
(() => {
  const raisonHls = d => {
    const code = d.response && d.response.code;
    if (d.details === "manifestLoadError" || d.details === "levelLoadError") {
      if (!code) return "refusé par le navigateur (CORS, certificat ou serveur absent)";
      if (code === 403 || code === 401) return `${code} : accès interdit (géo-blocage ou jeton)`;
      if (code === 404 || code === 410) return `${code} : flux introuvable`;
      return `${code} : erreur du serveur de la chaîne`;
    }
    if (d.details === "manifestParsingError") return "pas une liste HLS valide";
    if (d.details === "manifestLoadTimeOut" || d.details === "levelLoadTimeOut") return "liste trop lente à répondre";
    if (/frag/i.test(d.details)) return code ? `${code} : morceaux vidéo inaccessibles` : "morceaux vidéo refusés (CORS ou coupure)";
    if (/buffer|media/i.test(d.details) || d.type === "mediaError") return "format vidéo non lisible par le navigateur";
    if (/key/i.test(d.details)) return "flux chiffré (DRM)";
    return d.details;
  };

  function tester(url, delai = 15000) {
    return new Promise(resolve => {
      const t0 = performance.now();
      const v = document.createElement("video");
      v.muted = true; v.playsInline = true; v.autoplay = true;
      v.style.cssText = "position:fixed;width:16px;height:9px;left:-40px;top:0;opacity:0;pointer-events:none";
      document.body.appendChild(v);
      let h = null, fini = false, maxHauteur = 0;
      const finir = (ok, raison) => {
        if (fini) return;
        fini = true;
        clearTimeout(minuterie);
        const hauteur = v.videoHeight || 0;
        try { if (h) h.destroy(); } catch (e) { /* rien */ }
        v.removeAttribute("src"); try { v.load(); } catch (e) { /* rien */ }
        v.remove();
        resolve({ ok, raison, ms: Math.round(performance.now() - t0), hauteur, maxHauteur });
      };
      const minuterie = setTimeout(() => finir(false, "délai dépassé (15 s)"), delai);
      v.addEventListener("playing", () => setTimeout(() => {
        if (!v.videoHeight) finir(false, "son seulement (pas d'image)");
        else finir(true, "");
      }, 400));
      v.addEventListener("error", () => finir(false, "format non lisible (" + (v.error && v.error.code) + ")"));
      if (/\.mpd(\?|$)/i.test(url)) return finir(false, "format DASH non pris en charge par l'appli");
      if (window.Hls && window.Hls.isSupported() && !/\.(mp4|webm)(\?|$)/i.test(url)) {
        h = new window.Hls({ startLevel: 0, capLevelToPlayerSize: true, maxBufferLength: 4, maxMaxBufferLength: 6,
          manifestLoadingMaxRetry: 1, levelLoadingMaxRetry: 1, fragLoadingMaxRetry: 1, manifestLoadingTimeOut: 10000 });
        h.on(window.Hls.Events.MANIFEST_PARSED, (_, d) => { maxHauteur = Math.max(0, ...d.levels.map(l => l.height || 0)); });
        h.on(window.Hls.Events.ERROR, (_, d) => { if (d.fatal) finir(false, raisonHls(d)); });
        h.loadSource(url);
        h.attachMedia(v);
      } else {
        v.src = url;
      }
      v.play().catch(() => { /* lecture lancée par « autoplay » */ });
    });
  }

  window.__testerCandidats = async function (adresse, parallele = 4) {
    const candidats = await (await fetch(adresse, { cache: "no-store" })).json();
    window.__flux = { total: candidats.length, faits: 0, resultats: [], debut: Date.now() };
    let i = 0;
    async function ouvrier() {
      while (i < candidats.length) {
        const c = candidats[i++];
        const essais = [];
        let choisi = 0;
        for (let k = 0; k < c.flux.length; k++) {
          const r = await tester(c.flux[k].url);
          essais.push(r);
          if (r.ok) { choisi = k + 1; break; }
        }
        window.__flux.resultats.push({ n: c.n, nom: c.nom, choisi, essais });
        window.__flux.faits++;
      }
    }
    await Promise.all(Array.from({ length: parallele }, ouvrier));
    window.__flux.fin = Date.now();
    return window.__flux.resultats.filter(r => r.choisi).length;
  };
  window.__tester = tester;
})();
