#!/usr/bin/env python3
"""MirTV — logos plus légers pour l'appli web.

Pour chaque logo de DEST (PNG, JPG, WEBP) :
  - réduit à 160 px de haut au plus (480 px de large au plus), même format, même nom ;
  - calcule s'il est « clair » (texte blanc sur fond transparent) : il aura alors une plaque sombre.
Écrit DEST/logos-clairs.json (liste des fichiers clairs) : l'appli n'a plus à analyser chaque logo elle-même.
Appelé à la fin de logos-mirtv.py ; on peut aussi le lancer seul : sudo python3 optimiser-logos.py
"""
import json, os, sys
from PIL import Image

DEST = os.environ.get("MIRTV_LOGOS", "/opt/mirtv/images-web/logos")
HAUT, LARGE = 160, 480


def clair(im):
    """Même règle que l'appli : presque blanc, avec du transparent autour."""
    p = im.convert("RGBA").resize((48, 48))
    somme = n = 0
    for r, g, b, a in p.getdata():
        if a > 128:
            somme += 0.2126 * r + 0.7152 * g + 0.0722 * b
            n += 1
    return n > 0 and somme / n > 200 and n < 48 * 48 * 0.92


def optimiser(dossier=DEST):
    clairs, gagne, faits = [], 0, 0
    for f in sorted(os.listdir(dossier)):
        chemin = os.path.join(dossier, f)
        ext = f.rsplit(".", 1)[-1].lower()
        if ext not in ("png", "jpg", "jpeg", "webp") or not os.path.isfile(chemin):
            continue
        try:
            avant = os.path.getsize(chemin)
            im = Image.open(chemin)
            im.load()
            if clair(im):
                clairs.append(f)
            w, h = im.size
            ratio = min(HAUT / h, LARGE / w, 1)
            if ratio < 1 or avant > 25000:
                if ratio < 1:
                    im = im.resize((max(1, round(w * ratio)), max(1, round(h * ratio))), Image.LANCZOS)
                tmp = chemin + ".tmp"
                if ext == "png":
                    if im.mode not in ("RGBA", "LA", "P"):
                        im = im.convert("RGBA")
                    im.save(tmp, "PNG", optimize=True)
                elif ext == "webp":
                    im.save(tmp, "WEBP", quality=85, method=6)
                else:
                    im.convert("RGB").save(tmp, "JPEG", quality=85, optimize=True, progressive=True)
                apres = os.path.getsize(tmp)
                if apres < avant:
                    os.chmod(tmp, 0o644)
                    os.replace(tmp, chemin)
                    gagne += avant - apres
                    faits += 1
                else:
                    os.remove(tmp)
        except Exception as e:
            print(f"  logo illisible {f} : {e}", file=sys.stderr)
    with open(os.path.join(dossier, "logos-clairs.json"), "w", encoding="utf-8") as fh:
        json.dump(clairs, fh, ensure_ascii=False)
    os.chmod(os.path.join(dossier, "logos-clairs.json"), 0o644)
    print(f"Logos allégés : {faits} (−{gagne // 1024} Ko) ; logos clairs : {len(clairs)}")


if __name__ == "__main__":
    optimiser()
