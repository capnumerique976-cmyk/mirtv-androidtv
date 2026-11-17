#!/usr/bin/env python3
"""MirTV — programmes des chaînes qui n'en ont pas dans les guides gratuits tout faits.

Utilise l'outil libre iptv-org/epg (« récupérateur »), qui lit les programmes directement sur
plus de 200 sites (sites des chaînes, magazines télé, opérateurs…).

1. Lit la liste des chaînes encore sans programme (écrite par guide-mirtv.py dans SANS_GUIDE).
2. Pour chacune, trouve son identifiant iptv-org :
   - chaînes de l'import de test : identifiant noté dans le registre (colonne notes) ;
   - autres chaînes : par le nom (liste des chaînes iptv-org, noms et autres noms).
   Si rien n'est trouvé, cherche le nom tel quel dans les listes des sites.
3. Choisit pour chaque chaîne jusqu'à 3 sites (sites en état « vert » d'abord, langue de la chaîne d'abord).
4. Récupère 2 jours de programmes, site par site : 1er choix, puis 2e choix pour les chaînes restées vides, puis 3e.
5. Écrit SORTIE (lu par guide-mirtv.py, qui l'ajoute au guide de Jellyfin) et un rapport.

Usage : python3 guide-iptv-org.py [--essai N]   (N : seulement les N premières chaînes, pour tester)
Lancé chaque nuit par le minuteur systemd mirtv-guide-iptv-org.timer (voir installer-guide.sh).
"""
import csv, glob, json, os, re, subprocess, sys, unicodedata, urllib.request
import xml.etree.ElementTree as ET
from collections import defaultdict
from datetime import datetime

DOSSIER = os.environ.get("MIRTV_GUIDE_DOSSIER", "/opt/mirtv/guide")
OUTIL = os.environ.get("MIRTV_EPG_OUTIL", "/opt/mirtv/epg-iptv-org")
REGISTRE = os.environ.get("MIRTV_REGISTRE_CSV", "/opt/mirtv/registre/registre-chaines.csv")
API = os.environ.get("MIRTV_IPTV_API", "https://iptv-org.github.io/api/")
SANS_GUIDE = os.path.join(DOSSIER, "sans-guide.json")
SORTIE = os.path.join(DOSSIER, "iptv-org.xml")
TRAVAIL = os.path.join(DOSSIER, "iptv-org")
RAPPORT = os.path.join(DOSSIER, "rapport-iptv-org.txt")
PASSES = 3
JOURS = 2

ISO = {"francais": "fr", "anglais": "en", "allemand": "de", "grec": "el", "hongrois": "hu", "italien": "it",
       "portugais": "pt", "russe": "ru", "espagnol": "es", "arabe": "ar", "persan": "fa", "turc": "tr"}
# Noms de « déclinaisons » iptv-org selon la langue (France24.fr@French, Euronews.fr@Italian…)
FEED_LANGUE = {"fr": "french", "en": "english", "de": "german", "el": "greek", "hu": "hungarian", "it": "italian",
               "pt": "portuguese", "ru": "russian", "es": "spanish", "ar": "arabic", "fa": "persian", "tr": "turkish"}


def norm(s):
    s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()
    s = re.sub(r"\.(fr|com|tv|uk|us|be|ch|ca)\b", " ", s)
    s = re.sub(r"\b(hd|sd|fhd|uhd|tv|television|tele|channel|chaine|direct|live)\b", " ", s)
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return "".join(s.split())


def langue_et_base(nom):
    """« Euronews (allemand) » → ("Euronews", "de") ; « France 2 » → ("France 2", "fr")."""
    brut = unicodedata.normalize("NFKD", nom or "").encode("ascii", "ignore").decode().lower()
    m = re.match(r"^(.*?)\s*\((\w+)\)\s*$", brut)
    if m and m.group(2) in ISO:
        return re.sub(r"\s*\([^)]*\)\s*$", "", nom), ISO[m.group(2)]
    return nom, "fr"


def json_api(nom):
    req = urllib.request.Request(API + nom, headers={"User-Agent": "MirTV-guide/1.0"})
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.load(r)


def ids_du_registre():
    """nom normalisé → (identifiant iptv-org, déclinaison) pour les chaînes de l'import de test."""
    out = {}
    if not os.path.exists(REGISTRE):
        return out
    with open(REGISTRE, encoding="utf-8") as f:
        lignes = list(csv.reader(f, delimiter=";"))
    if not lignes:
        return out
    tete = lignes[0]
    i_nom, i_notes = tete.index("nom"), tete.index("notes")
    for l in lignes[1:]:
        if len(l) <= i_notes:
            continue
        m = re.search(r"iptv-org : ([^\s/(]+)(?:/(\S+))? \(", l[i_notes])
        if m:
            out[norm(l[i_nom])] = (m.group(1), m.group(2) or "")
    return out


