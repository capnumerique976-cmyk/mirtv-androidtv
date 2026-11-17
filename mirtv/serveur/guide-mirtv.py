#!/usr/bin/env python3
"""MirTV — guide des programmes (XMLTV) à partir de sources gratuites.

1. Lit la liste des chaînes MirTV (sortie M3U de Dispatcharr, identifiant tvg-id + nom).
2. Télécharge les guides gratuits (SOURCES), garde seulement nos chaînes, et renomme leurs identifiants
   avec ceux de Dispatcharr (1, 2, 3…), pour que Jellyfin les associe tout seul.
3. Ce qui manque encore est écrit dans sans-guide.json : guide-iptv-org.py va lire ces programmes
   directement sur les sites (outil iptv-org/epg), chaque nuit ; son résultat est relu ici (LOCAL).
   Les chaînes toujours sans guide gardent les programmes « factices » de Dispatcharr.
4. Écrit le résultat dans SORTIE (lu par Jellyfin) et un rapport (chaînes avec / sans guide).

Associations manuelles possibles dans guide-associations.csv (nom MirTV;identifiant dans la source).
Usage : python3 guide-mirtv.py [--rapport | --chercher mot1 mot2…]   (lancé toutes les 6 heures par le minuteur systemd)
"""
import csv, gzip, io, json, os, re, sys, unicodedata, urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone

DISPATCHARR = os.environ.get("MIRTV_DISPATCHARR", "http://127.0.0.1:9191")
SORTIE = os.environ.get("MIRTV_GUIDE", "/opt/mirtv/jellyfin/config/guide/mirtv-guide.xml")
ICI = os.path.dirname(os.path.abspath(__file__))
DOSSIER = os.environ.get("MIRTV_GUIDE_DOSSIER", "/opt/mirtv/guide")
LOCAL = os.path.join(DOSSIER, "iptv-org.xml")         # fabriqué chaque nuit par guide-iptv-org.py
SANS_GUIDE = os.path.join(DOSSIER, "sans-guide.json")  # chaînes que guide-iptv-org.py doit chercher
SOURCES = [
    "https://xmltvfr.fr/xmltv/xmltv.xml.gz",
    "https://epgshare01.online/epgshare01/epg_ripper_FR1.xml.gz",
    "https://epgshare01.online/epgshare01/epg_ripper_UK1.xml.gz",
    "https://epg.pw/xmltv/epg_FR.xml.gz",
    # chaînes gratuites « FAST » (Pluto TV, Samsung TV Plus, Plex, Roku) : France d'abord, puis le reste
    "https://i.mjh.nz/PlutoTV/fr.xml.gz",
    "https://i.mjh.nz/SamsungTVPlus/fr.xml.gz",
    "https://i.mjh.nz/PlutoTV/all.xml.gz",
    "https://i.mjh.nz/SamsungTVPlus/all.xml.gz",
    "https://i.mjh.nz/Plex/all.xml.gz",
    "https://i.mjh.nz/Roku/all.xml.gz",
    "https://epg.pw/xmltv/epg.xml.gz",                                   # monde entier (gros fichier, lu en flux)
    LOCAL,                                                               # programmes lus sur les sites (iptv-org/epg)
    "https://epgshare01.online/epgshare01/epg_ripper_ALL_SOURCES1.xml.gz",  # très gros, seulement s'il reste des chaînes
]
JOURS = 2  # on garde 2 jours de programmes


def ouvrir(url, timeout=300):
    """Flux décompressé à la volée (les gros guides ne sont jamais entièrement en mémoire)."""
    if url.startswith("/"):
        return open(url, "rb")
    req = urllib.request.Request(url, headers={"User-Agent": "MirTV-guide/1.0"})
    r = urllib.request.urlopen(req, timeout=timeout)
    return gzip.GzipFile(fileobj=r) if url.endswith(".gz") else r


def telecharger(url, timeout=120):
    req = urllib.request.Request(url, headers={"User-Agent": "MirTV-guide/1.0"})
    with urllib.request.urlopen(req, timeout=timeout) as r:
        data = r.read()
    if url.endswith(".gz") or data[:2] == b"\x1f\x8b":
        data = gzip.decompress(data)
    return data


