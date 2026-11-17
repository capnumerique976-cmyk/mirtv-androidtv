#!/usr/bin/env python3
"""MirTV — import de TEST des chaînes de langue française de la base libre iptv-org.

Sert à valider le lecteur sur tous les cas réels (flux variés). Les chaînes ajoutées sont en statut « essai »
avec la note « TEST INTERNE iptv-org » : à retirer avant l'ouverture au public (--retirer).

Étapes :
  1. python3 importer-iptv-org.py --candidats
       télécharge channels.json, feeds.json, streams.json, logos.json (https://iptv-org.github.io/api/),
       garde : (pays = FR ou langue française) et pas pour adultes, chaîne non fermée ;
       ne garde que les flux en https ; écarte les flux qui exigent un « referrer » ou un « user-agent »
       (impossible à donner depuis un navigateur) et les chaînes déjà dans MirTV ;
       écrit DEST/candidats.json (lu par le test de lecture dans le navigateur) et DEST/bilan.json.
  2. test de lecture dans un vrai navigateur (hls.js, comme l'appli) : voir docs/rapport-import-iptv-org.md
  3. python3 importer-iptv-org.py --ajouter 3,7,12,…   (numéros des candidats qui marchent)
       ajoute ces chaînes au registre (statut « essai »), avec leur catégorie MirTV et leur logo iptv-org.
     Puis : cd ~/mirtv/scripts/mirtv-admin && sudo python3 -B synchro.py
  python3 importer-iptv-org.py --retirer    retire toutes les chaînes « TEST INTERNE iptv-org »
"""
import csv, datetime, json, os, re, shutil, sys, unicodedata, urllib.request

API = os.environ.get("MIRTV_IPTV_API", "https://iptv-org.github.io/api/")
REGISTRE = os.environ.get("MIRTV_REGISTRE_CSV", "/opt/mirtv/registre/registre-chaines.csv")
DEST = os.environ.get("MIRTV_IMPORT", "/opt/mirtv/pwa/import")
COLONNES = ["numero", "nom", "categorie", "enfants", "url_source", "page_officielle", "type",
            "preuve", "date_verification", "verifie_par", "statut", "notes", "logo"]
MARQUE = "TEST INTERNE iptv-org"

# Catégories iptv-org → catégories MirTV (la première catégorie connue l'emporte)
CATEGORIES = {
    "news": "Infos", "weather": "Infos", "legislative": "Infos", "public": "Infos",
    "general": "Généraliste",
    "sports": "Sport", "outdoor": "Sport",
    "movies": "Cinéma", "classic": "Cinéma",
    "series": "Séries", "comedy": "Séries",
    "entertainment": "Divertissement", "family": "Divertissement", "interactive": "Divertissement", "shop": "Divertissement",
    "music": "Musique",
    "documentary": "Documentaires", "science": "Documentaires", "travel": "Documentaires", "education": "Documentaires",
    "cooking": "Cuisine",
    "lifestyle": "Art de vivre", "relax": "Art de vivre", "auto": "Art de vivre",
    "animation": "Animés",
    "kids": "Dessins animés",
    "culture": "Culture",
    "business": "Économie",
    "religious": "Religion",
}
ORDRE = ["kids", "animation", "news", "sports", "movies", "series", "music", "documentary", "cooking", "religious",
         "business", "culture", "entertainment", "lifestyle", "general"]
QUALITE = {"2160p": 1, "1080p": 3, "1080i": 3, "720p": 5, "576p": 4, "576i": 4, "480p": 4, "360p": 2}


def json_url(nom):
    req = urllib.request.Request(API + nom, headers={"User-Agent": "MirTV-import/1.0"})
    with urllib.request.urlopen(req, timeout=180) as r:
        return json.load(r)


def norm(s):
    s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9]+", "", s)


def lire_registre():
    with open(REGISTRE, encoding="utf-8") as f:
        lignes = [l for l in csv.reader(f, delimiter=";")]
    corps = [l for l in lignes[1:] if any(x.strip() for x in l)]
    return [dict(zip(COLONNES, (l + [""] * len(COLONNES))[:len(COLONNES)])) for l in corps]


