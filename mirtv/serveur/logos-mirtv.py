#!/usr/bin/env python3
"""MirTV — logos des chaînes pour l'appli web.

Cherche le logo officiel de chaque chaîne MirTV dans la base libre iptv-org (channels.json + logos.json),
en garde une copie sur le serveur (DEST) et écrit DEST/logos.json : {"nom de la chaîne": "fichier"}.
L'appli web lit https://mirtv.rahiss.com/tv/logos/logos.json.
Associations manuelles : logos-associations.csv (nom MirTV;identifiant iptv-org), à côté de ce script.
Usage : sudo python3 logos-mirtv.py   (relançable)
"""
import csv, hashlib, importlib.util, json, os, re, sys, urllib.request

ICI = os.path.dirname(os.path.abspath(__file__))
DEST = os.environ.get("MIRTV_LOGOS", "/opt/mirtv/images-web/logos")
API = "https://iptv-org.github.io/api/"
TVLOGOS_API = "https://api.github.com/repos/tv-logo/tv-logos/contents/countries/"

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

    cache = {}

    def charger(url):
        """Contenu du logo, ou None. Les images « supprimée » des hébergeurs (renvoi vers removed.png…) sont refusées."""
        if url not in cache:
            try:
                req = urllib.request.Request(url, headers={"User-Agent": "MirTV-logos/1.0"})
                with urllib.request.urlopen(req, timeout=30) as r:
                    data = r.read(3_000_000)
                    fin = r.geturl()
                cache[url] = None if len(data) < 200 or "removed" in fin.lower() else data
            except Exception as e:
                print(f"  logo non téléchargé ({url[:60]}) : {e}", file=sys.stderr)
                cache[url] = None
        return cache[url]

    # Logo noté dans le registre des chaînes (colonne « logo », ex. import iptv-org) : prioritaire
    du_registre = {}
    try:
        with open(os.environ.get("MIRTV_REGISTRE_CSV", "/opt/mirtv/registre/registre-chaines.csv"), encoding="utf-8") as f:
            for l in csv.DictReader(f, delimiter=";"):
                if (l.get("logo") or "").startswith("https://"):
                    du_registre[guide.norm(l.get("nom") or "")] = l["logo"]
    except OSError:
        pass

    # Logos possibles de chaque chaîne (le meilleur d'abord)
    choix = []
    for _, nom in chaines:
        ident = manuel.get(nom)
        sans_langue = guide.norm(re.sub(r"\(.*?\)", " ", nom))  # « France 24 (anglais) » → même logo que France 24
        for cand in ([] if ident else guide.candidats(nom) + [sans_langue]):
            ident = index.get(cand)
            if ident:
                break
        urls = [u for _, u in sorted(meilleurs.get(ident, []), reverse=True) if u.startswith("https://")] if ident else []
        if ident and ident.startswith("https://"):  # association manuelle vers un fichier précis
            urls = [ident]
        elif du_registre.get(guide.norm(nom)) and ident != "-":
            urls = [du_registre[guide.norm(nom)]] + [u for u in urls if u != du_registre[guide.norm(nom)]]
            ident = ident or "registre"
        choix.append((nom, ident, sans_langue, urls))

    # Image « logo absent » d'un hébergeur : le même fichier revient pour des chaînes sans rapport → refusé
    vus = {}
    for nom, ident, base, urls in choix:
        for url in urls[:2]:
            data = charger(url)
            if data:
                vus.setdefault(hashlib.sha1(data).hexdigest(), set()).add(base)
    faux = {h for h, bases in vus.items() if len(bases) >= 3}

    tvlogos = {}
    for dossier in ("france", "international"):
        try:
            req = urllib.request.Request(TVLOGOS_API + dossier, headers={"User-Agent": "MirTV-logos/1.0"})
            with urllib.request.urlopen(req, timeout=60) as r:
                for f in json.load(r):
                    nom_f = re.sub(r"-(fr|int)$", "", f["name"].rsplit(".", 1)[0]).replace("-", " ")
                    for cle in (guide.norm(nom_f), guide.norm(nom_f).replace(" ", "")):
                        tvlogos.setdefault(cle, f["download_url"])
        except Exception as e:
            print(f"  tv-logos indisponible ({dossier}) : {e}", file=sys.stderr)

    resultat, sans = {}, []
    for nom, ident, base, urls in choix:
        fichier = None
        for url in urls:
            data = charger(url)
            if not data or hashlib.sha1(data).hexdigest() in faux:
                continue
            ext = os.path.splitext(url.split("?")[0])[1].lower()
            ext = ext if ext in (".png", ".svg", ".webp", ".jpg", ".jpeg") else ".png"
            fichier = f"{slug(nom)}-{hashlib.sha1(data).hexdigest()[:8]}{ext}"  # nom qui change avec le logo : jamais d'ancien logo en cache
            with open(os.path.join(DEST, fichier), "wb") as f:
                f.write(data)
            break
        if not fichier and ident != "-":
            # Deuxième source : dépôt libre tv-logo/tv-logos (logos pour fond sombre)
            for cand in guide.candidats(nom) + [base]:
                url = tvlogos.get(cand) or tvlogos.get(cand.replace(" ", ""))
                data = charger(url) if url else None
                if data:
                    fichier = f"{slug(nom)}-{hashlib.sha1(data).hexdigest()[:8]}.png"
                    with open(os.path.join(DEST, fichier), "wb") as f:
                        f.write(data)
                    ident = url.rsplit("/", 1)[-1]
                    break
        if not fichier and du_registre.get(guide.norm(nom)):
            # Hébergeur qui refuse le serveur (imgur renvoie une image « indisponible ») : l'appli prend le logo
            # directement chez lui (les navigateurs des familles y ont accès)
            resultat[nom] = du_registre[guide.norm(nom)]
            print(f"  LIEN {nom} ← {resultat[nom][:60]}")
            continue
        if not fichier:
            sans.append(nom)
            continue
        resultat[nom] = fichier
        print(f"  OK   {nom} ← {ident}")
    for f in os.listdir(DEST):  # ménage : anciens logos qui ne servent plus
        if f != "logos.json" and f not in resultat.values():  # (les liens complets ne sont pas des fichiers)
            os.remove(os.path.join(DEST, f))
    with open(os.path.join(DEST, "logos.json"), "w", encoding="utf-8") as f:
        json.dump(resultat, f, ensure_ascii=False, indent=0)
    for nom in sans:
        print(f"  ---  {nom}")
    print(f"Logos : {len(resultat)} sur {len(chaines)} dans {DEST}")


if __name__ == "__main__":
    main()
