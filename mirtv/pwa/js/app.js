/* MirTV — appli web (PWA) pour télé connectée, ordinateur et téléphone.
 * Parle directement à Jellyfin (même adresse que la page). Écrans et règles : docs/03-ux-spec.md du kit MirTV.
 * Navigation : 4 flèches, OK (Entrée), Retour (Échap, Retour arrière, touche Retour de la télécommande
 * ou du téléphone). Appui long sur Retour = accueil. Souris et doigt marchent aussi.
 */
(function () {
  "use strict";

  const CONFIG = window.MIRTV_CONFIG;
  const $app = document.getElementById("app");
  const CLE = "mirtv.";

  /* ===================== Textes ===================== */

  function langue() { return lire("langue") || "fr"; }
  function t(cle, ...args) {
    const T = window.MIRTV_TEXTES || { fr: {}, mg: {} };
    let s = (langue() === "mg" && T.mg[cle]) || T.fr[cle];
    if (s === undefined) { console.warn("[MirTV] texte manquant", cle); s = ""; }
    return s.replace(/%(\d)\$(0?\d*)([sd])/g, (_, n, pad, type) => {
      let v = args[Number(n) - 1];
      if (v === undefined) return "";
      if (type === "d" && pad) v = String(v).padStart(Number(pad), "0");
      return String(v);
    });
  }

  /* ===================== Petites aides ===================== */

  function lire(cle) { try { const v = localStorage.getItem(CLE + cle); return v === null ? null : JSON.parse(v); } catch (e) { return null; } }
  function ecrire(cle, v) { try { localStorage.setItem(CLE + cle, JSON.stringify(v)); } catch (e) { /* stockage indisponible */ } }
  function effacer(cle) { try { localStorage.removeItem(CLE + cle); } catch (e) { /* rien */ } }
  function journal(...a) { console.log("[MirTV]", ...a); }
  function echap(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])); }
  function el(html) { const d = document.createElement("div"); d.innerHTML = html.trim(); return d.firstElementChild; }
  const attendre = ms => new Promise(r => setTimeout(r, ms));
  function heureCourte(d) { return d.toLocaleTimeString(langue() === "mg" ? "mg-MG" : "fr-FR", { hour: "2-digit", minute: "2-digit" }); }

  function appareilId() {
    let id = lire("appareil");
    if (!id) {
      id = "web-" + (crypto.randomUUID ? crypto.randomUUID() : (Date.now().toString(36) + Math.random().toString(36).slice(2)));
      ecrire("appareil", id);
    }
    return id;
  }
  function numeroEcran() { return appareilId().replace(/[^a-z0-9]/gi, "").slice(-6).toUpperCase(); }
  // Nom de l'appareil envoyé au serveur : lettres sans accent seulement (un « é » fait refuser la demande).
  function nomAppareil() {
    const ua = navigator.userAgent;
    if (/Android/i.test(ua) && /TV|AFT|BRAVIA|SMART/i.test(ua)) return "Tele Android";
    if (/Tizen|Web0S|webOS|SmartTV/i.test(ua)) return "Tele connectee";
    if (/Android/i.test(ua)) return "Telephone Android";
    if (/iPhone|iPad/i.test(ua)) return "iPhone";
    return "Navigateur";
  }
  const ascii = v => String(v).normalize("NFD").replace(/[^\x20-\x7E]/g, "").replace(/"/g, "");

  /* ===================== Profils ===================== */

  const FAMILLE = /^f\d+-(.+)$/i;
  function reste(nom) { const m = FAMILLE.exec((nom || "").trim()); return m ? m[1] : null; }
  function estEnfant(nom) { const r = reste(nom); return r !== null && r.toLowerCase() !== "parent"; }
  function estParent(nom) { const r = reste(nom); return r === null ? true : r.toLowerCase() === "parent"; }
  function famille(nom) { const m = /^(f\d+)-/i.exec((nom || "").trim()); return m ? m[1].toLowerCase() : "sans-famille"; }
  function prenom(nom) {
    const r = reste(nom);
    if (r === null) return cap(nom.trim());
    return r.toLowerCase() === "parent" ? "Parent" : cap(r);
  }
  function cap(s) { return s ? s.charAt(0).toUpperCase() + s.slice(1) : s; }

  function profils() { return lire("profils") || []; }
  function enregistrerProfil(p) {
    const l = profils().filter(x => x.id !== p.id);
    l.push(p);
    ecrire("profils", l.slice(-6));
  }
  let profil = null; // profil ouvert : { id, nom, jeton }

  /* ===================== Animaux (images de profil originales) ===================== */

  const ANIMAUX = [
    { couleur: "#FF9F1C", oreilles: "rondes" }, { couleur: "#5BE37D", oreilles: "aucune" },
    { couleur: "#22D3C5", oreilles: "rondes" }, { couleur: "#FFD93D", oreilles: "cornes" },
    { couleur: "#FF5C8A", oreilles: "longues" }, { couleur: "#A98BFF", oreilles: "pointues" }];
  function animal(i) {
    const a = ANIMAUX[((i % 6) + 6) % 6], c = a.couleur, e = "#120E36";
    let o = "";
    if (a.oreilles === "rondes") o = `<circle cx="22" cy="20" r="14" fill="${c}"/><circle cx="78" cy="20" r="14" fill="${c}"/>`;
    if (a.oreilles === "pointues") o = `<path d="M4 34 L18 4 L32 34Z" fill="${c}"/><path d="M68 34 L82 4 L96 34Z" fill="${c}"/>`;
    if (a.oreilles === "longues") o = `<ellipse cx="31" cy="23" rx="9" ry="23" fill="${c}"/><ellipse cx="69" cy="23" rx="9" ry="23" fill="${c}"/>`;
    if (a.oreilles === "cornes") o = `<circle cx="20" cy="20" r="7" fill="${e}"/><circle cx="80" cy="20" r="7" fill="${e}"/>`;
    return `<svg viewBox="0 0 100 100" aria-hidden="true">${o}<circle cx="50" cy="56" r="38" fill="${c}"/>` +
      `<circle cx="37" cy="50" r="5" fill="${e}"/><circle cx="63" cy="50" r="5" fill="${e}"/>` +
      `<ellipse cx="50" cy="66" rx="9" ry="6" fill="${e}" opacity="0.85"/><path d="M40 76 Q50 84 60 76" stroke="${e}" stroke-width="3" fill="none" stroke-linecap="round"/></svg>`;
  }
  function indexProfil(id) { const i = profils().findIndex(p => p.id === id); return i < 0 ? 0 : i; }

  /* ===================== Pictogrammes ===================== */

  const P = {
    tele: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="12" width="38" height="26" rx="4"/><path d="M17 4l7 8 7-8"/><path d="M14 44h20"/></svg>',
    reprendre: '<svg viewBox="0 0 48 48" fill="currentColor"><path d="M14 8l26 16-26 16z"/></svg>',
    films: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"><rect x="6" y="8" width="36" height="32" rx="4"/><path d="M6 18h36M6 30h36M16 8v32M32 8v32"/></svg>',
    favoris: '<svg viewBox="0 0 48 48" fill="currentColor"><path d="M24 42S6 31 6 18a9 9 0 0118-3 9 9 0 0118 3c0 13-18 24-18 24z"/></svg>',
    aide: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><circle cx="24" cy="24" r="18"/><path d="M18 19a6 6 0 1111 3c-2 2-5 3-5 7"/><circle cx="24" cy="36" r="1.5" fill="currentColor"/></svg>',
    retour: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"><path d="M20 12L8 24l12 12M8 24h32"/></svg>',
    cadenas: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"><rect x="10" y="22" width="28" height="20" rx="4"/><path d="M16 22v-6a8 8 0 0116 0v6"/></svg>',
    dessins: '<svg viewBox="0 0 48 48" fill="currentColor"><circle cx="24" cy="26" r="16"/><circle cx="12" cy="10" r="7"/><circle cx="36" cy="10" r="7"/></svg>',
    soustitres: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><rect x="5" y="10" width="38" height="28" rx="4"/><path d="M12 30h10M26 30h10M12 22h24"/></svg>',
    programme: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><path d="M10 12h28M10 24h28M10 36h18"/></svg>',
    internet: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><path d="M6 18a26 26 0 0136 0M12 25a17 17 0 0124 0M18 32a8 8 0 0112 0"/><circle cx="24" cy="39" r="2" fill="currentColor"/></svg>',
    message: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round"><path d="M8 10h32v22H20l-8 7v-7H8z"/></svg>',
    numero: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linecap="round"><path d="M18 8l-4 32M34 8l-4 32M8 18h32M6 30h32"/></svg>',
    maison: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"><path d="M6 22L24 7l18 15"/><path d="M11 19v22h26V19"/><path d="M20 41V29h8v12"/></svg>',
    reglages: '<svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="4"><circle cx="24" cy="24" r="7"/><path d="M24 4v7M24 37v7M4 24h7M37 24h7M10 10l5 5M33 33l5 5M10 38l5-5M33 15l5-5"/></svg>'
  };

  /* ===================== Jellyfin ===================== */

  let decalageServeurMs = 0; // heure du serveur − heure de l'appareil
  function heureServeur() { return new Date(Date.now() + decalageServeurMs); }

  class ErreurApi extends Error {
    constructor(statut, texte) { super("HTTP " + statut + " " + (texte || "").slice(0, 200)); this.statut = statut; this.texte = texte || ""; }
    get tropDEcrans() { return /maximum number of sessions|max.*session|too many/i.test(this.texte) || this.statut === 429; }
  }

  function enteteAuth(jeton) {
    let h = `MediaBrowser Client="MirTV Web", Device="${ascii(nomAppareil())}", DeviceId="${ascii(appareilId())}", Version="${ascii(CONFIG.version)}"`;
    if (jeton) h += `, Token="${jeton}"`;
    return h;
  }

  async function api(chemin, options = {}) {
    const jeton = options.jeton !== undefined ? options.jeton : (profil && profil.jeton);
    const init = { method: options.method || "GET", headers: { "Authorization": enteteAuth(jeton), "Accept": "application/json" } };
    if (options.corps !== undefined) { init.headers["Content-Type"] = "application/json"; init.body = JSON.stringify(options.corps); }
    let r;
    try { r = await fetch(CONFIG.serveur + chemin, init); }
    catch (e) { journal("réseau indisponible", chemin, e); throw new ErreurApi(0, "reseau"); }
    const date = r.headers.get("Date");
    if (date) { const d = Date.parse(date); if (!isNaN(d)) decalageServeurMs = d - Date.now(); }
    if (!r.ok) {
      const texte = await r.text().catch(() => "");
      journal("réponse refusée", chemin, r.status, texte.slice(0, 300));
      throw new ErreurApi(r.status, texte);
    }
    if (r.status === 204) return null;
    const type = r.headers.get("Content-Type") || "";
    return type.includes("json") ? r.json() : r.text();
  }

  function urlImage(id, type = "Primary", hauteur = 480) {
    return `${CONFIG.serveur}/Items/${id}/Images/${type}?maxHeight=${hauteur}&quality=85`;
  }

  /* ===================== Navigation à la télécommande ===================== */

  function visibles() {
    return [...$app.querySelectorAll(".nav")].filter(e => {
      if (e.disabled || e.closest("[hidden]")) return false;
      const r = e.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    });
  }

  function deplacer(dir) {
    const liste = visibles();
    if (!liste.length) return false;
    const actif = document.activeElement;
    if (!actif || !liste.includes(actif)) { liste[0].focus(); return true; }
    const a = actif.getBoundingClientRect();
    const ac = { x: a.left + a.width / 2, y: a.top + a.height / 2 };
    let meilleur = null, score = Infinity;
    for (const e of liste) {
      if (e === actif) continue;
      const r = e.getBoundingClientRect();
      const c = { x: r.left + r.width / 2, y: r.top + r.height / 2 };
      const dx = c.x - ac.x, dy = c.y - ac.y;
      let principal, lateral;
      if (dir === "droite") { if (r.left < a.right - 2 && dx <= 1) continue; principal = dx; lateral = Math.abs(dy); }
      else if (dir === "gauche") { if (r.right > a.left + 2 && dx >= -1) continue; principal = -dx; lateral = Math.abs(dy); }
      else if (dir === "bas") { if (r.top < a.bottom - 2 && dy <= 1) continue; principal = dy; lateral = Math.abs(dx); }
      else { if (r.bottom > a.top + 2 && dy >= -1) continue; principal = -dy; lateral = Math.abs(dx); }
      if (principal <= 0) continue;
      const s = principal + lateral * 2.2 + Math.abs(r.top - a.top) * 0.05;
      if (s < score) { score = s; meilleur = e; }
    }
    if (meilleur) {
      meilleur.focus({ preventScroll: true });
      meilleur.scrollIntoView({ block: "nearest", inline: "nearest", behavior: "smooth" });
      return true;
    }
    return false;
  }

  function focaliser(selecteurOuElement) {
    const e = typeof selecteurOuElement === "string" ? $app.querySelector(selecteurOuElement) : selecteurOuElement;
    const v = visibles();
    const cible = e || v.find(x => !x.closest(".barre")) || v[0];
    if (cible) { cible.focus({ preventScroll: true }); cible.scrollIntoView({ block: "nearest", inline: "nearest" }); }
  }

  /* ===================== Pile d'écrans ===================== */

  const pile = []; // { nom, ouvrir(), fermer?, touche?, retour?, focus? }
  let ecranCourant = null;

  function montrer(ecran, remplacer = false) {
    if (ecranCourant && ecranCourant.fermer) { try { ecranCourant.fermer(); } catch (e) { journal(e); } }
    if (ecranCourant && !remplacer) {
      const a = document.activeElement;
      ecranCourant.focus = a && a.dataset && a.dataset.cle ? a.dataset.cle : null;
      pile.push(ecranCourant);
    }
    ecranCourant = ecran;
    rendre(ecran);
  }

  function rendre(ecran) {
    $app.innerHTML = "";
    viderFond();
    Promise.resolve(ecran.ouvrir()).then(() => {
      if (ecranCourant !== ecran) return;
      if (ecran.focus) focaliser(`[data-cle="${CSS.escape(ecran.focus)}"]`);
      else if (!$app.contains(document.activeElement) || document.activeElement === document.body) focaliser();
    }).catch(e => erreurGenerale(e));
  }

  function reculer() {
    if (ecranCourant && ecranCourant.retour) { if (ecranCourant.retour() !== false) return; }
    if (!pile.length) return;
    if (ecranCourant.fermer) { try { ecranCourant.fermer(); } catch (e) { journal(e); } }
    ecranCourant = pile.pop();
    rendre(ecranCourant);
  }

  function viderPile() { pile.length = 0; }

  function allerAccueil() {
    if (!profil) return montrer(ecranQuiRegarde(), true);
    viderPile();
    if (ecranCourant && ecranCourant.fermer) { try { ecranCourant.fermer(); } catch (e) { journal(e); } }
    ecranCourant = null;
    montrer(estEnfant(profil.nom) ? ecranAccueilEnfants() : ecranAccueil(), true);
  }

  $app.addEventListener("click", e => { if (e.target.closest("[data-accueil]")) { e.preventDefault(); e.stopPropagation(); allerAccueil(); } }, true);

  /* ===================== Touches ===================== */

  const TOUCHES_RETOUR = ["Escape", "Backspace", "GoBack", "BrowserBack"];
  let minuterieRetour = null, retourLong = false;

  function estRetour(e) { return TOUCHES_RETOUR.includes(e.key) || e.keyCode === 10009 || e.keyCode === 461 || e.keyCode === 8; }

  document.addEventListener("keydown", e => {
    const champ = e.target && e.target.tagName === "INPUT";
    if (estRetour(e)) {
      if (champ && e.key === "Backspace") return;
      e.preventDefault();
      if (e.repeat) return;
      retourLong = false;
      clearTimeout(minuterieRetour);
      minuterieRetour = setTimeout(() => { retourLong = true; appuiLongRetour(); }, 800);
      return;
    }
    if (ecranCourant && ecranCourant.touche && ecranCourant.touche(e) === true) { e.preventDefault(); return; }
    const dirs = { ArrowUp: "haut", ArrowDown: "bas", ArrowLeft: "gauche", ArrowRight: "droite" };
    if (dirs[e.key]) {
      if (champ && (e.key === "ArrowLeft" || e.key === "ArrowRight")) return;
      e.preventDefault();
      deplacer(dirs[e.key]);
      return;
    }
    if ((e.key === "Enter" || e.keyCode === 13) && document.activeElement && document.activeElement.classList.contains("nav") && document.activeElement.tagName !== "BUTTON" && !champ) {
      e.preventDefault(); document.activeElement.click();
    }
  });
  document.addEventListener("keyup", e => {
    if (!estRetour(e)) return;
    const champ = e.target && e.target.tagName === "INPUT";
    if (champ && e.key === "Backspace") return;
    clearTimeout(minuterieRetour);
    if (!retourLong) reculer();
    retourLong = false;
  });

  function appuiLongRetour() {
    if (ecranCourant && ecranCourant.retourLong) { ecranCourant.retourLong(); return; }
    if (!profil) return;
    if (estEnfant(profil.nom)) { allerAccueil(); return; } // reste dans l'espace enfants
    allerAccueil();
  }

  // Touche Retour du téléphone ou du navigateur : une page piège garde l'appli ouverte
  function pieger() { try { history.pushState({ mirtv: Date.now() }, ""); } catch (e) { /* rien */ } }
  window.addEventListener("popstate", () => { pieger(); reculer(); });

  /* ===================== Gabarits communs ===================== */

  const SANS_ACCUEIL = ["accueil", "accueil-enfants", "qui-regarde", "pause", "code-parent", "connexion", "mot-de-passe"];
  function boutonAccueil() {
    if (!profil || (ecranCourant && SANS_ACCUEIL.includes(ecranCourant.nom))) return "";
    return `<button class="bouton bouton-accueil nav" data-accueil data-cle="accueil-btn" aria-label="${echap(t("bouton_accueil"))}">${P.maison}<span class="libelle">${echap(t("bouton_accueil"))}</span></button>`;
  }
  function barre(titre, avecProfil = true) {
    const p = avecProfil && profil ? `<span class="pastille-profil nav" tabindex="0" data-cle="pastille" aria-label="${echap(prenom(profil.nom))}">${animal(indexProfil(profil.id))}${echap(prenom(profil.nom))}</span>` : "";
    return `<header class="barre"><span class="logo" aria-label="MirTV"><span class="logo-mir">Mir</span><span class="logo-tv">TV</span></span><h1>${echap(titre)}</h1>${boutonAccueil()}<span class="heure" data-heure>${heureCourte(new Date())}</span>${p}</header>`;
  }
  function indices(liste) { return `<footer class="indices">${liste.map(c => `<span>${echap(t(c))}</span>`).join("")}</footer>`; }
  setInterval(() => document.querySelectorAll("[data-heure]").forEach(h => { h.textContent = heureCourte(new Date()); }), 20000);

  function ecranMessage({ titre, message, boutons = [], enfants = false, dessin = "" }) {
    return {
      nom: "message",
      ouvrir() {
        const e = el(`<section class="ecran ecran-centre ${enfants ? "enfants" : ""}">
          ${dessin}${titre ? `<p class="message grand">${echap(titre)}</p>` : ""}${message ? `<p class="message">${echap(message)}</p>` : ""}
          <div class="boutons"></div></section>`);
        const zone = e.querySelector(".boutons");
        boutons.forEach((b, i) => {
          const btn = el(`<button class="bouton nav ${i === 0 ? "principal" : ""}" data-cle="b${i}">${b.picto ? P[b.picto] : ""}${echap(b.texte)}</button>`);
          btn.addEventListener("click", b.action);
          zone.appendChild(btn);
        });
        $app.appendChild(e);
      }
    };
  }

  function erreurGenerale(e) {
    journal("erreur", e);
    if (e instanceof ErreurApi && e.statut === 0) return montrer(ecranPasInternet(), true);
    if (e instanceof ErreurApi && e.tropDEcrans) return montrer(ecranTropDEcrans(), true);
    if (e instanceof ErreurApi && e.statut === 401 && profil) {
      if (estEnfant(profil.nom)) return montrer(ecranPause(), true); // en dehors de la plage horaire
      return montrer(ecranQuiRegarde(), true);
    }
    montrer(ecranMessage({ message: t("aide_image_consigne"), boutons: [{ texte: t("bouton_reessayer"), action: () => allerAccueil() }] }), true);
  }

  function ecranPasInternet() {
    return ecranMessage({
      dessin: `<div style="width:8rem;height:8rem;color:var(--ciel)">${P.internet}</div>`,
      titre: t("pas_internet_titre"), message: t("pas_internet_consigne"),
      boutons: [{ texte: t("bouton_reessayer"), action: () => demarrer() }]
    });
  }

  function ecranTropDEcrans() {
    return ecranMessage({
      titre: t("erreur_trop_ecrans", CONFIG.limiteEcrans),
      boutons: [{ texte: t("bouton_voir_ecrans"), action: () => montrer(ecranMesEcrans()) }, { texte: t("bouton_reessayer"), action: () => demarrer() }]
    });
  }

  function ecranMesEcrans() {
    return {
      nom: "mes-ecrans",
      async ouvrir() {
        const e = el(`<section class="ecran">${barre(t("mes_ecrans_titre"))}<div class="defile"><div class="liste-reglages"></div></div>${indices(["indice_retour"])}</section>`);
        $app.appendChild(e);
        const zone = e.querySelector(".liste-reglages");
        let sessions = [];
        // Sans profil ouvert (connexion refusée), on utilise un profil déjà mémorisé sur cet écran
        const jeton = profil ? profil.jeton : (profils()[0] || {}).jeton;
        if (jeton) { try { sessions = (await api("/Sessions?ActiveWithinSeconds=960", { jeton })) || []; } catch (err) { journal(err); } }
        sessions = sessions.filter(s => s.DeviceId !== appareilId());
        if (!sessions.length) { zone.appendChild(el(`<p class="message">${echap(t("mes_ecrans_vide"))}</p>`)); return; }
        sessions.forEach((s, i) => {
          const ligne = el(`<div class="bouton" style="justify-content:space-between"><span>${echap(s.DeviceName || "?")} · ${echap(s.UserName ? prenom(s.UserName) : "")}</span></div>`);
          if (profil && estParent(profil.nom)) {
            const b = el(`<button class="bouton principal nav" data-cle="s${i}">${echap(t("bouton_fermer_ecran"))}</button>`);
            b.addEventListener("click", async () => {
              try { await api(`/Sessions/${s.Id}/Playing/Stop`, { method: "POST" }); } catch (err) { journal(err); }
              try { await api(`/Sessions/${s.Id}/Command/DisplayMessage`, { method: "POST", corps: { Header: "MirTV", Text: "", TimeoutMs: 1000 } }); } catch (err) { /* facultatif */ }
              ligne.remove();
            });
            ligne.appendChild(b);
          }
          zone.appendChild(ligne);
        });
      }
    };
  }

  /* ===================== Qui regarde ? ===================== */

  function ecranQuiRegarde() {
    return {
      nom: "qui-regarde",
      retour() { return true; }, // pas de sortie
      ouvrir() {
        profil = null; effacer("ouvert");
        const liste = profils();
        const e = el(`<section class="ecran">${barre(t("qui_regarde_titre"), false)}
          ${liste.length ? "" : `<p class="message" style="text-align:center">${echap(t("qui_regarde_aucun"))}</p>`}
          <div class="profils"></div>${indices(["indice_fleches_choisir", "indice_ok_ouvrir"])}</section>`);
        const zone = e.querySelector(".profils");
        liste.forEach((p, i) => {
          const b = el(`<button class="profil nav" data-cle="p-${p.id}" aria-label="${echap(t("profil_description", prenom(p.nom)) || prenom(p.nom))}">
            ${animal(i)}<span class="prenom">${echap(prenom(p.nom))}</span>${estEnfant(p.nom) ? `<span class="pastille-enfants">${echap(t("pastille_enfants"))}</span>` : ""}</button>`);
          b.addEventListener("click", () => ouvrirProfil(p));
          zone.appendChild(b);
        });
        if (liste.length < 6) {
          const plus = el(`<button class="profil nav" data-cle="se-connecter"><span class="plus">+</span><span class="prenom">${echap(t("qui_regarde_se_connecter"))}</span></button>`);
          plus.addEventListener("click", () => montrer(ecranConnexion()));
          zone.appendChild(plus);
        }
        $app.appendChild(e);
        const dernier = lire("dernierProfil");
        focaliser(dernier ? `[data-cle="p-${CSS.escape(dernier)}"]` : null);
      }
    };
  }

  async function ouvrirProfil(p) {
    profil = p;
    ecrire("dernierProfil", p.id);
    try {
      const u = await api(`/Users/${p.id}`);
      profil.politique = u.Policy || {};
      profil.nom = u.Name || p.nom;
    } catch (e) {
      if (e instanceof ErreurApi && e.tropDEcrans) return montrer(ecranTropDEcrans());
      if (e instanceof ErreurApi && e.statut === 401) {
        if (estEnfant(p.nom)) { ecrire("ouvert", p.id); return montrer(ecranPause(), true); }
        // jeton expiré : on se reconnecte
        ecrire("profils", profils().filter(x => x.id !== p.id));
        profil = null;
        return montrer(ecranConnexion());
      }
      if (e instanceof ErreurApi && e.statut === 0) return montrer(ecranPasInternet());
      journal(e);
    }
    ecrire("ouvert", p.id);
    allerAccueil();
  }

  /* ===================== Connexion ===================== */

  function ecranConnexion() {
    let arret = false;
    return {
      nom: "connexion",
      fermer() { arret = true; },
      async ouvrir() {
        const e = el(`<section class="ecran ecran-centre">${barre(t("connexion_titre"), false)}
          <div style="flex:1;display:flex;flex-direction:column;justify-content:center;width:100%">
            <div data-rapide hidden><p class="message">${echap(t("connexion_code_consigne"))}</p><div class="code-rapide" data-code></div><p class="petit" data-attente>${echap(t("connexion_code_attente"))}</p></div>
            <div class="boutons"><button class="bouton nav" data-cle="mdp">${echap(t("connexion_mot_de_passe_lien"))}</button><button class="bouton nav" data-cle="annuler">${echap(t("bouton_annuler"))}</button></div>
          </div>${indices(["indice_retour"])}</section>`);
        e.querySelector('[data-cle="mdp"]').addEventListener("click", () => montrer(ecranMotDePasse(), true));
        e.querySelector('[data-cle="annuler"]').addEventListener("click", () => reculer());
        $app.appendChild(e);
        focaliser('[data-cle="mdp"]');
        try {
          const actif = await api("/QuickConnect/Enabled", { jeton: null });
          if (actif !== true && actif !== "true") throw new Error("connexion rapide coupée");
          const debut = await api("/QuickConnect/Initiate", { method: "POST", jeton: null });
          e.querySelector("[data-code]").textContent = debut.Code;
          e.querySelector("[data-rapide]").hidden = false;
          while (!arret) {
            await attendre(4000);
            if (arret) return;
            const etat = await api(`/QuickConnect/Connect?secret=${encodeURIComponent(debut.Secret)}`, { jeton: null });
            if (etat && etat.Authenticated) {
              const res = await api("/Users/AuthenticateWithQuickConnect", { method: "POST", jeton: null, corps: { Secret: debut.Secret } });
              return connecte(res);
            }
          }
        } catch (err) {
          journal("connexion rapide indisponible", err);
          if (err instanceof ErreurApi && err.tropDEcrans) return montrer(ecranTropDEcrans(), true);
          if (!arret && ecranCourant && ecranCourant.nom === "connexion") montrer(ecranMotDePasse(), true);
        }
      }
    };
  }

  function ecranMotDePasse() {
    return {
      nom: "mot-de-passe",
      ouvrir() {
        const e = el(`<section class="ecran ecran-centre">${barre(t("connexion_titre"), false)}
          <form style="flex:1;display:flex;flex-direction:column;justify-content:center;width:100%" autocomplete="on">
            <label class="champ">${echap(t("connexion_nom"))}<input class="nav" data-cle="nom" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" required></label>
            <label class="champ">${echap(t("connexion_mot_de_passe"))}<input class="nav" data-cle="mdp" name="password" type="password" autocomplete="current-password" autocapitalize="none" autocorrect="off" spellcheck="false"></label>
            <label class="champ" style="flex-direction:row;align-items:center;gap:0.6rem"><input type="checkbox" class="nav" data-cle="voir" style="width:1.4rem;height:1.4rem;min-height:0"> ${echap(t("connexion_voir_mot_de_passe"))}</label>
            <p class="message" data-erreur hidden></p>
            <div class="boutons"><button class="bouton principal nav" data-cle="ok" type="submit">${echap(t("bouton_se_connecter") || t("connexion_titre"))}</button><button class="bouton nav" type="button" data-cle="annuler">${echap(t("bouton_annuler"))}</button></div>
          </form>${indices(["indice_retour"])}</section>`);
        const f = e.querySelector("form");
        e.querySelector('[data-cle="annuler"]').addEventListener("click", () => reculer());
        e.querySelector('[data-cle="voir"]').addEventListener("change", ev => { f.password.type = ev.target.checked ? "text" : "password"; });
        f.addEventListener("submit", async ev => {
          ev.preventDefault();
          const zone = e.querySelector("[data-erreur]");
          zone.hidden = true;
          try {
            const res = await api("/Users/AuthenticateByName", { method: "POST", jeton: null, corps: { Username: nettoyer(f.username.value, true), Pw: nettoyer(f.password.value, false) } });
            connecte(res);
          } catch (err) {
            if (err instanceof ErreurApi && err.tropDEcrans) return montrer(ecranTropDEcrans());
            const s = err instanceof ErreurApi ? err.statut : -1;
            zone.textContent = s === 0 ? t("pas_internet_titre") : (s === 401 || s === 403) ? t("erreur_connexion") : t("aide_image_consigne");
            zone.hidden = false;
          }
        });
        $app.appendChild(e);
        focaliser('[data-cle="nom"]');
      }
    };
  }

  // Nettoie un nom ou un mot de passe collé depuis un document ou une messagerie :
  // caractères invisibles, espaces insécables, tirets spéciaux, « \par » d'un fichier RTF, étiquette « Utilisateur : ».
  function nettoyer(v, estNom) {
    let s = String(v || "").normalize("NFKC");
    s = s.replace(/[\u200B-\u200D\u2060\uFEFF\u00AD]/g, "");
    s = s.replace(/[\u2010-\u2015\u2212\uFE58\uFE63\uFF0D]/g, "-");
    s = s.replace(/\\par\b/g, "");
    if (estNom) s = s.replace(/^\s*(utilisateur|nom du compte|nom)\s*:\s*/i, "").toLowerCase();
    else s = s.replace(/^\s*mot de passe\s*:\s*/i, "");
    return s.replace(/^\s+|\s+$/g, "");
  }

  function connecte(res) {
    const p = { id: res.User.Id, nom: res.User.Name, jeton: res.AccessToken };
    enregistrerProfil(p);
    viderPile();
    ecranCourant = null;
    ouvrirProfil(p);
  }

  /* ===================== Accueil adulte ===================== */

  let invitationInstallation = null;
  window.addEventListener("beforeinstallprompt", e => { e.preventDefault(); invitationInstallation = e; });

  // Fond d'ambiance : l'image de l'élément sélectionné, floutée, derrière l'écran (esprit Apple TV)
  const $fond = (() => {
    const f = document.createElement("div");
    f.id = "fond"; f.innerHTML = '<div class="calque"></div><div class="calque"></div><div class="assombrir"></div>';
    document.body.insertBefore(f, document.body.firstChild);
    return f;
  })();
  let calqueActif = 0, dernierFond = "";
  function changerFond(url) {
    if (!url || url === dernierFond) return;
    dernierFond = url;
    const calques = $fond.querySelectorAll(".calque");
    const suivant = calques[1 - calqueActif];
    const img = new Image();
    img.onload = () => {
      if (dernierFond !== url) return;
      suivant.style.backgroundImage = `url("${url}")`;
      suivant.classList.add("visible"); calques[calqueActif].classList.remove("visible");
      calqueActif = 1 - calqueActif;
    };
    img.src = url;
  }
  function viderFond() { dernierFond = ""; $fond.querySelectorAll(".calque").forEach(c => c.classList.remove("visible")); }
  document.addEventListener("focusin", e => {
    const ecran = e.target.closest && e.target.closest(".ecran");
    if (!ecran || !ecran.classList.contains("ambiance")) return;
    const f = e.target.dataset && e.target.dataset.fond;
    if (f) changerFond(f);
  });

  // Logos des chaînes (copiés sur notre serveur par mirtv/serveur/logos-mirtv.py)
  let promesseLogos = null;
  function chargerLogos() {
    if (!promesseLogos) promesseLogos = fetch("logos/logos.json", { cache: "no-cache" }).then(r => (r.ok ? r.json() : {})).catch(() => ({}));
    return promesseLogos;
  }
  const cleLogo = n => (n || "").normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().replace(/[^a-z0-9]+/g, "");
  function logoDe(c, logos) {
    if (!logos) return null;
    if (!logos.__n) Object.defineProperty(logos, "__n", { value: Object.fromEntries(Object.entries(logos).map(([k, v]) => [cleLogo(k), v])) });
    const f = logos[c.Name] || logos.__n[cleLogo(c.Name)];
    return f ? "logos/" + encodeURIComponent(f) : null;
  }
  const initiales = nom => (nom || "").replace(/\(.*?\)/g, "").split(/\s+/).filter(Boolean).map(m => m[0]).join("").slice(0, 3).toUpperCase();
  function progression(p) {
    const d = new Date(p.StartDate), f = new Date(p.EndDate), n = heureServeur();
    if (isNaN(d) || isNaN(f) || f <= d) return null;
    return Math.max(0, Math.min(100, (n - d) / (f - d) * 100));
  }
  function imageProgramme(c, h = 360) {
    const p = c.CurrentProgram;
    return progReel(p, c) && p.ImageTags && p.ImageTags.Primary ? urlImage(p.Id, "Primary", h) : null;
  }

  function tuileImage(cle, texte, sous, picto, teinte, art, principale = false) {
    return `<button class="tuile tuile-image nav ${principale ? "principale" : ""}" data-cle="${cle}" style="--teinte:${teinte}">
      <span class="visuel ${art}"></span><span class="voile"></span>
      <span class="icone">${picto}</span>
      <span class="texte"><span class="titre">${echap(texte)}</span>${sous ? `<span class="sous" data-sous>${echap(sous)}</span>` : ""}</span></button>`;
  }
  function poserImages(tuile, urls, delai = 6000) {
    // une ou plusieurs images qui se relaient en fondu dans une tuile
    const zone = tuile && tuile.querySelector(".visuel");
    if (!urls.length || !zone) return null;
    zone.insertAdjacentHTML("beforeend", urls.map((u, i) => `<img src="${u}" alt="" class="photo ${i === 0 ? "visible" : ""}" loading="lazy">`).join(""));
    zone.querySelectorAll("img.photo").forEach(im => im.addEventListener("error", () => im.remove()));
    zone.classList.add("avec-images");
    tuile.dataset.fond = urls[0];
    if (urls.length < 2) return null;
    let i = 0;
    return setInterval(() => {
      const imgs = zone.querySelectorAll("img.photo"); if (imgs.length < 2) return;
      imgs[i % imgs.length].classList.remove("visible"); i++;
      const im = imgs[i % imgs.length]; im.classList.add("visible");
      tuile.dataset.fond = im.src;
      if (document.activeElement === tuile) changerFond(im.src);
    }, delai);
  }

  function ecranAccueil() {
    const minuteries = [];
    return {
      nom: "accueil",
      retour() { montrer(ecranQuiRegarde(), true); viderPile(); return true; },
      retourLong() { if (estParent(profil.nom)) demanderCodeParent(() => montrer(ecranReglages(), true), () => {}); },
      fermer() { minuteries.forEach(m => m && clearInterval(m)); },
      ouvrir() {
        const e = el(`<section class="ecran ambiance">${barre(t("accueil_titre"))}
          <div class="accueil-grille v2">
            ${tuileImage("tele", t("tuile_tele"), t("tuile_tele_sous_titre"), P.tele, "var(--mangue)", "art-tele", true)}
            ${tuileImage("reprendre", t("tuile_reprendre"), "", P.reprendre, "var(--corail)", "art-reprendre")}
            ${tuileImage("films", t("tuile_films"), "", P.films, "var(--lagon)", "art-films")}
            ${tuileImage("favoris", t("tuile_favoris"), "", P.favoris, "var(--lilas)", "art-favoris")}
            ${tuileImage("aide", t("tuile_aide"), "", P.aide, "var(--citron)", "art-aide")}
          </div>
          <section class="rangee-direct" hidden><h2><span class="point-direct"></span>${echap(t("accueil_en_direct"))}</h2><div class="cartes-direct"></div></section>
          <div class="boutons" data-installer hidden style="margin-top:0"><button class="bouton nav" data-cle="installer">${echap(t("installer_appli"))}</button></div>
          ${indices(["indice_fleches_choisir", "indice_ok_ouvrir"])}</section>`);
        const go = (cle, f) => e.querySelector(`[data-cle="${cle}"]`).addEventListener("click", f);
        go("tele", () => montrer(ecranTele()));
        go("reprendre", () => montrer(ecranReprendre()));
        go("films", () => montrer(ecranFilms()));
        go("favoris", () => montrer(ecranFavoris()));
        go("aide", () => montrer(ecranAide()));
        if (invitationInstallation) {
          e.querySelector("[data-installer]").hidden = false;
          go("installer", async () => { invitationInstallation.prompt(); try { await invitationInstallation.userChoice; } catch (x) { /* rien */ } invitationInstallation = null; e.querySelector("[data-installer]").hidden = true; });
        }
        // Réglages parent : appui long sur la pastille (téléphone) ou appui long sur Retour (télécommande)
        appuiLong(e.querySelector('[data-cle="pastille"]'), () => { if (estParent(profil.nom)) demanderCodeParent(() => montrer(ecranReglages(), true), () => {}); });
        $app.appendChild(e);
        focaliser('[data-cle="tele"]');
        this.enrichir(e).catch(err => journal("accueil : images", err));
      },
      async enrichir(e) {
        const tuile = cle => e.querySelector(`[data-cle="${cle}"]`);
        const [chaines, logos] = await Promise.all([chargerChaines().catch(() => []), chargerLogos()]);
        if (!e.isConnected) return;
        // Télé en direct : les programmes en cours se relaient
        const avecImage = chaines.filter(c => imageProgramme(c));
        minuteries.push(poserImages(tuile("tele"), avecImage.slice(0, 8).map(c => imageProgramme(c, 720))));
        const vedette = avecImage[0] || chaines.find(c => progReel(c.CurrentProgram, c));
        const sous = tuile("tele").querySelector("[data-sous]");
        if (vedette && sous) sous.textContent = t("tuile_tele_maintenant", vedette.Name, vedette.CurrentProgram.Name);
        // Rangée « En direct maintenant »
        const triees = [...avecImage, ...chaines.filter(c => !imageProgramme(c) && progReel(c.CurrentProgram, c)), ...chaines.filter(c => !progReel(c.CurrentProgram, c))];
        const rangee = e.querySelector(".rangee-direct"), zone = e.querySelector(".cartes-direct");
        triees.slice(0, 14).forEach((c, i) => {
          const b = carteChaine(c, i, "d-" + c.Id, logos);
          b.addEventListener("click", () => montrer(ecranLecteurChaine(c, chaines, false)));
          zone.appendChild(b);
        });
        if (triees.length) rangee.hidden = false;
        // Mes favoris : logos des chaînes favorites
        const logosFav = chaines.filter(c => c.UserData && c.UserData.IsFavorite).map(c => logoDe(c, logos)).filter(Boolean).slice(0, 4);
        if (logosFav.length) tuile("favoris").querySelector(".visuel").insertAdjacentHTML("beforeend", `<span class="mosaique-logos">${logosFav.map(u => `<img src="${u}" alt="">`).join("")}</span>`);
        // Reprendre et Films : images des vidéos
        const image = it => it.BackdropImageTags && it.BackdropImageTags.length ? urlImage(it.Id, "Backdrop", 540)
          : (it.ImageTags && it.ImageTags.Thumb ? urlImage(it.Id, "Thumb", 540) : (it.ImageTags && it.ImageTags.Primary ? urlImage(it.Id, "Primary", 540) : null));
        try {
          const r = await api(`/Users/${profil.id}/Items/Resume?MediaTypes=Video&Limit=3`);
          if (e.isConnected) minuteries.push(poserImages(tuile("reprendre"), ((r && r.Items) || []).map(image).filter(Boolean)));
        } catch (err) { journal(err); }
        try {
          const r = await api(`/Users/${profil.id}/Items?Recursive=true&IncludeItemTypes=Movie,Video&SortBy=Random&Limit=6`);
          if (e.isConnected) minuteries.push(poserImages(tuile("films"), ((r && r.Items) || []).map(image).filter(Boolean), 7000));
        } catch (err) { journal(err); }
        if (document.activeElement && document.activeElement.dataset.fond) changerFond(document.activeElement.dataset.fond);
      }
    };
  }

  function appuiLong(element, action) {
    if (!element) return;
    let m = null;
    const debut = () => { m = setTimeout(action, 900); };
    const fin = () => clearTimeout(m);
    element.addEventListener("pointerdown", debut);
    ["pointerup", "pointerleave", "pointercancel"].forEach(ev => element.addEventListener(ev, fin));
    element.addEventListener("contextmenu", ev => ev.preventDefault());
  }

  /* ===================== Chaînes ===================== */

  const ONGLETS = [
    { etiquette: "onglet-infos", texte: "onglet_infos", couleur: "var(--ciel)" },
    { etiquette: "onglet-culture", texte: "onglet_culture", couleur: "var(--lilas)" },
    { etiquette: "onglet-sport", texte: "onglet_sport", couleur: "var(--feuille)" },
    { etiquette: "onglet-enfants", texte: "onglet_enfants", couleur: "var(--citron)" }];
  const COULEURS_LOGO = ["var(--mangue)", "var(--lagon)", "var(--corail)", "var(--citron)", "var(--lilas)", "var(--feuille)", "var(--ciel)"];

  async function chargerChaines() {
    const r = await api(`/LiveTv/Channels?UserId=${profil.id}&AddCurrentProgram=true&Fields=Tags&EnableImages=true&SortBy=SortName`);
    const l = (r && r.Items) || [];
    const num = c => { const n = parseFloat(c.ChannelNumber || c.Number); return isNaN(n) ? 99999 : n; };
    l.sort((a, b) => num(a) - num(b) || (a.Name || "").localeCompare(b.Name || ""));
    return l;
  }
  function chainesEnfants(l) {
    const tag = l.filter(c => (c.Tags || []).includes("onglet-enfants"));
    return tag.length ? tag : l; // côté serveur, le profil enfant ne reçoit déjà que les chaînes permises
  }
  // Vignette de chaîne : image du programme en cours (ou couleur), logo, badge « En direct », titre, avancement
  function carteChaine(c, i, cle, logos) {
    const couleur = COULEURS_LOGO[i % COULEURS_LOGO.length];
    const logo = logoDe(c, logos);
    const p = progReel(c.CurrentProgram, c) ? c.CurrentProgram : null;
    const visuel = imageProgramme(c);
    const pct = p ? progression(p) : null;
    const marque = logo ? `<img src="${logo}" alt="" loading="lazy" data-initiales="${echap(initiales(c.Name))}">` : `<b>${echap(initiales(c.Name))}</b>`;
    const b = el(`<button class="carte-chaine nav ${visuel ? "avec-visuel" : "sans-visuel"}" data-cle="${cle}" ${visuel ? `data-fond="${visuel}"` : ""} aria-label="${echap(c.Name)}" style="--teinte:${couleur}">
      <span class="visuel">${visuel ? `<img src="${visuel}" alt="" loading="lazy">` : ""}</span>
      <span class="plaque-logo">${marque}</span>
      <span class="badge-direct">${echap(t("badge_direct"))}</span>
      <span class="infos"><span class="nom">${echap(c.Name)}</span>${p ? `<span class="maintenant">${echap(p.Name)}</span>` : ""}</span>
      ${pct !== null ? `<span class="barre-prog"><span style="width:${pct.toFixed(0)}%"></span></span>` : ""}</button>`);
    // image ou logo absents : on retombe proprement sur la couleur et les initiales
    const v = b.querySelector(".visuel img");
    if (v) v.addEventListener("error", () => { b.classList.replace("avec-visuel", "sans-visuel"); b.removeAttribute("data-fond"); v.remove(); });
    const l = b.querySelector(".plaque-logo img");
    if (l) l.addEventListener("error", () => { const t2 = document.createElement("b"); t2.textContent = l.dataset.initiales; l.replaceWith(t2); });
    return b;
  }

  function ecranTele() {
    let ongletActif = lire("onglet") || null;
    return {
      nom: "tele",
      async ouvrir() {
        const e = el(`<section class="ecran ambiance">${barre(t("tele_titre"))}<div class="onglets" role="tablist"></div>
          <div class="defile"><div class="grille-chaines"></div></div>${indices(["indice_fleches_choisir", "indice_ok_ouvrir", "indice_retour_accueil"])}</section>`);
        $app.appendChild(e);
        e.querySelector(".grille-chaines").appendChild(el(`<p class="message">${echap(t("chargement"))}</p>`));
        const [toutes, logos] = await Promise.all([chargerChaines(), chargerLogos()]);
        const presents = ONGLETS.filter(o => toutes.some(c => (c.Tags || []).includes(o.etiquette)));
        if (presents.length && !presents.some(o => o.etiquette === ongletActif)) {
          const derniere = toutes.find(c => c.Id === lire("derniereChaine"));
          const o = derniere && presents.find(x => (derniere.Tags || []).includes(x.etiquette));
          ongletActif = (o || presents[0]).etiquette;
        }
        const zoneOnglets = e.querySelector(".onglets");
        const grille = e.querySelector(".grille-chaines");
        const remplir = () => {
          grille.innerHTML = "";
          const liste = presents.length ? toutes.filter(c => (c.Tags || []).includes(ongletActif)) : toutes;
          if (!liste.length) {
            grille.appendChild(el(`<p class="message">${echap(t("tele_vide"))}</p>`));
            return;
          }
          liste.forEach((c, i) => {
            const b = carteChaine(c, toutes.indexOf(c), "c-" + c.Id, logos);
            b.addEventListener("click", () => montrer(ecranLecteurChaine(c, toutes, false)));
            grille.appendChild(b);
          });
        };
        presents.forEach(o => {
          const b = el(`<button class="onglet nav ${o.etiquette === ongletActif ? "actif" : ""}" role="tab" data-cle="o-${o.etiquette}" style="background:${o.couleur}">${echap(t(o.texte))}</button>`);
          const choisir = () => {
            if (ongletActif === o.etiquette) return;
            ongletActif = o.etiquette; ecrire("onglet", o.etiquette);
            zoneOnglets.querySelectorAll(".onglet").forEach(x => x.classList.toggle("actif", x === b));
            remplir();
          };
          b.addEventListener("click", choisir);
          b.addEventListener("focus", choisir);
          zoneOnglets.appendChild(b);
        });
        if (!presents.length) zoneOnglets.remove();
        remplir();
        if (!this.focus) {
          const derniere = lire("derniereChaine");
          focaliser(grille.querySelector(`[data-cle="c-${CSS.escape(derniere || "")}"]`) || grille.querySelector(".nav"));
        }
      }
    };
  }

  /* ===================== Lecteur ===================== */

  function ecranLecteurChaine(chaine, liste, enfants) {
    let video, hls = null, minuterieNom = null, minuterieDelai = null, tentatives = 0, debutChangement = 0, bandeauOuvert = false, ferme = false, enLecture = false;
    let courante = chaine;
    const zapListe = enfants ? chainesEnfants(liste) : liste;

    function arreterLecture() {
      clearTimeout(minuterieDelai);
      if (hls) { try { hls.destroy(); } catch (e) { /* rien */ } hls = null; }
      if (video) { video.removeAttribute("src"); try { video.load(); } catch (e) { /* rien */ } }
    }

    async function sourceDirecte(c) {
      // Lecture directe : on demande à Jellyfin la source de la chaîne, puis on ouvre son adresse d'origine.
      const info = await api(`/Items/${c.Id}/PlaybackInfo?UserId=${profil.id}`, {
        method: "POST",
        corps: { UserId: profil.id, EnableDirectPlay: true, EnableDirectStream: false, EnableTranscoding: false, AllowVideoStreamCopy: false, AllowAudioStreamCopy: false, AutoOpenLiveStream: true, DeviceProfile: { DirectPlayProfiles: [{ Type: "Video" }], TranscodingProfiles: [] } }
      });
      const src = info && info.MediaSources && info.MediaSources[0];
      if (!src) throw new Error("aucune source");
      let adresse = src.Path || "";
      if (!/^https?:\/\//i.test(adresse)) throw new Error("source non http : " + adresse);
      if (adresse.includes(location.host)) journal("attention : la source passe par le serveur MirTV", adresse);
      if (location.protocol === "https:" && adresse.startsWith("http:")) journal("source en http sur une page https : le navigateur peut la bloquer", adresse);
      return adresse;
    }

    async function jouer(c, silencieux = false) {
      arreterLecture();
      courante = c;
      ecrire("derniereChaine", c.Id);
      debutChangement = performance.now();
      enLecture = false;
      const charge = $app.querySelector(".chargement-video");
      if (charge && !silencieux) charge.textContent = t("chargement");
      minuterieDelai = setTimeout(() => { if (!enLecture && !ferme) echec("délai dépassé"); }, CONFIG.delaiChaineSecondes * 1000);
      let adresse;
      try { adresse = await sourceDirecte(c); }
      catch (e) {
        if (e instanceof ErreurApi && e.tropDEcrans) { ferme = true; arreterLecture(); return montrer(ecranTropDEcrans(), true); }
        if (e instanceof ErreurApi && e.statut === 401 && enfants) { ferme = true; arreterLecture(); return montrer(ecranPause(), true); }
        return echec(e.message || e);
      }
      if (ferme || courante !== c) return;
      const estHls = /\.m3u8(\?|$)/i.test(adresse) || /m3u8|hls/i.test(adresse);
      if (estHls && window.Hls && window.Hls.isSupported()) {
        hls = new window.Hls({ enableWorker: true, lowLatencyMode: false, manifestLoadingMaxRetry: 2, levelLoadingMaxRetry: 2, fragLoadingMaxRetry: 2 });
        hls.on(window.Hls.Events.ERROR, (_, d) => {
          if (!d.fatal) return;
          journal("lecture interrompue", d.type, d.details);
          if (enLecture && tentatives < CONFIG.reconnexions) { tentatives++; journal("reconnexion silencieuse", tentatives); setTimeout(() => !ferme && jouer(courante, true), 1500); }
          else echec(d.details);
        });
        hls.loadSource(adresse);
        hls.attachMedia(video);
      } else {
        video.src = adresse;
      }
      video.play().catch(err => journal("lecture automatique refusée, appui nécessaire", err));
    }

    function echec(raison) {
      if (ferme) return;
      journal("chaîne illisible", courante && courante.Name, raison);
      ferme = true;
      arreterLecture();
      const i = zapListe.indexOf(courante);
      const suivante = zapListe[(i + 1) % zapListe.length];
      if (suivante) ecrire("derniereChaine", suivante.Id);
      montrer(ecranMessage({
        enfants, message: t("erreur_chaine"),
        boutons: [{ texte: t("bouton_autre_chaine"), action: () => reculerJusqua(enfants ? "chaines-enfants" : "tele") }]
      }), true);
    }

    function montrerNom(c) {
      const n = $app.querySelector(".nom-chaine");
      if (!n) return;
      n.textContent = (c.ChannelNumber ? c.ChannelNumber + "  " : "") + c.Name;
      n.hidden = false;
      clearTimeout(minuterieNom);
      minuterieNom = setTimeout(() => { n.hidden = true; }, 3000);
    }

    function zapper(delta) {
      if (zapListe.length < 2) return;
      fermerBandeau();
      const i = zapListe.indexOf(courante);
      const c = zapListe[(i + delta + zapListe.length) % zapListe.length];
      tentatives = 0;
      montrerNom(c);
      jouer(c);
    }

    async function ouvrirBandeau() {
      if (enfants) return;
      bandeauOuvert = true;
      const zone = $app.querySelector(".bandeau");
      zone.hidden = false;
      zone.innerHTML = `<div><div class="titre-chaine">${echap(courante.Name)}</div><div data-progs class="ligne-prog">${echap(t("chargement"))}</div></div><div class="actions"></div>`;
      const actions = zone.querySelector(".actions");
      let favori = !!(courante.UserData && courante.UserData.IsFavorite);
      const bFav = el(`<button class="bouton principal nav" data-cle="fav">${P.favoris}${echap(t(favori ? "bouton_retirer_favoris" : "bouton_ajouter_favoris"))}</button>`);
      bFav.addEventListener("click", async () => {
        try {
          await api(`/Users/${profil.id}/FavoriteItems/${courante.Id}`, { method: favori ? "DELETE" : "POST" });
          favori = !favori;
          courante.UserData = Object.assign({}, courante.UserData, { IsFavorite: favori });
          bFav.innerHTML = P.favoris + echap(t(favori ? "bouton_retirer_favoris" : "bouton_ajouter_favoris"));
        } catch (e) { journal(e); }
      });
      actions.appendChild(bFav);
      const pistes = sousTitresDisponibles();
      if (pistes) {
        const bSt = el(`<button class="bouton nav" data-cle="st">${P.soustitres}${echap(t("bouton_sous_titres"))}</button>`);
        bSt.addEventListener("click", basculerSousTitres);
        actions.appendChild(bSt);
      }
      const bProg = el(`<button class="bouton nav" data-cle="prog">${P.programme}${echap(t("bouton_programme"))}</button>`);
      bProg.addEventListener("click", () => { ferme = true; arreterLecture(); montrer(ecranProgramme(liste), true); });
      actions.appendChild(bProg);
      actions.appendChild(el(`<button class="bouton nav" data-accueil data-cle="accueil-btn" aria-label="${echap(t("bouton_accueil"))}">${P.maison}<span class="libelle">${echap(t("bouton_accueil"))}</span></button>`));
      focaliser(bFav);
      const progs = (await programmesChaine(courante.Id, 3)).filter(p => progReel(p, courante));
      const zp = zone.querySelector("[data-progs]");
      if (!zp) return;
      const now = heureServeur();
      const actuel = progs.find(p => new Date(p.StartDate) <= now && new Date(p.EndDate) > now);
      const suivant = progs.find(p => new Date(p.StartDate) > now);
      if (!actuel && !suivant) { zp.textContent = t("programme_inconnu"); return; }
      const ligne = (lib, p) => p ? `<div class="ligne-prog">${echap(lib)} : <b>${echap(p.Name)}</b> · ${echap(t("programme_horaire", heureCourte(new Date(p.StartDate)), heureCourte(new Date(p.EndDate))))}</div>` : "";
      let pourcent = 0;
      if (actuel) { const d = new Date(actuel.StartDate), f = new Date(actuel.EndDate); pourcent = Math.max(0, Math.min(100, (now - d) / (f - d) * 100)); }
      zp.outerHTML = ligne(t("bandeau_maintenant"), actuel) + (actuel ? `<div class="progression"><div style="width:${pourcent.toFixed(0)}%"></div></div>` : "") + ligne(t("bandeau_ensuite"), suivant);
    }
    function fermerBandeau() {
      bandeauOuvert = false;
      const zone = $app.querySelector(".bandeau");
      if (zone) { zone.hidden = true; zone.innerHTML = ""; }
      const v = $app.querySelector(".lecteur");
      if (v) v.focus({ preventScroll: true });
    }

    function sousTitresDisponibles() {
      if (hls && hls.subtitleTracks && hls.subtitleTracks.length) return true;
      return video && video.textTracks && video.textTracks.length > 0;
    }
    function basculerSousTitres() {
      if (hls && hls.subtitleTracks && hls.subtitleTracks.length) { hls.subtitleTrack = hls.subtitleTrack === -1 ? 0 : -1; hls.subtitleDisplay = hls.subtitleTrack !== -1; return; }
      const tt = video.textTracks;
      if (!tt || !tt.length) return;
      const on = tt[0].mode !== "showing";
      for (const tr of tt) tr.mode = "disabled";
      if (on) tt[0].mode = "showing";
    }

    return {
      nom: "lecteur-chaine",
      fermer() { ferme = true; arreterLecture(); clearTimeout(minuterieNom); },
      retour() { if (bandeauOuvert) { fermerBandeau(); return true; } return false; },
      touche(e) {
        if (bandeauOuvert) return false;
        if (e.key === "ArrowUp" || e.key === "ChannelUp" || e.keyCode === 427) { zapper(1); return true; }
        if (e.key === "ArrowDown" || e.key === "ChannelDown" || e.keyCode === 428) { zapper(-1); return true; }
        if (e.key === "Enter" || e.keyCode === 13) { if (video && video.paused) video.play().catch(() => {}); ouvrirBandeau(); return true; }
        if (e.key === "ArrowLeft" || e.key === "ArrowRight") return true;
        return false;
      },
      ouvrir() {
        ferme = false;
        const e = el(`<section class="ecran lecteur nav" tabindex="0" data-cle="video" aria-label="${echap(chaine.Name)}">
          <video playsinline autoplay></video><div class="chargement-video"></div>
          <div class="nom-chaine" hidden></div><div class="bandeau" hidden></div>
          <button class="bouton bouton-accueil-video" data-accueil tabindex="-1">${P.maison}${echap(t("bouton_accueil"))}</button></section>`);
        video = e.querySelector("video");
        video.addEventListener("playing", () => {
          enLecture = true; tentatives = 0; clearTimeout(minuterieDelai);
          const charge = $app.querySelector(".chargement-video"); if (charge) charge.textContent = "";
          journal("chaîne à l'image en", Math.round(performance.now() - debutChangement), "ms :", courante.Name);
        });
        video.addEventListener("error", () => {
          if (enLecture && tentatives < CONFIG.reconnexions) { tentatives++; journal("reconnexion silencieuse", tentatives); setTimeout(() => !ferme && jouer(courante, true), 1500); }
          else if (!hls) echec(video.error && video.error.message);
        });
        // Téléphone : toucher = bandeau ; glisser vers le haut / le bas = changer de chaîne
        let y0 = null;
        e.addEventListener("pointerdown", ev => { y0 = ev.clientY; });
        e.addEventListener("pointerup", ev => {
          if (y0 === null || ev.target.closest(".bandeau, [data-accueil]")) { y0 = null; return; }
          const dy = ev.clientY - y0; y0 = null;
          if (Math.abs(dy) > 60) zapper(dy < 0 ? 1 : -1);
          else if (!bandeauOuvert) { if (video.paused) video.play().catch(() => {}); ouvrirBandeau(); }
        });
        $app.appendChild(e);
        e.focus({ preventScroll: true });
        montrerNom(chaine);
        jouer(chaine);
      }
    };
  }

  function reculerJusqua(nom) {
    while (pile.length && pile[pile.length - 1].nom !== nom) pile.pop();
    if (!pile.length) return allerAccueil();
    if (ecranCourant && ecranCourant.fermer) { try { ecranCourant.fermer(); } catch (e) { /* rien */ } }
    ecranCourant = pile.pop();
    ecranCourant.focus = null;
    rendre(ecranCourant);
  }

  // Sans guide, le serveur invente un « programme » qui porte le nom de la chaîne : on l'ignore.
  const progReel = (p, c) => p && p.Name && c && p.Name.trim().toLowerCase() !== (c.Name || "").trim().toLowerCase();

  async function programmesChaine(id, limite) {
    try {
      const now = heureServeur().toISOString();
      const r = await api(`/LiveTv/Programs?UserId=${profil.id}&ChannelIds=${id}&MinEndDate=${encodeURIComponent(now)}&SortBy=StartDate&Limit=${limite}&EnableImages=false`);
      return (r && r.Items) || [];
    } catch (e) { journal(e); return []; }
  }

  /* ===================== Programme ===================== */

  function ecranProgramme(listeConnue) {
    return {
      nom: "programme",
      async ouvrir() {
        const e = el(`<section class="ecran">${barre(t("programme_titre"))}<div class="defile"><div data-lignes></div></div>${indices(["indice_fleches_choisir", "indice_ok_ouvrir", "indice_retour"])}</section>`);
        $app.appendChild(e);
        const zone = e.querySelector("[data-lignes]");
        zone.appendChild(el(`<p class="message">${echap(t("chargement"))}</p>`));
        const chaines = listeConnue || await chargerChaines();
        const now = heureServeur();
        const demain = new Date(now.getTime() + 18 * 3600 * 1000);
        let progs = [];
        try {
          const r = await api(`/LiveTv/Programs?UserId=${profil.id}&MinEndDate=${encodeURIComponent(now.toISOString())}&MaxStartDate=${encodeURIComponent(demain.toISOString())}&SortBy=StartDate&EnableImages=false&Limit=2000`);
          progs = (r && r.Items) || [];
        } catch (err) { journal(err); }
        zone.innerHTML = "";
        const soir = new Date(now); soir.setHours(19, 0, 0, 0);
        chaines.forEach((c, i) => {
          const pc = progs.filter(p => p.ChannelId === c.Id && progReel(p, c));
          const actuel = pc.find(p => new Date(p.StartDate) <= now && new Date(p.EndDate) > now);
          const suivant = pc.find(p => new Date(p.StartDate) > now);
          const ceSoir = pc.find(p => new Date(p.StartDate) >= soir);
          const cel = (lib, p) => `<span><span class="etiquette">${echap(lib)}</span>${p ? echap(p.Name) + ` <span class="petit">${echap(heureCourte(new Date(p.StartDate)))}</span>` : echap(t("programme_inconnu"))}</span>`;
          const b = el(`<button class="ligne-programme nav" data-cle="pg-${c.Id}"><span class="nom">${echap(c.Name)}</span>${cel(t("bandeau_maintenant"), actuel)}${cel(t("bandeau_ensuite"), suivant)}${cel(t("programme_ce_soir"), ceSoir)}</button>`);
          b.addEventListener("click", () => montrer(ecranLecteurChaine(c, chaines, false)));
          zone.appendChild(b);
        });
        if (!chaines.length) zone.appendChild(el(`<p class="message">${echap(t("tele_vide"))}</p>`));
      }
    };
  }

  /* ===================== Vidéos ===================== */

  const TICKS = 10000000;
  function duree(ticks) {
    const min = Math.round((ticks || 0) / TICKS / 60);
    return min >= 60 ? t("duree_heures", Math.floor(min / 60), min % 60) : t("duree_minutes", min);
  }
  function resume120(s) {
    s = (s || "").replace(/\s+/g, " ").trim();
    const phrase = s.split(/(?<=[.!?])\s/)[0] || s;
    if (phrase.length <= 120) return phrase;
    const coupe = phrase.slice(0, 120);
    return coupe.slice(0, coupe.lastIndexOf(" ")) + "…";
  }
  function affiche(item, cle) {
    const style = item.ImageTags && item.ImageTags.Primary ? `style="background-image:url('${urlImage(item.Id, "Primary", 360)}')"` : "";
    return el(`<button class="affiche nav" data-cle="${cle}" aria-label="${echap(item.Name)}"><span class="image" ${style}>${style ? "" : echap(item.Name)}</span><span class="titre">${echap(item.Name)}</span></button>`);
  }
  const ETIQUETTES_TECHNIQUES = /^(pas-enfants|onglet-|mirtv)/i;

  async function videosDuProfil(enfants) {
    let parent = "";
    try {
      const vues = await api(`/UserViews?UserId=${profil.id}`);
      const items = (vues && vues.Items) || [];
      const v = enfants ? items.find(x => /enfant/i.test(x.Name)) : items.find(x => /film|vid/i.test(x.Name));
      if (v) parent = `&ParentId=${v.Id}`;
    } catch (e) { journal(e); }
    const r = await api(`/Users/${profil.id}/Items?Recursive=true&IncludeItemTypes=Movie,Video,Episode&Fields=Overview,Tags,RunTimeTicks,UserData,MediaSources&SortBy=SortName${parent}`);
    return (r && r.Items) || [];
  }

  function ecranFilms(enfants = false) {
    return {
      nom: enfants ? "videos-enfants" : "films",
      async ouvrir() {
        const titre = enfants ? t("enfants_mes_videos") : t("films_titre");
        const e = el(`<section class="ecran ${enfants ? "enfants" : ""}">${barre(titre)}<div class="defile" data-rangees></div>${indices(["indice_fleches_choisir", "indice_ok_ouvrir", "indice_retour"])}</section>`);
        $app.appendChild(e);
        const zone = e.querySelector("[data-rangees]");
        zone.appendChild(el(`<p class="message">${echap(t("chargement"))}</p>`));
        const items = await videosDuProfil(enfants);
        zone.innerHTML = "";
        if (!items.length) {
          zone.appendChild(el(`<p class="message">${echap(t("films_vide"))}</p>`));
          return;
        }
        const groupes = new Map();
        items.forEach(it => {
          const tags = (it.Tags || []).filter(x => !ETIQUETTES_TECHNIQUES.test(x));
          const cle = tags[0] || t("films_tout");
          if (!groupes.has(cle)) groupes.set(cle, []);
          groupes.get(cle).push(it);
        });
        [...groupes.keys()].sort((a, b) => (a === t("films_tout")) - (b === t("films_tout")) || a.localeCompare(b)).forEach((nom, gi) => {
          const r = el(`<section class="rangee"><h2>${echap(nom)}</h2><div class="affiches"></div></section>`);
          groupes.get(nom).forEach(it => {
            const b = affiche(it, `v-${gi}-${it.Id}`);
            b.addEventListener("click", () => montrer(ecranFiche(it, enfants)));
            r.querySelector(".affiches").appendChild(b);
          });
          zone.appendChild(r);
        });
      }
    };
  }

  function ecranFiche(item, enfants = false) {
    return {
      nom: "fiche",
      async ouvrir() {
        try { item = await api(`/Users/${profil.id}/Items/${item.Id}`); } catch (e) { journal(e); }
        const pos = (item.UserData && item.UserData.PlaybackPositionTicks) || 0;
        const commence = pos > 0;
        const style = item.ImageTags && item.ImageTags.Primary ? `style="background-image:url('${urlImage(item.Id, "Primary", 720)}')"` : "";
        const e = el(`<section class="ecran ${enfants ? "enfants" : ""}">${barre(enfants ? t("enfants_mes_videos") : t("films_titre"))}
          <div class="fiche"><div class="image" ${style}></div><div><h2>${echap(item.Name)}</h2><p class="resume">${echap(resume120(item.Overview))}</p>
          <p class="duree">${item.RunTimeTicks ? echap(duree(item.RunTimeTicks)) : ""}</p>
          <div class="boutons"><button class="bouton principal nav" data-cle="regarder">${P.reprendre}${echap(t(commence ? "bouton_reprendre" : "bouton_regarder"))}</button>
          ${commence ? `<button class="bouton nav" data-cle="debut">${echap(t("bouton_recommencer"))}</button>` : ""}</div></div></div>
          ${indices(["indice_ok_ouvrir", "indice_retour"])}</section>`);
        e.querySelector('[data-cle="regarder"]').addEventListener("click", () => montrer(ecranLecteurVideo(item, pos, enfants)));
        const d = e.querySelector('[data-cle="debut"]');
        if (d) d.addEventListener("click", () => montrer(ecranLecteurVideo(item, 0, enfants)));
        $app.appendChild(e);
        focaliser('[data-cle="regarder"]');
      }
    };
  }

  function ecranLecteurVideo(item, positionTicks, enfants = false) {
    let video, minuterie = null, ferme = false, sessionLecture = null, demarre = false;
    const source = item.MediaSources && item.MediaSources[0];
    function corps(extra = {}) {
      return Object.assign({ ItemId: item.Id, MediaSourceId: source ? source.Id : item.Id, PlaySessionId: sessionLecture, PlayMethod: "DirectPlay", CanSeek: true, PositionTicks: Math.round((video ? video.currentTime : 0) * TICKS), IsPaused: video ? video.paused : false }, extra);
    }
    function signaler(chemin, extra) { api(chemin, { method: "POST", corps: corps(extra) }).catch(e => journal("suivi de lecture", e)); }
    function echec(raison) {
      if (ferme) return;
      journal("vidéo illisible", item.Name, raison);
      ferme = true;
      montrer(ecranMessage({ enfants, message: t("erreur_video"), boutons: [{ texte: t("bouton_autre_chose"), action: () => reculer() }] }), true);
    }
    return {
      nom: "lecteur-video",
      fermer() {
        if (!ferme && demarre) signaler("/Sessions/Playing/Stopped");
        ferme = true; clearInterval(minuterie);
        if (video) { try { video.pause(); } catch (e) { /* rien */ } video.removeAttribute("src"); try { video.load(); } catch (e) { /* rien */ } }
      },
      touche(e) {
        if (!video) return false;
        if (e.key === "Enter" || e.keyCode === 13 || e.key === "MediaPlayPause" || e.key === " ") { video.paused ? video.play().catch(() => {}) : video.pause(); return true; }
        if (e.key === "ArrowRight" || e.key === "MediaFastForward") { video.currentTime = Math.min(video.duration || 1e9, video.currentTime + 30); return true; }
        if (e.key === "ArrowLeft" || e.key === "MediaRewind") { video.currentTime = Math.max(0, video.currentTime - 10); return true; }
        if (e.key === "ArrowUp" || e.key === "ArrowDown") return true;
        return false;
      },
      ouvrir() {
        const e = el(`<section class="ecran lecteur nav" tabindex="0" data-cle="video" aria-label="${echap(item.Name)}"><video playsinline autoplay controls></video><div class="chargement-video">${echap(t("chargement"))}</div>
          <button class="bouton bouton-accueil-video" data-accueil tabindex="-1">${P.maison}${echap(t("bouton_accueil"))}</button></section>`);
        video = e.querySelector("video");
        sessionLecture = "web" + Date.now().toString(36);
        // Lecture directe du fichier (vidéos préparées en H.264 à l'avance), jamais de conversion
        const url = `${CONFIG.serveur}/Videos/${item.Id}/stream?static=true&MediaSourceId=${source ? source.Id : item.Id}&DeviceId=${encodeURIComponent(appareilId())}&api_key=${encodeURIComponent(profil.jeton)}`;
        video.addEventListener("loadedmetadata", () => { if (positionTicks > 0) video.currentTime = positionTicks / TICKS; });
        video.addEventListener("playing", () => {
          const c = $app.querySelector(".chargement-video"); if (c) c.textContent = "";
          if (!demarre) { demarre = true; signaler("/Sessions/Playing"); }
        });
        video.addEventListener("error", () => echec(video.error && video.error.message));
        video.addEventListener("ended", () => { signaler("/Sessions/Playing/Stopped"); demarre = false; reculer(); });
        minuterie = setInterval(() => { if (demarre && !ferme) signaler("/Sessions/Playing/Progress", { EventName: "TimeUpdate" }); }, 10000);
        setTimeout(() => { if (!demarre && !ferme && video.readyState === 0) echec("délai dépassé"); }, 20000);
        video.src = url;
        video.play().catch(err => journal("lecture automatique refusée", err));
        $app.appendChild(e);
        e.focus({ preventScroll: true });
      }
    };
  }

  /* ===================== Reprendre ===================== */

  function ecranReprendre(enfants = false) {
    return {
      nom: "reprendre",
      async ouvrir() {
        const e = el(`<section class="ecran ${enfants ? "enfants" : ""}">${barre(t("reprendre_titre"))}<p class="petit" style="margin:0.4rem 0 0">${echap(t("reprendre_sous_titre"))}</p>
          <div class="defile" style="display:flex;flex-direction:column;justify-content:center"><div class="cartes-reprendre"></div><div class="boutons" data-sous hidden></div></div>
          ${indices(["indice_fleches_choisir", "indice_ok_ouvrir", "indice_retour"])}</section>`);
        $app.appendChild(e);
        const zone = e.querySelector(".cartes-reprendre");
        const r = await api(`/Users/${profil.id}/Items/Resume?MediaTypes=Video&Fields=UserData,RunTimeTicks,MediaSources,Overview&Limit=12`);
        const items = ((r && r.Items) || []).filter(it => {
          const p = it.UserData && it.UserData.PlaybackPositionTicks, d = it.RunTimeTicks;
          return p > 0 && (!d || p / d < 0.95);
        });
        if (!items.length) {
          zone.replaceWith(el(`<div style="text-align:center"><p class="message">${echap(t("reprendre_vide"))}</p><div class="boutons"><button class="bouton principal nav" data-cle="films">${P.films}${echap(t("bouton_voir_films"))}</button></div></div>`));
          e.querySelector('[data-cle="films"]').addEventListener("click", () => montrer(ecranFilms(enfants), true));
          focaliser('[data-cle="films"]');
          return;
        }
        const sous = e.querySelector("[data-sous]");
        items.forEach((it, i) => {
          const pos = it.UserData.PlaybackPositionTicks, d = it.RunTimeTicks || pos;
          const reste = Math.max(1, Math.round((d - pos) / TICKS / 60));
          const img = it.ImageTags && it.ImageTags.Thumb ? urlImage(it.Id, "Thumb", 400) : (it.BackdropImageTags && it.BackdropImageTags.length ? urlImage(it.Id, "Backdrop", 400) : (it.ImageTags && it.ImageTags.Primary ? urlImage(it.Id, "Primary", 400) : ""));
          const b = el(`<button class="carte-reprendre nav" data-cle="r-${it.Id}"><div class="image" ${img ? `style="background-image:url('${img}')"` : ""}></div>
            <div class="progression" style="margin:0"><div style="width:${Math.round(pos / d * 100)}%"></div></div>
            <div class="texte"><div class="titre">${echap(it.Name)}</div><div class="reste">${echap(t("reprendre_reste", reste))}</div></div></button>`);
          b.addEventListener("click", () => montrer(ecranLecteurVideo(it, pos, enfants)));
          b.addEventListener("focus", () => {
            sous.hidden = false; sous.innerHTML = "";
            const deb = el(`<button class="bouton nav" data-cle="debut-${it.Id}">${echap(t("bouton_recommencer"))}</button>`);
            deb.addEventListener("click", () => montrer(ecranLecteurVideo(it, 0, enfants)));
            sous.appendChild(deb);
          });
          zone.appendChild(b);
        });
        if (!this.focus) focaliser(zone.querySelector(".nav"));
      }
    };
  }

  /* ===================== Mes favoris ===================== */

  function ecranFavoris() {
    return {
      nom: "favoris",
      async ouvrir() {
        const e = el(`<section class="ecran">${barre(t("favoris_titre"))}<div class="defile"><div class="grille-favoris"></div></div>${indices(["indice_fleches_choisir", "indice_ok_ouvrir", "indice_retour"])}</section>`);
        $app.appendChild(e);
        const zone = e.querySelector(".grille-favoris");
        let chaines = [], videos = [];
        try { chaines = (await chargerChaines()).filter(c => c.UserData && c.UserData.IsFavorite); } catch (err) { journal(err); }
        try { videos = ((await api(`/Users/${profil.id}/Items?Recursive=true&Filters=IsFavorite&IncludeItemTypes=Movie,Video,Episode&Fields=Overview,RunTimeTicks,UserData,MediaSources`)) || {}).Items || []; } catch (err) { journal(err); }
        if (!chaines.length && !videos.length) {
          zone.replaceWith(el(`<div style="text-align:center;margin:auto"><p class="message">${echap(t("favoris_vide"))}</p><div class="boutons"><button class="bouton principal nav" data-cle="voir-tele">${P.tele}${echap(t("bouton_voir_tele"))}</button></div></div>`));
          e.querySelector('[data-cle="voir-tele"]').addEventListener("click", () => montrer(ecranTele(), true));
          focaliser('[data-cle="voir-tele"]');
          return;
        }
        let toutes = null;
        const logos = await chargerLogos();
        chaines.forEach((c, i) => {
          const b = carteChaine(c, i, "fc-" + c.Id, logos);
          b.addEventListener("click", async () => { toutes = toutes || await chargerChaines(); montrer(ecranLecteurChaine(c, toutes, false)); });
          zone.appendChild(b);
        });
        videos.forEach(v => {
          const b = affiche(v, "fv-" + v.Id);
          b.addEventListener("click", () => montrer(ecranFiche(v)));
          zone.appendChild(b);
        });
      }
    };
  }

  /* ===================== Aide ===================== */

  function ecranAide() {
    return {
      nom: "aide",
      ouvrir() {
        const e = el(`<section class="ecran">${barre(t("aide_titre"))}
          <div class="enfants-grille" style="grid-template-columns:1fr 1fr 1fr">
            <button class="tuile nav" data-cle="image" style="background:var(--ciel)">${P.internet}<span>${echap(t("aide_image"))}</span></button>
            <button class="tuile nav" data-cle="contact" style="background:var(--feuille)">${P.message}<span>${echap(t("aide_contacter"))}</span></button>
            <button class="tuile nav" data-cle="numero" style="background:var(--citron)">${P.numero}<span>${echap(t("aide_numero_box"))}</span></button>
          </div><p class="message" data-reponse style="text-align:center;min-height:2em"></p>
          <div class="boutons" style="margin-top:0"><button class="bouton nav" data-cle="connecter">${P.tele}${echap(t("connecter_ecran_titre"))}</button></div>
          ${indices(["indice_fleches_choisir", "indice_ok_ouvrir", "indice_retour"])}</section>`);
        e.querySelector('[data-cle="connecter"]').addEventListener("click", () => montrer(ecranConnecterEcran()));
        const rep = e.querySelector("[data-reponse]");
        e.querySelector('[data-cle="image"]').addEventListener("click", async () => {
          rep.textContent = t("chargement");
          try { await api("/System/Ping", { jeton: null }); rep.textContent = t("aide_image_ok"); }
          catch (err) { rep.textContent = t("pas_internet_titre") + " " + t("aide_image_consigne"); }
        });
        e.querySelector('[data-cle="contact"]').addEventListener("click", () => {
          rep.textContent = CONFIG.whatsapp ? `${t("aide_whatsapp")} ${CONFIG.whatsapp}. ${t("aide_langues")}` : `${t("aide_whatsapp")} ${t("aide_numero_bientot")}`;
        });
        e.querySelector('[data-cle="numero"]').addEventListener("click", () => { rep.textContent = t("numero_ecran", numeroEcran()); });
        $app.appendChild(e);
      }
    };
  }

  /* ===================== Espace enfants ===================== */

  function ecranAccueilEnfants() {
    let minuterie = null;
    return {
      nom: "accueil-enfants",
      retour() { return true; },      // Retour reste dans l'espace enfants
      retourLong() { },               // appui long aussi
      fermer() { clearInterval(minuterie); },
      ouvrir() {
        const e = el(`<section class="ecran enfants">${barre(t("enfants_titre"))}
          <div class="enfants-grille">
            <button class="tuile nav" data-cle="dessins" style="background:var(--citron)">${P.dessins}<span>${echap(t("enfants_dessins_animes"))}</span></button>
            <button class="tuile nav" data-cle="videos" style="background:var(--lagon)">${P.films}<span>${echap(t("enfants_mes_videos"))}</span></button>
            <button class="tuile nav" data-cle="reprendre" style="background:var(--corail)">${P.reprendre}<span>${echap(t("enfants_reprendre"))}</span></button>
          </div>
          <button class="sortir nav" data-cle="sortir">${P.cadenas}${echap(t("bouton_sortir"))}</button>
          ${indices(["indice_fleches_choisir", "indice_ok_ouvrir"])}</section>`);
        e.querySelector('[data-cle="dessins"]').addEventListener("click", () => montrer(ecranChainesEnfants()));
        e.querySelector('[data-cle="videos"]').addEventListener("click", () => montrer(ecranFilms(true)));
        e.querySelector('[data-cle="reprendre"]').addEventListener("click", () => montrer(ecranReprendre(true)));
        e.querySelector('[data-cle="sortir"]').addEventListener("click", sortirEspaceEnfants);
        $app.appendChild(e);
        focaliser('[data-cle="dessins"]');
        verifierPlage();
        minuterie = setInterval(verifierPlage, 60000);
      }
    };
  }

  function sortirEspaceEnfants() {
    demanderCodeParent(() => { effacer("ouvert"); profil = null; viderPile(); ecranCourant = null; montrer(ecranQuiRegarde(), true); }, () => {}, true);
  }

  function ecranChainesEnfants() {
    return {
      nom: "chaines-enfants",
      async ouvrir() {
        const e = el(`<section class="ecran enfants">${barre(t("enfants_dessins_animes"))}<div class="defile"><div class="grille-chaines"></div></div>${indices(["indice_fleches_choisir", "indice_ok_ouvrir"])}</section>`);
        $app.appendChild(e);
        const grille = e.querySelector(".grille-chaines");
        const [toutes, logos] = await Promise.all([chargerChaines(), chargerLogos()]);
        const liste = chainesEnfants(toutes);
        if (!liste.length) grille.appendChild(el(`<p class="message">${echap(t("tele_vide"))}</p>`));
        liste.forEach((c, i) => {
          const b = carteChaine(c, i, "c-" + c.Id, logos);
          b.addEventListener("click", () => montrer(ecranLecteurChaine(c, liste, true)));
          grille.appendChild(b);
        });
        if (!this.focus) focaliser(grille.querySelector(`[data-cle="c-${CSS.escape(lire("derniereChaine") || "")}"]`) || grille.querySelector(".nav"));
      }
    };
  }

  /* Plages horaires : réglées dans Jellyfin (Policy.AccessSchedules), contrôlées avec l'heure du serveur */
  function dansLaPlage(politique) {
    const plages = (politique && politique.AccessSchedules) || [];
    if (!plages.length) return true;
    const d = new Date(heureServeur().getTime() + CONFIG.decalageHeures * 3600 * 1000);
    const jours = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];
    const jour = jours[d.getUTCDay()];
    const heure = d.getUTCHours() + d.getUTCMinutes() / 60;
    return plages.some(p => {
      const okJour = p.DayOfWeek === jour || p.DayOfWeek === "Everyday" ||
        (p.DayOfWeek === "Weekday" && !["Saturday", "Sunday"].includes(jour)) || (p.DayOfWeek === "Weekend" && ["Saturday", "Sunday"].includes(jour));
      return okJour && heure >= p.StartHour && heure < p.EndHour;
    });
  }
  function verifierPlage() {
    if (!profil || !estEnfant(profil.nom)) return;
    if (!dansLaPlage(profil.politique)) montrer(ecranPause(), true);
  }

  function ecranPause() {
    viderPile();
    let minuterie = null;
    const dessin = `<div class="dessin-pause"><svg viewBox="0 0 160 120" aria-hidden="true">
      <circle cx="120" cy="30" r="18" fill="#FFD93D"/><circle cx="128" cy="24" r="16" fill="#0F3A2F"/>
      <ellipse cx="70" cy="92" rx="46" ry="18" fill="#1B5244"/>
      <circle cx="60" cy="70" r="24" fill="#FF9F1C"/><circle cx="44" cy="52" r="8" fill="#FF9F1C"/><circle cx="76" cy="52" r="8" fill="#FF9F1C"/>
      <path d="M50 70 q5 4 10 0 M64 70 q5 4 10 0" stroke="#120E36" stroke-width="3" fill="none" stroke-linecap="round"/>
      <text x="96" y="64" fill="#D6F5E3" font-size="14" font-family="Lexend">z z</text></svg></div>`;
    return {
      nom: "pause",
      retour() { return true; }, retourLong() { },
      fermer() { clearInterval(minuterie); },
      ouvrir() {
        const e = el(`<section class="ecran enfants ecran-centre">${dessin}<p class="message grand">${echap(t("pause_enfants_titre"))}</p><p class="message">${echap(t("pause_enfants_message"))}</p>
          <button class="sortir nav" data-cle="sortir">${P.cadenas}${echap(t("bouton_sortir"))}</button></section>`);
        e.querySelector('[data-cle="sortir"]').addEventListener("click", sortirEspaceEnfants);
        $app.appendChild(e);
        focaliser('[data-cle="sortir"]');
        // Retour automatique à l'espace enfants quand la plage recommence
        minuterie = setInterval(async () => {
          if (!profil) return;
          try { const u = await api(`/Users/${profil.id}`); profil.politique = u.Policy || {}; } catch (err) { return; }
          if (dansLaPlage(profil.politique)) allerAccueil();
        }, 60000);
      }
    };
  }

  /* ===================== Code parent ===================== */

  async function hacher(code, sel) {
    const cle = await crypto.subtle.importKey("raw", new TextEncoder().encode(code), "PBKDF2", false, ["deriveBits"]);
    const bits = await crypto.subtle.deriveBits({ name: "PBKDF2", salt: new TextEncoder().encode(sel), iterations: 150000, hash: "SHA-256" }, cle, 256);
    return [...new Uint8Array(bits)].map(b => b.toString(16).padStart(2, "0")).join("");
  }
  async function codeEstBon(code, fam) {
    const enr = lire("code." + fam);
    if (!enr) return code === CONFIG.codeParentParDefaut;
    return (await hacher(code, enr.sel)) === enr.hache;
  }
  async function enregistrerCode(code, fam) {
    const sel = (crypto.randomUUID ? crypto.randomUUID() : String(Math.random())) + fam;
    ecrire("code." + fam, { sel, hache: await hacher(code, sel) });
  }

  function demanderCodeParent(siBon, siRester, enfants = false) {
    montrer(ecranCodeParent({ enfants, titre: t("code_parent_titre"), consigne: t("code_parent_consigne"), valider: async code => {
      const fam = famille(profil ? profil.nom : "");
      return codeEstBon(code, fam);
    }, siBon, siRester }));
  }

  function ecranCodeParent({ enfants, titre, consigne, valider, siBon, siRester }) {
    let saisie = "";
    let ce = null;
    function maj() { if (!ce) return; ce.querySelectorAll(".code-cases span").forEach((s, i) => { s.textContent = i < saisie.length ? "●" : ""; }); }
    async function chiffre(n) {
      const blocage = lire("blocageCode") || 0;
      const msg = ce.querySelector("[data-msg]");
      if (Date.now() < blocage) { msg.textContent = t("code_parent_attente"); return; }
      if (saisie.length >= 4) return;
      saisie += n; maj();
      if (saisie.length < 4) return;
      const ok = await valider(saisie);
      if (ok) { ecrire("echecsCode", 0); siBon(saisie); return; }
      const echecs = (lire("echecsCode") || 0) + 1;
      ecrire("echecsCode", echecs);
      if (echecs >= 5) { ecrire("blocageCode", Date.now() + 60000); ecrire("echecsCode", 0); msg.textContent = t("code_parent_attente"); }
      else msg.textContent = t("erreur_mauvais_code") + " " + t("bouton_reessayer") + ".";
      saisie = ""; setTimeout(maj, 400);
    }
    return {
      nom: "code-parent",
      retour() { reculer2(); return true; },
      touche(e) { if (/^[0-9]$/.test(e.key)) { chiffre(e.key); return true; } return false; },
      ouvrir() {
        ce = el(`<section class="ecran ecran-centre ${enfants ? "enfants" : ""}">${barre(titre, false)}
          <p class="message">${echap(consigne)}</p><div class="code-cases"><span></span><span></span><span></span><span></span></div>
          <p class="message" data-msg style="min-height:1.4em;margin:0 auto 0.6rem"></p>
          <div class="pave"></div></section>`);
        const pave = ce.querySelector(".pave");
        ["1", "2", "3", "4", "5", "6", "7", "8", "9"].forEach(n => {
          const b = el(`<button class="nav" data-cle="k${n}">${n}</button>`); b.addEventListener("click", () => chiffre(n)); pave.appendChild(b);
        });
        const eff = el(`<button class="nav large" data-cle="effacer">${echap(t("touche_effacer"))}</button>`);
        eff.addEventListener("click", () => { saisie = saisie.slice(0, -1); maj(); });
        const zero = el(`<button class="nav" data-cle="k0">0</button>`); zero.addEventListener("click", () => chiffre("0"));
        const rester = el(`<button class="nav large rester" data-cle="rester">${echap(t("touche_rester"))}</button>`);
        rester.addEventListener("click", () => reculer2());
        pave.append(eff, zero, rester);
        $app.appendChild(ce);
        focaliser('[data-cle="k1"]');
      }
    };
    function reculer2() {
      if (!pile.length) return;
      ecranCourant = pile.pop();
      rendre(ecranCourant);
      if (siRester) siRester();
    }
  }

  /* ===================== Connecter un autre écran (code rapide) ===================== */

  function ecranConnecterEcran() {
    let saisie = "", ce = null, envoi = false;
    const maj = () => ce && ce.querySelectorAll(".code-cases span").forEach((s, i) => { s.textContent = saisie[i] || ""; });
    async function chiffre(n) {
      if (envoi || saisie.length >= 6) return;
      saisie += n; maj();
      if (saisie.length < 6) return;
      envoi = true;
      const msg = ce.querySelector("[data-msg]");
      try {
        await api(`/QuickConnect/Authorize?code=${encodeURIComponent(saisie)}&userId=${profil.id}`, { method: "POST" });
        msg.textContent = t("connecter_ecran_ok");
      } catch (e) {
        journal("code refusé", e);
        msg.textContent = t("connecter_ecran_erreur");
        saisie = ""; setTimeout(maj, 600);
      }
      envoi = false;
    }
    return {
      nom: "connecter-ecran",
      touche(e) { if (/^[0-9]$/.test(e.key)) { chiffre(e.key); return true; } return false; },
      ouvrir() {
        ce = el(`<section class="ecran ecran-centre">${barre(t("connecter_ecran_titre"))}
          <p class="message">${echap(t("connecter_ecran_consigne"))}</p>
          <div class="code-cases">${"<span></span>".repeat(6)}</div>
          <p class="message" data-msg style="min-height:1.4em;margin:0 auto 0.6rem"></p><div class="pave"></div></section>`);
        const pave = ce.querySelector(".pave");
        ["1", "2", "3", "4", "5", "6", "7", "8", "9"].forEach(n => { const b = el(`<button class="nav" data-cle="k${n}">${n}</button>`); b.addEventListener("click", () => chiffre(n)); pave.appendChild(b); });
        const eff = el(`<button class="nav large" data-cle="effacer">${echap(t("touche_effacer"))}</button>`);
        eff.addEventListener("click", () => { saisie = saisie.slice(0, -1); maj(); ce.querySelector("[data-msg]").textContent = ""; });
        const zero = el(`<button class="nav" data-cle="k0">0</button>`); zero.addEventListener("click", () => chiffre("0"));
        const fin = el(`<button class="nav large rester" data-cle="fini">${echap(t("bouton_revenir"))}</button>`);
        fin.addEventListener("click", () => reculer());
        pave.append(eff, zero, fin);
        $app.appendChild(ce);
        focaliser('[data-cle="k1"]');
      }
    };
  }

  /* ===================== Réglages (profil parent) ===================== */

  function ecranReglages() {
    return {
      nom: "reglages",
      ouvrir() {
        const fam = famille(profil.nom);
        const e = el(`<section class="ecran">${barre(t("reglages_titre"))}<div class="defile"><div class="liste-reglages">
          <p class="petit">${echap(t("reglages_langue"))}</p>
          <div class="boutons" style="justify-content:flex-start;margin:0">
            <button class="bouton nav ${langue() === "fr" ? "principal" : ""}" data-cle="fr">${echap(t("langue_fr"))}</button>
            <button class="bouton nav ${langue() === "mg" ? "principal" : ""}" data-cle="mg">${echap(t("langue_mg"))}</button></div>
          <button class="bouton nav" data-cle="code">${P.cadenas}${echap(t("reglages_code"))}</button>
          <button class="bouton nav" data-cle="connecter">${P.tele}${echap(t("connecter_ecran_titre"))}</button>
          <button class="bouton nav" data-cle="ecrans">${P.tele}${echap(t("mes_ecrans_titre"))}</button>
          <button class="bouton nav" data-cle="oublier">${P.retour}${echap(t("reglages_oublier"))}</button>
          <p class="petit" data-msg></p>
          <p class="petit">${echap(t("apropos_titre"))} : MirTV ${echap(CONFIG.version)}</p>
          </div></div>${indices(["indice_fleches_choisir", "indice_ok_ouvrir", "indice_retour"])}</section>`);
        e.querySelector('[data-cle="fr"]').addEventListener("click", () => { ecrire("langue", "fr"); rendre(ecranCourant); });
        e.querySelector('[data-cle="mg"]').addEventListener("click", () => { ecrire("langue", "mg"); rendre(ecranCourant); });
        e.querySelector('[data-cle="ecrans"]').addEventListener("click", () => montrer(ecranMesEcrans()));
        e.querySelector('[data-cle="connecter"]').addEventListener("click", () => montrer(ecranConnecterEcran()));
        e.querySelector('[data-cle="oublier"]').addEventListener("click", () => {
          effacer("profils"); effacer("ouvert"); effacer("dernierProfil");
          profil = null; viderPile(); ecranCourant = null; montrer(ecranQuiRegarde(), true);
        });
        e.querySelector('[data-cle="code"]').addEventListener("click", () => {
          montrer(ecranCodeParent({ titre: t("reglages_code"), consigne: t("reglages_nouveau_code"), valider: async () => true, siBon: async code => {
            await enregistrerCode(code, fam);
            reculer();
            const m = $app.querySelector("[data-msg]"); if (m) m.textContent = t("reglages_code_ok");
          } }));
        });
        $app.appendChild(e);
      }
    };
  }

  /* ===================== Démarrage ===================== */

  async function demarrer() {
    pieger();
    viderPile();
    ecranCourant = null;
    if (!navigator.onLine) return montrer(ecranPasInternet(), true);
    // Retour dans l'appli : un enfant retrouve son espace (pas « Qui regarde ? »)
    const ouvert = lire("ouvert");
    const p = ouvert && profils().find(x => x.id === ouvert);
    if (p) return ouvrirProfil(p);
    montrer(ecranQuiRegarde(), true);
  }

  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => navigator.serviceWorker.register("sw.js").catch(e => journal("service worker", e)));
  }
  window.addEventListener("online", () => { if (ecranCourant && ecranCourant.nom === "message") demarrer(); });
  window.MirTV = { t, deplacer, estEnfant, estParent, prenom, dansLaPlage, resume120, duree, demarrer }; // pour les tests
  demarrer();
})();
