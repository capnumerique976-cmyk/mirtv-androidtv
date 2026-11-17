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

Import « sport » (toutes langues) : ajouter --sport à --candidats, --ajouter et --resultats.
  Garde les chaînes de la catégorie iptv-org « sports » (comme la liste categories/sports.m3u), toutes rangées
  dans la catégorie MirTV « Sport ». Fichiers à part : candidats-sport.json, bilan-sport.json, rapport-sport.html.
"""
import csv, datetime, json, os, re, shutil, sys, unicodedata, urllib.request

API = os.environ.get("MIRTV_IPTV_API", "https://iptv-org.github.io/api/")
REGISTRE = os.environ.get("MIRTV_REGISTRE_CSV", "/opt/mirtv/registre/registre-chaines.csv")
DEST = os.environ.get("MIRTV_IMPORT", "/opt/mirtv/pwa/import")
COLONNES = ["numero", "nom", "categorie", "enfants", "url_source", "page_officielle", "type",
            "preuve", "date_verification", "verifie_par", "statut", "notes", "logo"]
MARQUE = "TEST INTERNE iptv-org"
SPORT = "--sport" in sys.argv
SUFFIXE = "-sport" if SPORT else ""


def chemin_import(nom):
    """candidats.json → candidats-sport.json en import « sport »"""
    base, ext = os.path.splitext(nom)
    return os.path.join(DEST, base + SUFFIXE + ext)

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
        if SPORT:
            if "sports" in (c.get("categories") or []):
                retenues[c["id"]] = c
        elif c.get("country") == "FR" or "fra" in langues:
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
    cles = {cle_langue(c["nom"]) for c in registre}

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
        if url in urls or norm(nom) in noms or norm(c["name"]) in noms or cle_langue(nom) in cles:
            b["ecartes_deja_dans_mirtv"] += 1
            continue
        cat, enfants = ("Sport", False) if SPORT else categorie(c.get("categories"))
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
    with open(chemin_import("candidats.json"), "w", encoding="utf-8") as f:
        json.dump(liste, f, ensure_ascii=False)
    with open(chemin_import("bilan.json"), "w", encoding="utf-8") as f:
        json.dump(b, f, ensure_ascii=False, indent=1)
    print(json.dumps(b, ensure_ascii=False))
    return 0


def ajouter(choix):
    """choix : « 3,7:2,12 » = candidat 3 (1er flux), candidat 7 (2e flux), candidat 12."""
    with open(chemin_import("candidats.json"), encoding="utf-8") as f:
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
    ecrire_registre(registre, "iptv-org" + SUFFIXE)
    print(f"{ajout} chaîne(s) ajoutée(s) (statut essai, numéros 300 et plus). Lancez la synchro.")
    return 0


def retirer():
    registre = lire_registre()
    reste = [c for c in registre if not c["notes"].startswith(MARQUE)]
    ecrire_registre(reste, "retrait-iptv-org")
    print(f"{len(registre) - len(reste)} chaîne(s) « {MARQUE} » retirée(s). Lancez la synchro.")
    return 0



def resultats(fichier):
    """Lit le résultat du test de lecture (1re ligne : liste JSON des raisons ; puis « n,choisi,2e essai,hauteur,
    hauteur max,ms,raisons » séparés par « ; »), ajoute les chaînes qui marchent et écrit le rapport."""
    with open(fichier, encoding="utf-8") as f:
        codes = json.loads(f.readline())
        brut = f.read().replace("\n", "")
    with open(chemin_import("candidats.json"), encoding="utf-8") as f:
        cand = {x["n"]: x for x in json.load(f)}
    with open(chemin_import("bilan.json"), encoding="utf-8") as f:
        bilan = json.load(f)
    lignes = []
    for r in [r for r in brut.split(";") if r]:
        n, choisi, deux, h, hmax, ms, raisons = r.split(",")
        x = cand[int(n)]
        lignes.append({"n": int(n), "nom": x["nom"], "choisi": int(choisi), "deuxieme": deux == "1", "hauteur": int(h),
                       "hmax": int(hmax), "ms": int(ms), "flux": x["flux"],
                       "raisons": [codes[int(c)] for c in raisons.split(".") if c != ""]})
    ok = [l for l in lignes if l["choisi"]]
    ko = [l for l in lignes if not l["choisi"]]
    ajouter(",".join(f"{l['n']}:{l['choisi']}" for l in ok))

    from collections import Counter
    par_cat = Counter(l["flux"][l["choisi"] - 1]["categorie"] for l in ok)
    causes = Counter(l["raisons"][-1] if l["raisons"] else "inconnue" for l in ko)
    qual = Counter("1080p et plus" if max(l["hmax"], l["hauteur"]) >= 1080 else "720p" if max(l["hmax"], l["hauteur"]) >= 720
                   else "moins de 720p" for l in ok)
    adaptatifs = sum(1 for l in ok if l["hmax"])
    temps = sorted(l["ms"] for l in ok)
    mediane = temps[len(temps) // 2] if temps else 0
    pays = Counter(l["flux"][l["choisi"] - 1]["pays"] or "?" for l in ok)
    jour = datetime.datetime.now().strftime("%d/%m/%Y %H:%M")

    titre = "Import de test iptv-org — chaînes de sport (toutes langues)" if SPORT else "Import de test iptv-org"
    filtre = ("catégorie « sports » d'iptv-org, toutes langues, pas pour adultes, chaîne en activité" if SPORT
              else "pays FR ou langue française, pas pour adultes, chaîne en activité")
    md = [f"# {titre} — {jour}", "",
          "## Résumé", "",
          f"- Base iptv-org : {bilan['chaines_total']} chaînes, {bilan['flux_total']} flux.",
          f"- Filtre ({filtre}) : **{bilan['chaines_filtrees']} chaînes**, {bilan['flux_des_chaines']} flux.",
          f"- Écartés avant le test : {bilan['ecartes_http']} flux en http (non sécurisés), {bilan['ecartes_referrer_user_agent']} flux qui exigent un « referrer » ou un « user-agent » (impossible depuis un navigateur), {bilan['ecartes_deja_dans_mirtv']} déjà dans MirTV.",
          f"- Testées dans un vrai navigateur (Chrome, hls.js comme l'appli, depuis https://mirtv.rahiss.com) : **{len(lignes)} chaînes** ({bilan['flux_candidats']} flux https).",
          f"- **Lisibles : {len(ok)}** (dont {sum(1 for l in ok if l['deuxieme'])} au 2e essai) — **ajoutées à MirTV** en statut « essai » (TEST INTERNE). **Illisibles : {len(ko)}** — écartées.",
          f"- Démarrage médian : {mediane / 1000:.1f} s. Flux à plusieurs qualités (adaptatifs) : {adaptatifs} ; à une seule qualité : {len(ok) - adaptatifs}.",
          "", "## Chaînes ajoutées par catégorie", "", "| Catégorie | Chaînes |", "|---|---|"]
    md += [f"| {c} | {n} |" for c, n in par_cat.most_common()]
    md += ["", "## Qualité la plus haute proposée", "", "| Qualité | Chaînes |", "|---|---|"]
    md += [f"| {q} | {n} |" for q, n in qual.most_common()]
    md += ["", "## Pays des chaînes ajoutées", "", "| Pays | Chaînes |", "|---|---|"]
    md += [f"| {p} | {n} |" for p, n in pays.most_common()]
    md += ["", "## Pourquoi des chaînes ne marchent pas", "", "| Cause | Chaînes |", "|---|---|"]
    md += [f"| {c} | {n} |" for c, n in causes.most_common()]
    md += ["", "## Liste des chaînes ajoutées", "", "| Nom | Catégorie | Pays | Qualité max | Démarrage |", "|---|---|---|---|---|"]
    for l in sorted(ok, key=lambda l: (l["flux"][l["choisi"] - 1]["categorie"], l["nom"].lower())):
        fl = l["flux"][l["choisi"] - 1]
        md.append(f"| {l['nom']} | {fl['categorie']} | {fl['pays']} | {max(l['hmax'], l['hauteur'])}p | {l['ms'] / 1000:.1f} s |")
    md += ["", "## Liste des chaînes écartées", "", "| Nom | Cause |", "|---|---|"]
    for l in sorted(ko, key=lambda l: l["nom"].lower()):
        md.append(f"| {l['nom']} | {' ; '.join(dict.fromkeys(l['raisons'])) or 'inconnue'} |")
    md += ["", "Pour retirer toutes ces chaînes de test : `python3 importer-iptv-org.py --retirer` puis la synchro."]
    texte = "\n".join(md) + "\n"
    os.makedirs(DEST, exist_ok=True)
    with open(chemin_import("rapport.md"), "w", encoding="utf-8") as f:
        f.write(texte)
    import html as h
    corps = []
    for ligne in md:
        if ligne.startswith("# "):
            corps.append(f"<h1>{h.escape(ligne[2:])}</h1>")
        elif ligne.startswith("## "):
            corps.append(f"<h2>{h.escape(ligne[3:])}</h2>")
        elif ligne.startswith("|---"):
            continue
        elif ligne.startswith("| "):
            cel = [c.strip() for c in ligne.strip("|").split("|")]
            corps.append("<tr>" + "".join(f"<td>{h.escape(c)}</td>" for c in cel) + "</tr>")
        elif ligne.startswith("- "):
            corps.append(f"<p>• {re.sub(r'[*][*](.*?)[*][*]', r'<b>\1</b>', h.escape(ligne[2:]))}</p>")
        elif ligne:
            corps.append(f"<p>{h.escape(ligne)}</p>")
    page = "\n".join(corps)
    page = re.sub(r"((?:<tr>.*</tr>\n?)+)", lambda m: "<table>" + m.group(1) + "</table>", page)
    with open(chemin_import("rapport.html"), "w", encoding="utf-8") as f:
        f.write('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
                '<title>' + h.escape(titre) + ' — MirTV</title><style>body{font:15px/1.5 system-ui,sans-serif;max-width:1000px;margin:24px auto;padding:0 16px;color:#1b1840}'
                'h1{font-size:24px}h2{font-size:18px;margin-top:28px;border-bottom:2px solid #ff9f1c}table{border-collapse:collapse;width:100%}'
                'td{border-bottom:1px solid #ddd;padding:4px 8px;font-size:14px}tr:first-child td{font-weight:700;background:#f5f3ff}</style>' + page)
    print(f"Lisibles : {len(ok)} / {len(lignes)} ; catégories : " + ", ".join(f"{c} {n}" for c, n in par_cat.most_common()))
    print("Causes : " + " | ".join(f"{c} : {n}" for c, n in causes.most_common()))
    print(f"Qualité : " + ", ".join(f"{q} {n}" for q, n in qual.most_common()) + f" ; adaptatifs {adaptatifs} ; démarrage médian {mediane} ms")
    print("Pays : " + ", ".join(f"{p} {n}" for p, n in pays.most_common(12)))
    print(f"Rapport : https://mirtv.rahiss.com/tv/import/rapport{SUFFIXE}.html")
    return 0


LANGUES = {"english": "en", "anglais": "en", "french": "fr", "francais": "fr", "arabic": "ar", "arabe": "ar",
           "spanish": "es", "espagnol": "es", "german": "de", "allemand": "de", "hungarian": "hu", "hongrois": "hu",
           "italian": "it", "italien": "it", "portuguese": "pt", "portugais": "pt", "russian": "ru", "russe": "ru"}


def cle_langue(nom):
    """« France 24 (English) » et « France 24 (anglais) » → même clé ; sans langue = français."""
    s = unicodedata.normalize("NFKD", nom or "").encode("ascii", "ignore").decode().lower()
    mots = re.findall(r"[a-z0-9+]+", s)
    langue = next((LANGUES[m] for m in mots if m in LANGUES), "fr")
    return " ".join(m for m in mots if m not in LANGUES and m not in ("hd", "sd", "tv")) + "|" + langue


def doublons(retirer=False):
    registre = lire_registre()
    autres = {cle_langue(c["nom"]): c["nom"] for c in registre if not c["notes"].startswith(MARQUE)}
    trouves = [c for c in registre if c["notes"].startswith(MARQUE) and cle_langue(c["nom"]) in autres]
    for c in trouves:
        print(f"  doublon : {c['nom']}  =  {autres[cle_langue(c['nom'])]}")
    if retirer and trouves:
        ids = {id(c) for c in trouves}
        ecrire_registre([c for c in registre if id(c) not in ids], "doublons-iptv-org")
        print(f"{len(trouves)} doublon(s) retiré(s). Lancez la synchro.")
    elif not trouves:
        print("Aucun doublon.")
    return 0

if __name__ == "__main__":
    if "--candidats" in sys.argv:
        sys.exit(candidats())
    if "--ajouter" in sys.argv:
        i = sys.argv.index("--ajouter")
        sys.exit(ajouter(" ".join(sys.argv[i + 1:])))
    if "--retirer" in sys.argv:
        sys.exit(retirer())
    if "--doublons" in sys.argv:
        sys.exit(doublons("--retirer-doublons" in sys.argv))
    if "--resultats" in sys.argv:
        sys.exit(resultats(sys.argv[sys.argv.index("--resultats") + 1]))
    print(__doc__)