def ecrire_registre(chaines, suffixe):
    jour = datetime.datetime.now().strftime("%Y%m%d-%H%M")
    shutil.copy2(REGISTRE, f"{REGISTRE}.avant-{suffixe}-{jour}")
    tmp = REGISTRE + ".tmp"
    with open(tmp, "w", encoding="utf-8", newline="") as f:
        f.write(";".join(COLONNES) + "\n")
        for c in chaines:
            f.write(";".join(" ".join(str(c.get(k, "")).replace(";", ",").split()) for k in COLONNES) + "\n")
    os.replace(tmp, REGISTRE)


def categorie(cats):
    cats = [c for c in (cats or []) if c in CATEGORIES]
    for c in ORDRE:
        if c in cats:
            return CATEGORIES[c], c in ("kids", "animation")
    return (CATEGORIES[cats[0]] if cats else "Généraliste"), False


def candidats():
    canaux = json_url("channels.json")
    try:
        feeds = json_url("feeds.json")
    except Exception:  # ancienne API : langues dans channels.json
        feeds = []
    flux = json_url("streams.json")
    logos = json_url("logos.json")
    b = {"chaines_total": len(canaux), "flux_total": len(flux)}

    langues_feed, nom_feed, principal = {}, {}, {}
    for f in feeds:
        cle = (f.get("channel"), f.get("id"))
        langues_feed[cle] = f.get("languages") or []
        nom_feed[cle] = f.get("name") or ""
        if f.get("is_main"):
            principal[f.get("channel")] = f.get("id")
    langues_chaine = {}
    for (ch, _), l in langues_feed.items():
        langues_chaine.setdefault(ch, set()).update(l)

    retenues = {}
    for c in canaux:
        langues = set(c.get("languages") or []) | langues_chaine.get(c["id"], set())
        if c.get("is_nsfw") or c.get("closed"):
            continue
        if c.get("country") == "FR" or "fra" in langues:
            retenues[c["id"]] = c
    b["chaines_filtrees"] = len(retenues)

    meilleur_logo = {}
    for l in logos:
        ch = l.get("channel")
        if ch not in retenues or not str(l.get("url", "")).startswith("https://"):
            continue
        score = (l.get("feed") in (None, "", principal.get(ch)), "Picons" not in (l.get("tags") or []), l.get("width") or 0)
        if ch not in meilleur_logo or score > meilleur_logo[ch][0]:
            meilleur_logo[ch] = (score, l["url"])

    registre = lire_registre()
    urls = {c["url_source"] for c in registre}
    noms = {norm(c["nom"]) for c in registre}

    b.update({"flux_des_chaines": 0, "ecartes_http": 0, "ecartes_referrer_user_agent": 0, "ecartes_deja_dans_mirtv": 0})
    par_chaine = {}
    for s in flux:
        ch = s.get("channel")
        if ch not in retenues:
            continue
        b["flux_des_chaines"] += 1
        url = (s.get("url") or "").strip()
        if not url.lower().startswith("https://"):
            b["ecartes_http"] += 1
            continue
        if s.get("referrer") or s.get("user_agent"):
            b["ecartes_referrer_user_agent"] += 1
            continue
        c = retenues[ch]
        feed = s.get("feed")
        nf = nom_feed.get((ch, feed), "") if feed and feed != principal.get(ch) else ""
        nom = c["name"] + (f" ({nf})" if nf and nf.lower() not in ("sd", "hd", "main") else "")
        if url in urls or norm(nom) in noms or norm(c["name"]) in noms:
            b["ecartes_deja_dans_mirtv"] += 1
            continue
        cat, enfants = categorie(c.get("categories"))
        q = s.get("quality") or ""
        par_chaine.setdefault((ch, nom), []).append({
            "nom": nom, "id": ch, "feed": feed or "", "url": url, "qualite": q,
            "categorie": cat, "categories_iptv": c.get("categories") or [], "enfants": "oui" if enfants else "non",
            "pays": c.get("country") or "", "site": c.get("website") or "", "logo": meilleur_logo.get(ch, (0, ""))[1],
            "format": "hls" if re.search(r"\.m3u8(\?|$)|m3u8", url, re.I) else ("dash" if ".mpd" in url.lower() else "autre"),
            "_ordre": QUALITE.get(q, 3),
        })
    liste = []
    for (_, nom), fl in sorted(par_chaine.items(), key=lambda x: x[0][1].lower()):
        fl.sort(key=lambda x: -x["_ordre"])
        for x in fl:
            x.pop("_ordre", None)
        liste.append({"n": len(liste) + 1, "nom": nom, "flux": fl})
    b["chaines_candidates"] = len(liste)
    b["flux_candidats"] = sum(len(x["flux"]) for x in liste)
    b["date"] = datetime.datetime.now().isoformat(timespec="minutes")
    os.makedirs(DEST, exist_ok=True)
    with open(os.path.join(DEST, "candidats.json"), "w", encoding="utf-8") as f:
        json.dump(liste, f, ensure_ascii=False)
    with open(os.path.join(DEST, "bilan.json"), "w", encoding="utf-8") as f:
        json.dump(b, f, ensure_ascii=False, indent=1)
    print(json.dumps(b, ensure_ascii=False))
    return 0