def norm(s):
    s = unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()
    s = re.sub(r"\((francais|french|fr)\)", " fr ", s)
    s = re.sub(r"\((anglais|english|en)\)", " en ", s)
    s = re.sub(r"\.(fr|com|tv|uk|us|be|ch|ca)\b", " ", s)
    s = re.sub(r"\b(hd|sd|fhd|uhd|tv|television|tele|channel|chaine|direct|live)\b", " ", s)
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " ".join(s.split())


LANGUES = {
    "francais": ["fr", "francais", "french"], "anglais": ["en", "english", "anglais"],
    "allemand": ["de", "german", "deutsch"], "grec": ["gr", "greek", "ellinika"], "hongrois": ["hu", "hungarian", "magyar"],
    "italien": ["it", "italian", "italiano"], "portugais": ["pt", "portuguese", "portugues"], "russe": ["ru", "russian"],
    "espagnol": ["es", "spanish", "espanol"], "arabe": ["ar", "arabic", "arabe"],
}


def candidats(nom):
    """Noms possibles d'une chaîne dans les guides : « Euronews (allemand) » → euronews de, euronews german…"""
    brut = unicodedata.normalize("NFKD", nom or "").encode("ascii", "ignore").decode().lower()
    m = re.match(r"^(.*?)\s*\((\w+)\)\s*$", brut)
    if not m:
        return [norm(nom)]
    base, langue = m.group(1), m.group(2)
    variantes = LANGUES.get(langue, [langue])
    out = [norm(f"{base} {v}") for v in variantes]
    if langue == "francais":
        out.append(norm(base))
    return out


def nos_chaines():
    m3u = telecharger(DISPATCHARR + "/output/m3u?direct=true", 30).decode("utf-8", "replace")
    out = []
    for m in re.finditer(r'tvg-id="([^"]*)"[^\n]*?tvg-name="([^"]*)"', m3u):
        out.append((m.group(1), m.group(2)))
    return out


def associations_manuelles():
    p = os.path.join(ICI, "guide-associations.csv")
    if not os.path.exists(p):
        return {}
    with open(p, encoding="utf-8") as f:
        return {l[0].strip(): l[1].strip() for l in csv.reader(f, delimiter=";") if len(l) >= 2 and not l[0].startswith("#")}


def heure(s):
    # format XMLTV : 20261117083000 +0300
    try:
        return datetime.strptime(s[:20].strip(), "%Y%m%d%H%M%S %z")
    except ValueError:
        return datetime.strptime(s[:14], "%Y%m%d%H%M%S").replace(tzinfo=timezone.utc)


