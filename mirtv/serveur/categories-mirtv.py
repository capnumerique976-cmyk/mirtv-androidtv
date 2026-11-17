#!/usr/bin/env python3
"""MirTV — nouvelles catégories (Cinéma, Séries, Animés…) et onglets de « Télé en direct ».
Met à jour synchro.py (catégorie → onglet) et registre.py (liste du back office). Relançable.
Usage : python3 categories-mirtv.py ~/mirtv/scripts/mirtv-admin
"""
import re, sys
from pathlib import Path

d = Path(sys.argv[1]).expanduser()
ONGLETS = '''ONGLETS_PAR_CATEGORIE = {
    "infos": "infos", "économie": "infos", "regional": "infos", "régional": "infos", "généraliste": "infos", "generaliste": "infos",
    "sport": "sport",
    "cinéma": "cinema", "cinema": "cinema",
    "séries": "series", "series": "series",
    "divertissement": "divertissement", "téléréalité": "divertissement", "jeux": "divertissement",
    "musique": "musique",
    "documentaires": "decouverte", "cuisine": "decouverte", "art de vivre": "decouverte", "voyages": "decouverte",
    "animés": "animes", "animes": "animes",
    "dessins animés": "enfants", "dessins animes": "enfants",
}
'''
p = d / "synchro.py"
s = p.read_text(encoding="utf-8")
s2 = re.sub(r"ONGLETS_PAR_CATEGORIE = \{.*?\n\}\n", ONGLETS, s, count=1, flags=re.S)
s2 = s2.replace("# Onglets de « Télé en direct » (4 au maximum, voir docs/03-ux-spec.md).", "# Onglets de « Télé en direct » (10 au plus ; l'appli n'affiche que ceux qui ont des chaînes).")
p.write_text(s2, encoding="utf-8")
print(f"{p} : {'mis à jour' if s2 != s else 'déjà à jour'}")

p = d / "registre.py"
s = p.read_text(encoding="utf-8")
s2 = re.sub(r"CATEGORIES = \[.*?\]\n", 'CATEGORIES = ["Infos", "Généraliste", "Régional", "Sport", "Cinéma", "Séries", "Divertissement", "Musique",\n              "Documentaires", "Cuisine", "Art de vivre", "Animés", "Dessins animés", "Culture", "Économie", "Religion"]\n', s, count=1, flags=re.S)
p.write_text(s2, encoding="utf-8")
print(f"{p} : {'mis à jour' if s2 != s else 'déjà à jour'}")