def index_sites():
    """identifiant iptv-org → [entrées], nom normalisé → [entrées], état de chaque site."""
    etat = {}
    p = os.path.join(OUTIL, "SITES.md")
    if os.path.exists(p):
        for site, _, e in re.findall(r'<a href="sites/([^"]+)">[^<]*</a></td><td[^>]*>(\d+)</td><td[^>]*>([^<]*)</td>',
                                     open(p, encoding="utf-8").read()):
            etat[site] = e.strip()
    par_id, par_nom = defaultdict(list), defaultdict(list)
    for f in glob.glob(os.path.join(OUTIL, "sites", "*", "*.channels.xml")):
        for m in re.finditer(r"<channel\s+([^>]*)>([^<]*)</channel>", open(f, encoding="utf-8").read()):
            a = dict(re.findall(r'(\w+)="([^"]*)"', m.group(1)))
            e = {"site": a.get("site", ""), "site_id": a.get("site_id", ""), "lang": a.get("lang", ""),
                 "xmltv_id": a.get("xmltv_id", ""), "nom": m.group(2).strip()}
            if not e["site"] or not e["site_id"] or etat.get(e["site"]) == "🔴":
                continue
            if e["xmltv_id"]:
                par_id[e["xmltv_id"].split("@")[0]].append(e)
            par_nom[norm(e["nom"])].append(e)
    return par_id, par_nom, etat


def candidats(nom, registre, chaines_api, par_id, par_nom, etat):
    """Jusqu'à PASSES entrées de sites, la meilleure d'abord, et l'identifiant iptv-org retenu."""
    base, langue = langue_et_base(nom)
    ident, feed = registre.get(norm(nom), (None, ""))
    if not ident:
        trouves = chaines_api.get(norm(base), [])
        trouves = sorted(trouves, key=lambda c: (c.get("country") != "FR", c.get("closed") is not None))
        ident = trouves[0]["id"] if trouves else None
    entrees = list(par_id.get(ident, [])) if ident else []
    if not entrees:  # pas d'identifiant connu : le nom tel qu'il est écrit sur les sites
        entrees = [e for e in par_nom.get(norm(nom), []) + par_nom.get(norm(base), []) if e["lang"] in (langue, "")]
    vus, uniques = set(), []
    for e in entrees:
        cle = (e["site"], e["site_id"])
        if cle not in vus:
            vus.add(cle)
            uniques.append(e)

    def rang(e):
        f = e["xmltv_id"].split("@")[1].lower() if "@" in e["xmltv_id"] else ""
        bonne_decl = (feed and f == feed.lower()) or (not feed and f in ("", "sd", "hd", FEED_LANGUE.get(langue, "")))
        return (etat.get(e["site"]) != "🟢", e["lang"] != langue, not bonne_decl, e["site"])

    uniques.sort(key=rang)
    # un seul choix par site : passer à un autre site est plus utile qu'une autre page du même site
    choix, sites = [], set()
    for e in uniques:
        if e["site"] not in sites:
            sites.add(e["site"])
            choix.append(e)
    return choix[:PASSES], ident


def echapper(s):
    return (s or "").replace("&", "&amp;").replace("<", "&lt;").replace(">", "&gt;").replace('"', "&quot;")


def recuperer(passe, liste):
    """liste : [(cid, nom, entrée)] → {cid: [éléments programme]}"""
    os.makedirs(TRAVAIL, exist_ok=True)
    chemin = os.path.join(TRAVAIL, f"passe{passe}.channels.xml")
    sortie = os.path.join(TRAVAIL, f"passe{passe}.xml")
    with open(chemin, "w", encoding="utf-8") as f:
        f.write('<?xml version="1.0" encoding="UTF-8"?>\n<channels>\n')
        for cid, nom, e in liste:
            f.write(f'  <channel site="{echapper(e["site"])}" site_id="{echapper(e["site_id"])}" lang="{echapper(e["lang"] or "fr")}"'
                    f' xmltv_id="{echapper(cid)}">{echapper(nom)}</channel>\n')
        f.write("</channels>\n")
    if os.path.exists(sortie):
        os.remove(sortie)
    cmd = ["node", "-r", "@swc-node/register", "scripts/commands/epg/grab.ts", f"--channels={chemin}",
           f"--output={sortie}", f"--days={JOURS}", "--maxConnections=4", "--timeout=20000"]
    with open(os.path.join(TRAVAIL, f"passe{passe}.log"), "w") as log:
        try:
            subprocess.run(cmd, cwd=OUTIL, stdout=log, stderr=subprocess.STDOUT, timeout=5400)
        except subprocess.TimeoutExpired:
            log.write("\nArrêt : plus de 90 minutes.\n")
    progs = defaultdict(list)
    if os.path.exists(sortie):
        try:
            for p in ET.parse(sortie).getroot().findall("programme"):
                progs[p.get("channel")].append(p)
        except ET.ParseError as err:
            print(f"Passe {passe} : résultat illisible ({err})", file=sys.stderr)
    return progs