def main(rapport_seul=False):
    chaines = nos_chaines()
    manuel = associations_manuelles()
    restant = {cid: nom for cid, nom in chaines}
    trouve = {}  # id MirTV -> (source, id source)
    programmes = {}
    maintenant = datetime.now(timezone.utc)
    fin = maintenant + timedelta(days=JOURS)

    for url in SOURCES:
        if url == LOCAL and not rapport_seul:
            # ce qui manque encore après les guides tout faits : liste de travail de guide-iptv-org.py
            os.makedirs(DOSSIER, exist_ok=True)
            with open(SANS_GUIDE + ".tmp", "w", encoding="utf-8") as f:
                json.dump(restant, f, ensure_ascii=False, indent=0)
            os.replace(SANS_GUIDE + ".tmp", SANS_GUIDE)
        if url == LOCAL and not os.path.exists(LOCAL):
            continue
        if not restant:
            break
        try:
            flux = ouvrir(url)
        except Exception as e:
            print(f"Source indisponible : {url} ({e})", file=sys.stderr)
            continue
        index, ids_source, choisis, pret = {}, set(), {}, False
        try:
            for _, el in ET.iterparse(flux, events=("end",)):
                if el.tag == "channel":
                    cid_s = el.get("id")
                    ids_source.add(cid_s)
                    for n in [dn.text for dn in el.findall("display-name")] + [cid_s]:
                        index.setdefault(norm(n), cid_s)
                        index.setdefault(norm(n).replace(" ", ""), cid_s)  # « TV5 Monde Info » = « TV5Monde Info »
                    el.clear()
                elif el.tag == "programme":
                    if not pret:  # toutes les chaînes de la source sont lues : on associe
                        pret = True
                        for cid, nom in list(restant.items()):
                            src = manuel.get(nom) if manuel.get(nom) in ids_source else None
                            if url == LOCAL:  # fichier fait pour MirTV : mêmes identifiants
                                src = cid if cid in ids_source else None
                            for c in ([] if src or url == LOCAL else [x for c in candidats(nom) for x in (c, c.replace(" ", ""))]):
                                src = index.get(c)
                                if src:
                                    break
                            if src and src not in choisis:
                                choisis[src] = cid
                                trouve[cid] = (url, src)
                                del restant[cid]
                        index.clear()
                        if not choisis:
                            break  # rien d'utile dans cette source
                    cid = choisis.get(el.get("channel"))
                    if cid:
                        try:
                            d, f = heure(el.get("start")), heure(el.get("stop"))
                        except Exception:
                            d = f = None
                        if d and f and not (f < maintenant - timedelta(hours=2) or d > fin):
                            el.set("channel", cid)
                            el.tail = None
                            programmes.setdefault(cid, []).append(ET.fromstring(ET.tostring(el)))
                    el.clear()
        except ET.ParseError as e:
            print(f"Source illisible : {url} ({e})", file=sys.stderr)
        # une chaîne associée sans aucun programme à venir reste à chercher ailleurs
        for cid in list(trouve):
            if trouve[cid][0] == url and not programmes.get(cid):
                restant[cid] = dict(chaines)[cid]
                del trouve[cid]

    print(f"Chaînes avec guide : {len([c for c in trouve if programmes.get(c)])} sur {len(chaines)}")
    for cid, nom in chaines:
        if programmes.get(cid):
            print(f"  OK   {nom} ← {trouve[cid][1]} ({len(programmes[cid])} programmes)")
    for cid, nom in chaines:
        if not programmes.get(cid):
            print(f"  ---  {nom}")
    if rapport_seul:
        return

    # Fusion : vrais programmes quand on en a, sinon ceux de Dispatcharr
    base = ET.fromstring(telecharger(DISPATCHARR + "/output/epg", 30))
    tv = ET.Element("tv", {"generator-info-name": "MirTV"})
    for ch in base.findall("channel"):
        tv.append(ch)
    for p in base.findall("programme"):
        if not programmes.get(p.get("channel")):
            tv.append(p)
    for liste in programmes.values():
        for p in liste:
            for el in list(p):
                if el.tag not in ("title", "sub-title", "desc", "category", "icon"):
                    p.remove(el)
            tv.append(p)
    os.makedirs(os.path.dirname(SORTIE), exist_ok=True)
    tmp = SORTIE + ".tmp"
    ET.ElementTree(tv).write(tmp, encoding="utf-8", xml_declaration=True)
    os.replace(tmp, SORTIE)
    os.chmod(SORTIE, 0o644)
    print(f"Guide écrit : {SORTIE}")


def chercher(mots):
    """Aide aux associations manuelles : liste les chaînes des sources dont le nom contient un des mots."""
    mots = [norm(m) for m in mots if norm(m)]
    for url in SOURCES:
        try:
            flux = ouvrir(url)
            for _, el in ET.iterparse(flux, events=("end",)):
                if el.tag == "programme":
                    break
                if el.tag == "channel":
                    noms = [dn.text or "" for dn in el.findall("display-name")]
                    texte = " | ".join(norm(n) for n in noms + [el.get("id")])
                    if any(m in texte for m in mots):
                        print(f"{url.split('/')[-1]:<34} {el.get('id')!s:<40} {' / '.join(noms)[:70]}")
                    el.clear()
        except Exception as e:
            print(f"Source illisible : {url} ({e})", file=sys.stderr)


if __name__ == "__main__":
    if "--chercher" in sys.argv:
        chercher(sys.argv[sys.argv.index("--chercher") + 1:])
    else:
        main("--rapport" in sys.argv)
