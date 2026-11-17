#!/usr/bin/env python3
"""MirTV — logos des chaînes pour l'appli web.

Cherche le logo officiel de chaque chaîne MirTV dans la base libre iptv-org (channels.json + logos.json),
en garde une copie sur le serveur (DEST) et écrit DEST/logos.json : {"nom de la chaîne": "fichier"}.
L'appli web lit https://mirtv.rahiss.com/tv/logos/logos.json.
Associations manuelles : logos-associations.csv (nom MirTV;identifiant iptv-org), à côté de ce script.
Usage : sudo python3 logos-mirtv.py   (relançable ; ne retélécharge pas un logo déjà présent)
"""
import csv, importlib.util, json, os, re, sys, urllib.request

ICI = os.path.dirname(os.path.abspath(__file__))
DEST = os.environ.get("MIRTV_LOGOS", "/opt/mirtv/images-web/logos")
API = "https://iptv-org.github.io/api/"

spec = importlib.util.spec_from_file_location("guide", os.path.join(ICI, "guide-mirtv.py"))
guide = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guide)


def json_url(nom):
    req = urllib.request.Request(API + nom, headers={"User-Agent": "MirTV-logos/1.0"})
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.load(r)


def slug(s):
    return re.sub(r"[^a-z0-9]+", "-", guide.norm(s)).strip("-") or "chaine"


def main():
    os.makedirs(DEST, exist_ok=True)
    chaines = guide.nos_chaines()
    canaux = json_url("channels.json")
    logos = json_url("logos.json")
    manuel = {}
    p = os.path.join(ICI, "logos-associations.csv")
    if os.path.exists(p):
        with open(p, encoding="utf-8") as f:
            manuel = {l[0].strip(): l[1].strip() for l in csv.reader(f, delimiter=";") if len(l) >= 2 and not l[0].startswith("#")}

    index = {}
    for c in canaux:
        if c.get("closed"):
            continue
        for n in [c.get("name", "")] + (c.get("alt_names") or []):
            index.setdefault(guide.norm(n), c["id"])
    meilleurs = {}  # identifiant iptv-org -> meilleur logo (sans fond de préférence, le plus large)
    for l in logos:
        if l.get("feed"):
            continue
        score = (l.get("format") in ("PNG", "SVG", "WebP"), "picons" not in (l.get("tags") or []), l.get("width") or 0)
        meilleurs.setdefault(l["channel"], []).append((score, l["url"]))

    resultat, sans = {}, []
    for _, nom in chaines:
        ident = manuel.get(nom)
        sans_langue = guide.norm(re.sub(r"\(.*?\)", " ", nom))  # « France 24 (anglais) » → même logo que France 24
        for cand in ([] if ident else guide.candidats(nom) + [sans_langue]):
            ident = index.get(cand)
            if ident:
                break
        urls = [u for _, u in sorted(meilleurs.get(ident, []), reverse=True) if u.startswith("https://")] if ident else []
        fichier = None
        for url in urls:  # le meilleur logo d'abord, le suivant s'il ne se télécharge pas
            ext = os.path.splitext(url.split("?")[0])[1].lower()
            ext = ext if ext in (".png", ".svg", ".webp", ".jpg", ".jpeg") else ".png"
            essai = slug(nom) + ext
            chemin = os.path.join(DEST, essai)
            if not os.path.exists(chemin):
                try:
                    req = urllib.request.Request(url, headers={"User-Agent": "MirTV-logos/1.0"})
                    with urllib.request.urlopen(req, timeout=30) as r:
                        data = r.read(3_000_000)
                    if len(data) < 200:
                        raise ValueError("fichier vide")
                    with open(chemin, "wb") as f:
                        f.write(data)
                except Exception as e:
                    print(f"  logo non téléchargé pour {nom} : {e}", file=sys.stderr)
                    continue
            fichier = essai
            break
        if not fichier:
            sans.append(nom)
            continue
        resultat[nom] = fichier
        print(f"  OK   {nom} ← {ident}")
    with open(os.path.join(DEST, "logos.json"), "w", encoding="utf-8") as f:
        json.dump(resultat, f, ensure_ascii=False, indent=0)
    for nom in sans:
        print(f"  ---  {nom}")
    print(f"Logos : {len(resultat)} sur {len(chaines)} dans {DEST}")


if __name__ == "__main__":
    main()
