#!/usr/bin/env python3
"""MirTV — mesure de la vitesse (avant / après une optimisation).

  sudo python3 mesurer-mirtv.py            mesures en texte
  sudo python3 mesurer-mirtv.py --json     même chose en JSON

Mesure, par l'adresse publique (comme un vrai écran) :
  - les requêtes que l'appli fait au serveur télé (liste des chaînes, programmes, accueil) : durée et taille ;
  - les fichiers de l'appli (compression, mise en cache par le navigateur) ;
  - les logos et les aperçus (poids).
Utilise la clé d'administration de Jellyfin (jamais affichée) et le premier profil famille trouvé.
"""
import gzip, json, os, statistics, sys, time, urllib.request
from datetime import datetime, timedelta, timezone

sys.path.insert(0, "/opt/mirtv/bin")
SITE = os.environ.get("MIRTV_SITE", "https://mirtv.rahiss.com")
ESSAIS = 3


def requete(url, entetes=None, compresse=True):
    h = {"User-Agent": "MirTV-mesure/1.0", **(entetes or {})}
    if compresse:
        h["Accept-Encoding"] = "gzip"
    t0 = time.perf_counter()
    with urllib.request.urlopen(urllib.request.Request(url, headers=h), timeout=60) as r:
        corps = r.read()
        info = {"code": r.status, "octets_reseau": len(corps), "encodage": r.headers.get("Content-Encoding", "-"),
                "cache": r.headers.get("Cache-Control", "-"), "etag": bool(r.headers.get("ETag") or r.headers.get("Last-Modified"))}
    info["ms"] = round((time.perf_counter() - t0) * 1000)
    if info["encodage"] == "gzip":
        corps = gzip.decompress(corps)
    info["octets"] = len(corps)
    info["_corps"] = corps
    return info


def mesurer(url, entetes=None, essais=ESSAIS):
    res = [requete(url, entetes) for _ in range(essais)]
    m = res[-1]
    m["ms_median"] = round(statistics.median(r["ms"] for r in res))
    m["ms_premier"] = res[0]["ms"]
    return m


def main():
    import mirtv_api as api
    jeton = api.cle_api()
    auth = {"Authorization": f'MediaBrowser Client="MirTV-mesure", Device="serveur", DeviceId="mesure", Version="1", Token="{jeton}"'}
    _, users = api.appel("GET", "/Users", token=jeton)
    profil = next(u for u in users if not u.get("Policy", {}).get("IsAdministrator") and not u.get("Policy", {}).get("IsDisabled"))
    uid = profil["Id"]
    j = SITE + "/jellyfin" if os.environ.get("MIRTV_PREFIXE") else SITE
    maintenant = datetime.now(timezone.utc)
    demain = maintenant + timedelta(hours=24)
    iso = lambda d: d.strftime("%Y-%m-%dT%H:%M:%S.000Z")
    tests = [
        ("Liste des chaînes + programme en cours (chaque écran)",
         f"{j}/LiveTv/Channels?UserId={uid}&AddCurrentProgram=true&Fields=Tags&EnableImages=true&SortBy=SortName"),
        ("Liste des chaînes sans programme en cours",
         f"{j}/LiveTv/Channels?UserId={uid}&AddCurrentProgram=false&Fields=Tags&EnableImages=false&SortBy=SortName"),
        ("Programme 24 h (écran « Le programme »)",
         f"{j}/LiveTv/Programs?UserId={uid}&MinEndDate={iso(maintenant)}&MaxStartDate={iso(demain)}&SortBy=StartDate&EnableImages=false&Fields=Overview"),
        ("Accueil : vidéos à reprendre", f"{j}/Users/{uid}/Items/Resume?MediaTypes=Video&Limit=3"),
        ("Petite requête (ping)", f"{j}/System/Ping"),
    ]
    resultats = []
    for nom, url in tests:
        try:
            m = mesurer(url, auth)
            corps = json.loads(m.pop("_corps") or b"{}") if m["octets"] else {}
            n = len(corps.get("Items", [])) if isinstance(corps, dict) else 0
            resultats.append({"test": nom, "elements": n, **m})
        except Exception as e:
            resultats.append({"test": nom, "erreur": f"{type(e).__name__}: {e}"})
    fichiers = ["/tv/", "/tv/js/app.js", "/tv/css/mirtv.css", "/tv/vendor/hls.min.js", "/tv/logos/logos.json", "/tv/apercus/apercus.json"]
    statiques = []
    for f in fichiers:
        try:
            m = mesurer(SITE + f, essais=2)
            corps = m.pop("_corps")
            statiques.append({"fichier": f, **m})
            if f.endswith("apercus.json"):
                apercus = json.loads(corps)
            if f.endswith("logos.json"):
                logos = json.loads(corps)
        except Exception as e:
            statiques.append({"fichier": f, "erreur": f"{type(e).__name__}: {e}"})
    images = []
    try:
        echantillon = list(apercus.values())[:5]
        for nomf in echantillon:
            m = requete(f"{SITE}/tv/apercus/{urllib.request.quote(nomf)}")
            m.pop("_corps")
            images.append({"aperçu": nomf, **m})
    except Exception as e:
        images.append({"erreur": str(e)})
    try:
        echantillon = [v for v in logos.values() if isinstance(v, str) and not v.startswith("http")][:5]
        for nomf in echantillon:
            m = requete(f"{SITE}/tv/logos/{urllib.request.quote(nomf)}")
            m.pop("_corps")
            images.append({"logo": nomf, **m})
    except Exception as e:
        images.append({"erreur": str(e)})
    sortie = {"date": datetime.now().isoformat(timespec="minutes"), "api": resultats, "fichiers": statiques, "images": images}
    if "--json" in sys.argv:
        print(json.dumps(sortie, ensure_ascii=False, indent=1))
        return
    print(f"MirTV — mesures du {sortie['date']}\n")
    for r in resultats:
        if "erreur" in r:
            print(f"  ✗ {r['test']} : {r['erreur']}")
        else:
            print(f"  {r['test']} : {r['ms_median']} ms (1er : {r['ms_premier']}), {r['elements']} éléments, "
                  f"{r['octets'] // 1024} Ko ({r['octets_reseau'] // 1024} Ko envoyés, {r['encodage']})")
    print()
    for s in statiques:
        if "erreur" in s:
            print(f"  ✗ {s['fichier']} : {s['erreur']}")
        else:
            print(f"  {s['fichier']} : {s['ms_median']} ms, {s['octets'] // 1024} Ko → {s['octets_reseau'] // 1024} Ko ({s['encodage']}), cache : {s['cache']}")
    print()
    for i in images:
        if "erreur" in i:
            print(f"  ✗ {i['erreur']}")
        else:
            print(f"  {'aperçu' if 'aperçu' in i else 'logo'} : {i['octets'] // 1024} Ko, {i['ms']} ms, cache : {i['cache']}")


if __name__ == "__main__":
    main()
