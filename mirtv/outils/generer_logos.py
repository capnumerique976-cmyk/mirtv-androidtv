"""MirTV — génère les logos vectoriels Android (icône, bannière TV, logo, icône de réglages)
à partir de la police Lexend ExtraBold (licence OFL) et des couleurs de la charte MirTV.

Usage (depuis la racine du dépôt) : python3 mirtv/outils/generer_logos.py
Dépendance : fontTools (pip install fonttools). Les fichiers produits sont versionnés :
ce script n'est utile que pour les régénérer.
"""
from pathlib import Path

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont

RACINE = Path(__file__).resolve().parents[2]
POLICE = RACINE / "app/src/main/res/font/lexend_extrabold.ttf"
RES = RACINE / "app/src/main/res"

NUIT = "#FF120E36"
MANGUE = "#FFFF9F1C"
BLANC = "#FFFFFFFF"


def chemins_texte(texte_couleurs, x, y_ligne, taille):
    """Renvoie [(pathData, couleur)] pour une suite de (texte, couleur), ligne de base y_ligne."""
    police = TTFont(POLICE)
    glyphes = police.getGlyphSet()
    cmap = police.getBestCmap()
    upm = police["head"].unitsPerEm
    echelle = taille / upm
    sortie = []
    for texte, couleur in texte_couleurs:
        stylo = SVGPathPen(glyphes, ntos=lambda v: f"{v:.2f}".rstrip("0").rstrip("."))
        for car in texte:
            nom = cmap[ord(car)]
            # (sx, 0, 0, -sy, dx, dy) : retourne l'axe vertical (les polices montent, l'écran descend)
            tp = TransformPen(stylo, (echelle, 0, 0, -echelle, x, y_ligne))
            glyphes[nom].draw(tp)
            x += glyphes[nom].width * echelle
        sortie.append((stylo.getCommands(), couleur))
    return sortie, x


def largeur_texte(texte, taille):
    police = TTFont(POLICE)
    glyphes = police.getGlyphSet()
    cmap = police.getBestCmap()
    return sum(glyphes[cmap[ord(c)]].width for c in texte) * taille / police["head"].unitsPerEm


def n(v):
    """Nombre court pour pathData (2 décimales au plus)."""
    return f"{v:.2f}".rstrip("0").rstrip(".")


def rect_arrondi(x, y, l, h, r):
    return (f"M{n(x + r)},{n(y)}h{n(l - 2 * r)}a{n(r)},{n(r)} 0,0 1,{n(r)},{n(r)}v{n(h - 2 * r)}"
            f"a{n(r)},{n(r)} 0,0 1,{n(-r)},{n(r)}h{n(-(l - 2 * r))}a{n(r)},{n(r)} 0,0 1,{n(-r)},{n(-r)}"
            f"v{n(-(h - 2 * r))}a{n(r)},{n(r)} 0,0 1,{n(r)},{n(-r)}z")


def triangle_lecture(cx, cy, t):
    """Triangle « lecture » centré (cx, cy), hauteur t."""
    return f"M{n(cx - t * 0.38)},{n(cy - t / 2)}L{n(cx + t * 0.5)},{n(cy)}L{n(cx - t * 0.38)},{n(cy + t / 2)}z"


def vecteur(largeur, hauteur, chemins, dp=None, teinte=None):
    lw, lh = dp or (largeur, hauteur)
    t = f'\n    android:tint="{teinte}"' if teinte else ""
    corps = "".join(f'\n    <path\n        android:fillColor="{c}"\n        android:pathData="{p}" />' for p, c in chemins)
    return (f'<?xml version="1.0" encoding="utf-8"?>\n<!-- MirTV : fichier généré par mirtv/outils/generer_logos.py -->\n'
            f'<vector xmlns:android="http://schemas.android.com/apk/res/android"\n'
            f'    android:width="{lw}dp"\n    android:height="{lh}dp"{t}\n'
            f'    android:viewportWidth="{largeur}"\n    android:viewportHeight="{hauteur}">{corps}\n</vector>\n')


def logo(x, y, hauteur):
    """Carré mangue + « Mir » blanc + « TV » mangue ; (x, y) = coin haut gauche, hauteur totale."""
    cote = hauteur
    chemins = [(rect_arrondi(x, y, cote, cote, cote * 0.23), MANGUE),
               (triangle_lecture(x + cote * 0.52, y + cote / 2, cote * 0.46), NUIT)]
    taille = hauteur * 1.05
    textes, fin = chemins_texte([("Mir", BLANC), ("TV", MANGUE)], x + cote * 1.25, y + hauteur * 0.86, taille)
    return chemins + textes, fin


def main():
    d = RES / "drawable"
    # Logo (barre du haut, écran de démarrage, économiseur d'écran) : 252 x 72
    chemins, _ = logo(8, 14, 44)
    (d / "app_logo.xml").write_text(vecteur(252, 72, chemins), encoding="utf-8")
    # Bannière Android TV 320 x 180 : fond Nuit, logo centré
    largeur = 60 * 1.25 + largeur_texte("MirTV", 60 * 1.05)
    chemins, _ = logo((320 - largeur) / 2, 60, 60)
    (d / "app_banner_foreground.xml").write_text(vecteur(320, 180, chemins), encoding="utf-8")
    (d / "app_banner_background.xml").write_text(vecteur(320, 180, [("M0,0h320v180h-320z", NUIT)]), encoding="utf-8")
    # Icône adaptative 108 x 108 (zone sûre : cercle de 66 au centre)
    (d / "app_icon_background.xml").write_text(vecteur(108, 108, [("M0,0h108v108h-108z", NUIT)]), encoding="utf-8")
    icone = [(rect_arrondi(31, 31, 46, 46, 11), MANGUE), (triangle_lecture(55, 54, 22), NUIT)]
    (d / "app_icon_foreground.xml").write_text(vecteur(108, 108, icone), encoding="utf-8")
    mono = [(rect_arrondi(31, 31, 46, 46, 11) + triangle_lecture(55, 54, 22), "#FFFFFFFF")]
    texte_mono = vecteur(108, 108, mono).replace("<path", '<path\n        android:fillType="evenOdd"', 1)
    (d / "app_icon_foreground_monochrome.xml").write_text(texte_mono, encoding="utf-8")
    # Petite icône des réglages (remplace le logo d'origine), 24 dp, teintée en blanc
    petite = [(rect_arrondi(16, 16, 224, 224, 52) + triangle_lecture(132, 128, 110), "#FFFFFFFF")]
    texte_petite = vecteur(256, 256, petite, dp=(24, 24), teinte="#FFFFFF").replace(
        "<path", '<path\n        android:fillType="evenOdd"', 1)
    (d / "ic_jellyfin.xml").write_text(texte_petite, encoding="utf-8")
    print("Logos MirTV générés.")


if __name__ == "__main__":
    main()