def main():
    essai = int(sys.argv[sys.argv.index("--essai") + 1]) if "--essai" in sys.argv else 0
    if not os.path.exists(SANS_GUIDE):
        sys.exit(f"{SANS_GUIDE} absent : lancez d'abord guide-mirtv.py")
    sans = json.load(open(SANS_GUIDE, encoding="utf-8"))  # {cid: nom}
    items = list(sans.items())[: essai or None]
    registre = ids_du_registre()
    chaines_api = defaultdict(list)
    for c in json_api("channels.json"):
        for n in [c.get("name")] + list(c.get("alt_names") or []):
            chaines_api[norm(n)].append(c)
    par_id, par_nom, etat = index_sites()
    print(f"{len(items)} chaîne(s) sans programme ; {sum(len(v) for v in par_id.values())} entrées de sites connues")

    plans, sans_site = {}, []
    for cid, nom in items:
        choix, ident = candidats(nom, registre, chaines_api, par_id, par_nom, etat)
        if choix:
            plans[cid] = (nom, choix, ident)
        else:
            sans_site.append((cid, nom, ident))

    resultats, utilise = {}, {}
    for passe in range(PASSES):
        liste = [(cid, nom, choix[passe]) for cid, (nom, choix, _) in plans.items()
                 if cid not in resultats and len(choix) > passe]
        if not liste:
            break
        print(f"Passe {passe + 1} : {len(liste)} chaîne(s) sur {len({e[2]['site'] for e in liste})} site(s)…", flush=True)
        progs = recuperer(passe + 1, liste)
        for cid, nom, e in liste:
            if progs.get(cid):
                resultats[cid] = progs[cid]
                utilise[cid] = e
        print(f"  → {sum(1 for c, _, _ in liste if c in resultats)} avec programmes", flush=True)

    # Fichier final : identifiants MirTV, nom MirTV (guide-mirtv.py associe par l'identifiant)
    tv = ET.Element("tv", {"generator-info-name": "MirTV (iptv-org/epg)"})
    for cid in resultats:
        ch = ET.SubElement(tv, "channel", {"id": cid})
        ET.SubElement(ch, "display-name").text = sans[cid]
    for cid, liste in resultats.items():
        for p in liste:
            p.set("channel", cid)
            tv.append(p)
    os.makedirs(DOSSIER, exist_ok=True)
    tmp = SORTIE + ".tmp"
    ET.ElementTree(tv).write(tmp, encoding="utf-8", xml_declaration=True)
    os.replace(tmp, SORTIE)
    os.chmod(SORTIE, 0o644)

    lignes = [f"Récupération iptv-org/epg du {datetime.now():%d/%m/%Y %H:%M}",
              f"Chaînes sans programme au départ : {len(items)}",
              f"Programmes trouvés : {len(resultats)}",
              f"Aucun site ne trouve la chaîne : {len(sans_site)}",
              f"Sites essayés sans résultat : {len(plans) - len(resultats)}", ""]
    for cid, (nom, choix, ident) in plans.items():
        if cid in resultats:
            e = utilise[cid]
            lignes.append(f"OK    {nom} ← {e['site']} ({len(resultats[cid])} programmes)")
    for cid, (nom, choix, ident) in plans.items():
        if cid not in resultats:
            lignes.append(f"VIDE  {nom} ({ident or 'sans identifiant'}) — essayé : {', '.join(e['site'] for e in choix)}")
    for cid, nom, ident in sans_site:
        lignes.append(f"---   {nom} ({ident or 'sans identifiant iptv-org'})")
    open(RAPPORT, "w", encoding="utf-8").write("\n".join(lignes) + "\n")
    print("\n".join(lignes[:5]))
    print(f"Guide : {SORTIE} ; rapport : {RAPPORT}")


if __name__ == "__main__":
    main()