def ajouter(choix):
    """choix : « 3,7:2,12 » = candidat 3 (1er flux), candidat 7 (2e flux), candidat 12."""
    with open(os.path.join(DEST, "candidats.json"), encoding="utf-8") as f:
        liste = {x["n"]: x for x in json.load(f)}
    registre = lire_registre()
    urls = {c["url_source"] for c in registre}
    noms = {norm(c["nom"]) for c in registre}
    numeros = [int(c["numero"]) for c in registre if c["numero"].isdigit()]
    numero = max([300] + [n + 1 for n in numeros if n >= 300])
    ajout = 0
    for morceau in [m for m in re.split(r"[,\s]+", choix) if m]:
        n, _, i = morceau.partition(":")
        x = liste.get(int(n)) if n.isdigit() else None
        k = int(i) - 1 if i.isdigit() else 0
        if not x or not 0 <= k < len(x["flux"]):
            print(f"  candidat « {morceau} » inconnu : ignoré")
            continue
        fl = x["flux"][k]
        if fl["url"] in urls or norm(x["nom"]) in noms:
            continue
        registre.append({
            "numero": str(numero), "nom": x["nom"], "categorie": fl["categorie"], "enfants": fl["enfants"],
            "url_source": fl["url"], "page_officielle": fl["site"], "type": "gratuit-officiel", "preuve": "",
            "date_verification": datetime.date.today().isoformat(),
            "verifie_par": "Claude (lecture testée dans le navigateur avec hls.js)", "statut": "essai",
            "notes": f"{MARQUE} : {fl['id']}{('/' + fl['feed']) if fl['feed'] else ''} ({fl['pays'] or '?'}, {fl['qualite'] or 'qualité ?'}). À retirer avant l'ouverture au public.",
            "logo": fl["logo"],
        })
        urls.add(fl["url"])
        noms.add(norm(x["nom"]))
        numero += 1
        ajout += 1
    ecrire_registre(registre, "iptv-org")
    print(f"{ajout} chaîne(s) ajoutée(s) (statut essai, numéros 300 et plus). Lancez la synchro.")
    return 0


def retirer():
    registre = lire_registre()
    reste = [c for c in registre if not c["notes"].startswith(MARQUE)]
    ecrire_registre(reste, "retrait-iptv-org")
    print(f"{len(registre) - len(reste)} chaîne(s) « {MARQUE} » retirée(s). Lancez la synchro.")
    return 0


if __name__ == "__main__":
    if "--candidats" in sys.argv:
        sys.exit(candidats())
    if "--ajouter" in sys.argv:
        i = sys.argv.index("--ajouter")
        sys.exit(ajouter(" ".join(sys.argv[i + 1:])))
    if "--retirer" in sys.argv:
        sys.exit(retirer())
    print(__doc__)
