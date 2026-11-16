"""MirTV — remplace le nom « Jellyfin » par « MirTV » dans les textes visibles de l'appli,
au moment de la compilation (les fichiers d'origine restent inchangés dans le dépôt, ce qui
simplifie la reprise des nouvelles versions de jellyfin-androidtv).

Lancé par le flux de compilation (.github/workflows/mirtv-build.yaml) avant Gradle.
Usage : python3 mirtv/outils/marque.py [--verifier]
  --verifier : n'écrit rien, affiche les textes qui contiennent encore « Jellyfin ».
"""
import re
import sys
from pathlib import Path

RACINE = Path(__file__).resolve().parents[2]
# Textes gardés tels quels : mentions de licence (obligatoires, écran « À propos »).
GARDER = {"apropos_licence"}
MOT = re.compile(r"\bJellyfin\b")
CHAINE = re.compile(r'(<string\s+name="([^"]+)"[^>]*>)(.*?)(</string>)', re.S)


def fichiers():
    for p in RACINE.glob("**/src/main/res/values*/strings*.xml"):
        if "/build/" not in str(p):
            yield p


def main(verifier: bool) -> int:
    restants = 0
    for p in fichiers():
        texte = p.read_text(encoding="utf-8")

        def remplace(m):
            nonlocal restants
            if m.group(2) in GARDER or not MOT.search(m.group(3)):
                return m.group(0)
            if verifier:
                restants += 1
                print(f"{p.relative_to(RACINE)} : {m.group(2)}")
                return m.group(0)
            return m.group(1) + MOT.sub("MirTV", m.group(3)) + m.group(4)

        nouveau = CHAINE.sub(remplace, texte)
        if not verifier and nouveau != texte:
            p.write_text(nouveau, encoding="utf-8")
    if verifier:
        print(f"{restants} texte(s) avec « Jellyfin ».")
    else:
        print("Nom MirTV appliqué aux textes de l'appli.")
    return 0


if __name__ == "__main__":
    sys.exit(main("--verifier" in sys.argv))
