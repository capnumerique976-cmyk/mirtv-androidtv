#!/usr/bin/env python3
"""MirTV — vignettes des chaînes : une image prise sur chaque chaîne en direct (comme sur Apple TV).

Pour chaque chaîne de MirTV (liste M3U de Dispatcharr), ffmpeg ouvre la source quelques secondes
et garde une image (640 px de large). Les images vont dans DEST, avec DEST/apercus.json :
{"nom de la chaîne": "fichier.jpg"}. L'appli web les lit sur https://mirtv.rahiss.com/tv/apercus/.
Une chaîne qui ne répond pas garde son image précédente pendant 1 jour.
Lancé toutes les 20 minutes par mirtv-apercus.timer (installer-apercus.sh).
Usage : sudo python3 apercus-mirtv.py [--rapport]
"""
import concurrent.futures as cf, hashlib, json, os, re, shutil, subprocess, sys, tempfile, time, urllib.request

DISPATCHARR = os.environ.get("MIRTV_DISPATCHARR", "http://127.0.0.1:9191")
DEST = os.environ.get("MIRTV_APERCUS", "/opt/mirtv/images-web/apercus")
GARDER = 24 * 3600  # une image trop vieille n'est plus montrée
FFMPEG = shutil.which("ffmpeg") or "/usr/bin/ffmpeg"


def chaines():
    with urllib.request.urlopen(DISPATCHARR + "/output/m3u?direct=true", timeout=30) as r:
        m3u = r.read().decode("utf-8", "replace")
    return re.findall(r'tvg-name="([^"]*)"[^\n]*\n(\S+)', m3u)


def slug(s):
    import unicodedata
    s = unicodedata.normalize("NFKD", s).encode("ascii", "ignore").decode().lower()
    return re.sub(r"[^a-z0-9]+", "-", s).strip("-") or "chaine"


def prendre(nom, url):
    """Image JPEG prise environ 3 s après le début, ou None. Une image presque noire est refusée."""
    with tempfile.TemporaryDirectory() as d:
        sortie = os.path.join(d, "a.jpg")
        for depart in ("3", "8"):
            cmd = [FFMPEG, "-nostdin", "-loglevel", "error", "-rw_timeout", "15000000",
                   "-user_agent", "Mozilla/5.0 (MirTV)", "-i", url, "-ss", depart, "-frames:v", "1",
                   "-vf", "scale=640:-2", "-q:v", "5", "-y", sortie]
            try:
                subprocess.run(cmd, timeout=40, capture_output=True)
            except subprocess.TimeoutExpired:
                return None
            if os.path.exists(sortie) and os.path.getsize(sortie) > 9000:  # en dessous : image noire ou unie
                with open(sortie, "rb") as f:
                    return f.read()
    return None


def main():
    os.makedirs(DEST, exist_ok=True)
    index_p = os.path.join(DEST, "apercus.json")
    try:
        with open(index_p, encoding="utf-8") as f:
            ancien = json.load(f)
    except (OSError, ValueError):
        ancien = {}
    liste = chaines()
    resultat, rate = {}, []
    with cf.ThreadPoolExecutor(max_workers=4) as ex:
        for (nom, url), data in zip(liste, ex.map(lambda c: prendre(*c), liste)):
            if data:
                fichier = f"{slug(nom)}-{hashlib.sha1(data).hexdigest()[:8]}.jpg"
                with open(os.path.join(DEST, fichier), "wb") as f:
                    f.write(data)
                resultat[nom] = fichier
            else:
                rate.append(nom)
                vieux = ancien.get(nom)
                if vieux and os.path.exists(os.path.join(DEST, vieux)) and time.time() - os.path.getmtime(os.path.join(DEST, vieux)) < GARDER:
                    resultat[nom] = vieux
    tmp = index_p + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(resultat, f, ensure_ascii=False)
    os.replace(tmp, index_p)
    for f in os.listdir(DEST):  # ménage
        if f.endswith(".jpg") and f not in resultat.values():
            os.remove(os.path.join(DEST, f))
    print(f"Vignettes : {len(resultat)} sur {len(liste)} chaînes")
    if "--rapport" in sys.argv:
        for nom in rate:
            print(f"  ---  {nom}")


if __name__ == "__main__":
    main()
