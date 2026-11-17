#!/usr/bin/env python3
"""MirTV — apprend à mirtv-garde les comptes sans préfixe « f901- » (ex. irchad).
La famille de ces comptes est lue dans /opt/mirtv/registre/familles.json (écrit par comptes-test.py).
Usage : sudo python3 garde-familles.py /chemin/vers/garde.py   (relançable : ne modifie qu'une fois)
"""
import sys

p = sys.argv[1]
s = open(p, encoding="utf-8").read()
if "familles.json" in s:
    print(f"{p} : déjà à jour")
    sys.exit(0)
remplacements = [
    ('FAMILLE = re.compile(r"^f(\\d+)-")\n',
     'FAMILLE = re.compile(r"^f(\\d+)-")\nMEMBRES = Path("/opt/mirtv/registre/familles.json")  # comptes sans préfixe : nom -> famille\n'),
    ("    familles: dict[str, list] = {}\n",
     "    familles: dict[str, list] = {}\n    try:\n        membres = json.loads(MEMBRES.read_text(encoding=\"utf-8\"))\n    except (OSError, ValueError):\n        membres = {}\n"),
    ("        m = FAMILLE.match(nom)\n        if not item or not m:\n            continue\n",
     "        m = FAMILLE.match(nom)\n        fam = m.group(1) if m else membres.get(nom.lower())\n        if not item or not fam:\n            continue\n"),
    ("        familles.setdefault(m.group(1), [])", "        familles.setdefault(fam, [])"),
]
for avant, apres in remplacements:
    if avant not in s:
        print(f"{p} : passage introuvable, rien n'est modifié ({avant.strip()[:40]}…)")
        sys.exit(1)
    s = s.replace(avant, apres, 1)
open(p, "w", encoding="utf-8").write(s)
print(f"{p} : comptes sans préfixe pris en compte")
