#!/usr/bin/env python3
"""MirTV — guide des programmes (XMLTV) à partir de sources gratuites.

1. Lit la liste des chaînes MirTV (sortie M3U de Dispatcharr, identifiant tvg-id + nom).
2. Télécharge les guides gratuits (SOURCES), garde seulement nos chaînes, et renomme leurs identifiants
   avec ceux de Dispatcharr (1, 2, 3…), pour que Jellyfin les associe tout seul.
3. Les chaînes sans guide gardent les programmes « factices » de Dispatcharr.
4. Écrit le résultat dans SORTIE (lu par Jellyfin) et un rapport (chaînes avec / sans guide).

Associations manuelles possibles dans guide-associations.csv (nom MirTV;identifiant dans la source).
Usage : python3 guide-mirtv.py [--rapport]   (lancé toutes les 6 heures par le minuteur systemd)
"""
import csv, gzip, io, os, re, sys, unicodedata, urllib.request
import xml.etree.ElementTree as ET
from datetime import datetime, timedelta, timezone

DISPATCHARR = os.environ.get("MIRTV_DISPATCHARR", "http://127.0.0.1:9191")
SORTIE = os.environ.get("MIRTV_GUIDE", "/opt/mirtv/jellyfin/config/guide/mirtv-guide.xml")
ICI = os.path.dirname(os.path.abspath(__file__))
SOURCES = [
    "https://xmltvfr.fr/xmltv/xmltv.xml.gz",
    "https://epgshare01.online/epgshare01/epg_ripper_FR1.xml.gz",
    "https://epgshare01.online/epgshare01/epg_ripper_UK1.xml.gz",
]
JOURS = 2  # on garde 2 jours de programmes


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
    s = re.sub(r"\b(hd|sd|fhd|uhd|tv|channel|chaine|direct|live)\b", " ", s)
    s = re.sub(r"[^a-z0-9]+", " ", s)
    return " ".join(s.split())


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
        if not restant:
            break
        try:
            data = telecharger(url)
        except Exception as e:
            print(f"Source indisponible : {url} ({e})", file=sys.stderr)
            continue
        racine = ET.fromstring(data)
        index = {}
        for ch in racine.findall("channel"):
            for dn in ch.findall("display-name"):
                index.setdefault(norm(dn.text), ch.get("id"))
            index.setdefault(norm(ch.get("id")), ch.get("id"))
        ids_source = {ch.get("id") for ch in racine.findall("channel")}
        choisis = {}
        for cid, nom in list(restant.items()):
            src = manuel.get(nom) if manuel.get(nom) in ids_source else None
            if not src:
                src = index.get(norm(nom))
            if not src and norm(nom).endswith(" fr"):
                src = index.get(norm(nom)[:-3])
            if src:
                choisis[src] = cid
                trouve[cid] = (url, src)
                del restant[cid]
        for p in racine.findall("programme"):
            cid = choisis.get(p.get("channel"))
            if not cid:
                continue
            try:
                d, f = heure(p.get("start")), heure(p.get("stop"))
            except Exception:
                continue
            if f < maintenant - timedelta(hours=2) or d > fin:
                continue
            p.set("channel", cid)
            programmes.setdefault(cid, []).append(p)

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


if __name__ == "__main__":
    main("--rapport" in sys.argv)
