#!/usr/bin/env python3
"""MirTV — retire du registre les chaînes dont le nom est donné (une par ligne dans un fichier).
Usage (en basania) : python3 supprimer-chaines.py liste.txt   puis la synchro.
Une copie du registre est gardée (registre-chaines.csv.avant-nettoyage-<date>).
"""
import datetime, os, shutil, sys

REGISTRE = os.environ.get("MIRTV_REGISTRE_CSV", "/opt/mirtv/registre/registre-chaines.csv")
noms = {l.strip().lower() for l in open(sys.argv[1], encoding="utf-8") if l.strip() and not l.startswith("#")}
with open(REGISTRE, encoding="utf-8") as f:
    lignes = f.read().splitlines()
garde, retire = [lignes[0]], []
for l in lignes[1:]:
    champs = l.split(";")
    if len(champs) > 1 and champs[1].strip().lower() in noms:
        retire.append(champs[1])
    else:
        garde.append(l)
shutil.copy2(REGISTRE, f"{REGISTRE}.avant-nettoyage-{datetime.datetime.now():%Y%m%d-%H%M}")
tmp = REGISTRE + ".tmp"
with open(tmp, "w", encoding="utf-8") as f:
    f.write("\n".join(garde) + "\n")
os.replace(tmp, REGISTRE)
print(f"{len(retire)} chaîne(s) retirée(s) : " + ", ".join(retire))
introuvables = noms - {r.lower() for r in retire}
if introuvables:
    print("Introuvables : " + ", ".join(sorted(introuvables)))
