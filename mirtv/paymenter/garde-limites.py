#!/usr/bin/env python3
"""MirTV — la garde des écrans lit aussi la limite de chaque famille payante (écrite par Paymenter).
Fichier : /opt/mirtv/paymenter/partage/limites-familles.json  {"1001": 2, ...}
Usage : sudo python3 garde-limites.py /opt/mirtv/garde/garde.py   (relançable)
"""
import sys

p = sys.argv[1]
s = open(p, encoding="utf-8").read()
if "limites-familles.json" in s:
    print(f"{p} : déjà à jour")
    sys.exit(0)
avant = '    r.setdefault("limites_familles", {})\n'
apres = avant + '''    try:  # limites des familles payantes (Paymenter, mirtv/paymenter)
        r["limites_familles"] = {**json.loads(Path("/opt/mirtv/paymenter/partage/limites-familles.json").read_text(encoding="utf-8")), **r["limites_familles"]}
    except (OSError, ValueError):
        pass
'''
if avant not in s:
    print(f"{p} : passage introuvable, rien n'est modifié")
    sys.exit(1)
open(p, "w", encoding="utf-8").write(s.replace(avant, apres, 1))
print(f"{p} : limites des familles payantes prises en compte")
