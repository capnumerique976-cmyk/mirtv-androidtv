#!/usr/bin/env python3
"""MirTV — ne garde que les chaînes en français ou en anglais (demande de Ravi du 18/11/2026).

Langue de chaque chaîne du registre :
  - chaînes importées d'iptv-org (notes « TEST INTERNE iptv-org : <id>/<déclinaison> ») : langues de la déclinaison
    dans feeds.json d'iptv-org (sinon, la déclinaison principale de la chaîne) ;
  - autres chaînes : la langue écrite entre parenthèses dans le nom (« Euronews (allemand) »), sinon français.
Une chaîne est gardée si le français ou l'anglais fait partie de ses langues. Langue inconnue : gardée (et signalée).

Usage (en basania, propriétaire du registre) :
  python3 garder-langues.py              montre ce qui serait retiré, sans rien changer
  python3 garder-langues.py --appliquer  retire ces chaînes (copie du registre gardée à côté)
Puis : cd ~/mirtv/scripts/mirtv-admin && sudo python3 -B synchro.py
"""
import json, os, re, shutil, sys, unicodedata, urllib.request
from datetime import datetime

REGISTRE = os.environ.get("MIRTV_REGISTRE_CSV", "/opt/mirtv/registre/registre-chaines.csv")
API = os.environ.get("MIRTV_IPTV_API", "https://iptv-org.github.io/api/")
GARDER = {"fra", "eng"}
SUFFIXES = {"francais": "fra", "anglais": "eng", "allemand": "deu", "grec": "ell", "hongrois": "hun", "italien": "ita",
            "portugais": "por", "russe": "rus", "espagnol": "spa", "arabe": "ara", "persan": "fas", "turc": "tur",
            "roumain": "ron", "polonais": "pol", "neerlandais": "nld", "chinois": "zho", "japonais": "jpn"}
NOMS = {"fra": "français", "eng": "anglais", "deu": "allemand", "ell": "grec", "hun": "hongrois", "ita": "italien",
        "por": "portugais", "rus": "russe", "spa": "espagnol", "ara": "arabe", "fas": "persan", "tur": "turc",
        "ron": "roumain", "pol": "polonais", "nld": "néerlandais", "zho": "chinois", "jpn": "japonais", "kur": "kurde",
        "swe": "suédois", "ukr": "ukrainien", "bul": "bulgare", "ces": "tchèque", "hrv": "croate", "srp": "serbe",
        "heb": "hébreu", "hin": "hindi", "kor": "coréen", "dan": "danois", "nor": "norvégien", "fin": "finnois",
        "slk": "slovaque", "slv": "slovène", "aze": "azéri", "kat": "géorgien", "vie": "vietnamien", "tha": "thaï",
        "ind": "indonésien", "msa": "malais", "mlg": "malgache", "swa": "swahili", "hat": "créole haïtien",
        "wol": "wolof", "lin": "lingala", "bam": "bambara", "cat": "catalan", "eus": "basque", "glg": "galicien"}


def sans_accents(s):
    return unicodedata.normalize("NFKD", s or "").encode("ascii", "ignore").decode().lower()


def json_api(nom):
    req = urllib.request.Request(API + nom, headers={"User-Agent": "MirTV/1.0"})
    with urllib.request.urlopen(req, timeout=120) as r:
        return json.load(r)


def main():
    appliquer = "--appliquer" in sys.argv
    feeds = json_api("feeds.json")
    par_feed, principale = {}, {}
    for f in feeds:
        langues = set(f.get("languages") or [])
        par_feed[(f["channel"], f["id"].lower())] = langues
        if f.get("is_main"):
            principale[f["channel"]] = langues

    # lignes brutes (le registre est écrit sans guillemets, « ; » entre les colonnes) : recopiées telles quelles
    lignes = open(REGISTRE, encoding="utf-8").read().splitlines()
    tete = lignes[0].split(";")
    i_nom, i_notes = tete.index("nom"), tete.index("notes")
    garde, retire, inconnues = [lignes[0]], [], []
    for brute in lignes[1:]:
        l = brute.split(";")
        if len(l) <= i_notes:
            garde.append(brute)
            continue
        nom, notes = l[i_nom], l[i_notes]
        m = re.search(r"iptv-org : ([^\s/(]+)(?:/(\S+))? \(", notes)
        if m:
            ident, decl = m.group(1), (m.group(2) or "").lower()
            langues = par_feed.get((ident, decl)) or principale.get(ident) or set()
        else:
            s = re.search(r"\((\w+)\)\s*$", sans_accents(nom))
            langues = {SUFFIXES[s.group(1)]} if s and s.group(1) in SUFFIXES else {"fra"}
        if not langues:
            inconnues.append(nom)
            garde.append(brute)
        elif langues & GARDER:
            garde.append(brute)
        else:
            retire.append((nom, ", ".join(sorted(NOMS.get(x, x) for x in langues))))

    print(f"Registre : {len(lignes) - 1} chaînes ; gardées (français ou anglais) : {len(garde) - 1} ; à retirer : {len(retire)}")
    par_langue = {}
    for _, lg in retire:
        par_langue[lg] = par_langue.get(lg, 0) + 1
    print("Retirées par langue : " + " | ".join(f"{k} {v}" for k, v in sorted(par_langue.items(), key=lambda x: -x[1])))
    if inconnues:
        print(f"Langue inconnue (gardées) : {len(inconnues)} — " + ", ".join(inconnues[:15]))
    if "--liste" in sys.argv:
        for nom, lg in retire:
            print(f"  - {nom} ({lg})")
    if not appliquer:
        print("Rien n'a changé. Pour retirer : python3 garder-langues.py --appliquer")
        return
    copie = f"{REGISTRE}.avant-langues-{datetime.now():%Y%m%d-%H%M}"
    shutil.copy2(REGISTRE, copie)
    with open(REGISTRE + ".tmp", "w", encoding="utf-8", newline="") as f:
        f.write("\n".join(garde) + "\n")
    shutil.copymode(REGISTRE, REGISTRE + ".tmp")
    os.replace(REGISTRE + ".tmp", REGISTRE)
    print(f"{len(retire)} chaîne(s) retirée(s). Copie de l'ancien registre : {copie}")
    print("Lancez la synchro : cd ~/mirtv/scripts/mirtv-admin && sudo python3 -B synchro.py")


if __name__ == "__main__":
    main()
