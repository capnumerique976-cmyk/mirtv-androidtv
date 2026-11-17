#!/usr/bin/env python3
"""MirTV — ajoute (ou retire) les chaînes de TEST INTERNE de chaines-test-fast.csv dans le registre.

Usage (en basania, propriétaire du registre) :
  python3 ajouter-chaines-test.py            ajoute les chaînes absentes (statut « essai »)
  python3 ajouter-chaines-test.py --retirer  retire toutes les chaînes « TEST INTERNE »
Puis : cd ~/mirtv/scripts/mirtv-admin && sudo python3 -B synchro.py
Une copie du registre est gardée à côté (registre-chaines.csv.avant-test-<date>).
"""
import csv, datetime, os, shutil, sys

ICI = os.path.dirname(os.path.abspath(__file__))
REGISTRE = os.environ.get("MIRTV_REGISTRE_CSV", "/opt/mirtv/registre/registre-chaines.csv")
COLONNES = ["numero", "nom", "categorie", "enfants", "url_source", "page_officielle", "type",
            "preuve", "date_verification", "verifie_par", "statut", "notes", "logo"]
MARQUE = "TEST INTERNE"


def lire():
    with open(REGISTRE, encoding="utf-8") as f:
        lignes = [l for l in csv.reader(f, delimiter=";")]
    entete, corps = lignes[0], [l for l in lignes[1:] if any(x.strip() for x in l)]
    return entete, [dict(zip(COLONNES, (l + [""] * len(COLONNES))[:len(COLONNES)])) for l in corps]


def ecrire(chaines):
    jour = datetime.date.today().strftime("%Y%m%d")
    shutil.copy2(REGISTRE, f"{REGISTRE}.avant-test-{jour}")
    tmp = REGISTRE + ".tmp"
    with open(tmp, "w", encoding="utf-8", newline="") as f:
        f.write(";".join(COLONNES) + "\n")
        for c in chaines:
            f.write(";".join(" ".join(str(c.get(k, "")).replace(";", ",").split()) for k in COLONNES) + "\n")
    os.replace(tmp, REGISTRE)


def main():
    _, chaines = lire()
    if "--retirer" in sys.argv:
        reste = [c for c in chaines if not c["notes"].startswith(MARQUE)]
        ecrire(reste)
        print(f"{len(chaines) - len(reste)} chaîne(s) de test retirée(s). Lancez la synchro.")
        return 0
    urls = {c["url_source"] for c in chaines}
    noms = {c["nom"].lower() for c in chaines}
    numeros = [int(c["numero"]) for c in chaines if c["numero"].isdigit()]
    numero = max([200] + [n + 1 for n in numeros if n >= 200])
    ajout = 0
    with open(os.path.join(ICI, "chaines-test-fast.csv"), encoding="utf-8") as f:
        for l in csv.reader((x for x in f if not x.startswith("#")), delimiter=";"):
            if l[0] == "nom" or len(l) < 5:
                continue
            nom, categorie, enfants, url, page = l[:5]
            if url in urls or nom.lower() in noms:
                continue
            chaines.append({"numero": str(numero), "nom": nom, "categorie": categorie, "enfants": enfants,
                            "url_source": url, "page_officielle": page, "type": "gratuit-officiel", "preuve": "",
                            "date_verification": datetime.date.today().isoformat(),
                            "verifie_par": "Claude (lecture testée dans le navigateur)", "statut": "essai",
                            "notes": f"{MARQUE} : chaîne gratuite Pluto TV / Rakuten TV (liste iptv-org). À retirer avant l'ouverture au public.",
                            "logo": ""})
            numero += 1
            ajout += 1
    ecrire(chaines)
    print(f"{ajout} chaîne(s) de test ajoutée(s) (statut essai). Lancez la synchro.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
