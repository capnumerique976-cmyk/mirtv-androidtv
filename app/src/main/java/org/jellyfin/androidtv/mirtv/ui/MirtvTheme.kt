package org.jellyfin.androidtv.mirtv.ui

import androidx.compose.ui.graphics.Color
import androidx.compose.ui.text.TextStyle
import androidx.compose.ui.text.font.Font
import androidx.compose.ui.text.font.FontFamily
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.compose.ui.unit.sp
import org.jellyfin.androidtv.R

/**
 * MirTV : valeurs de la charte « couleurs vives » (design/tokens.json du dépôt mirtv).
 * Aucune couleur ni taille en dur ailleurs. 1920 x 1080 px = 960 x 540 dp : px / 2.
 */
object MirtvCouleurs {
	val Nuit = Color(0xFF120E36)
	val Nuit2 = Color(0xFF1F1A52)
	val Nuit3 = Color(0xFF2C2670)
	val NuitVideo = Color(0xFF0B0A1F)
	val Foret = Color(0xFF0F3A2F)
	val Foret2 = Color(0xFF1B5244)
	val Encre = Color(0xFF120E36)
	val Blanc = Color(0xFFFFFFFF)
	val Doux = Color(0xFFD6D3F5)
	val DouxForet = Color(0xFFD6F5E3)
	val Indice = Color(0xFFB9B4EA)
	val Mangue = Color(0xFFFF9F1C)
	val Corail = Color(0xFFFF5C8A)
	val Lagon = Color(0xFF22D3C5)
	val Citron = Color(0xFFFFD93D)
	val Lilas = Color(0xFFA98BFF)
	val Feuille = Color(0xFF5BE37D)
	val Ciel = Color(0xFF4CC9FF)
	val FocusHalo = Color(0x1AFFFFFF)
}

object MirtvTailles {
	// Marges (5 % des bords) : 96 px / 54 px = 48 dp / 27 dp
	val MargeHorizontale = 48.dp
	val MargeVerticale = 27.dp
	val EntreTuiles = 20.dp
	val InterneTuile = 20.dp
	val RayonTuile = 20.dp
	val RayonCarte = 18.dp
	val RayonBandeau = 20.dp
	val RayonBouton = 16.dp

	// Focus : +10 %, contour blanc 4 px décalé de 8 px, halo 18 px, 160 ms
	const val FocusEchelle = 1.1f
	val FocusContour = 2.dp
	val FocusDecalage = 4.dp
	val FocusHalo = 9.dp
	const val FocusDureeMs = 160

	// Textes (sp = px / 2 sur un écran de 960 dp)
	val TexteTuilePrincipale = 36.sp
	val TexteTuile = 26.sp
	val TexteTitreEcran = 28.sp
	val TexteMessage = 20.sp
	val TexteBouton = 17.sp
	val TexteCourant = 14.sp
	val TexteAccueilTitre = 40.sp
}

object MirtvPolice {
	val Lexend = FontFamily(
		Font(R.font.lexend_regular, FontWeight.Normal),
		Font(R.font.lexend_semibold, FontWeight.SemiBold),
		Font(R.font.lexend_bold, FontWeight.Bold),
		Font(R.font.lexend_extrabold, FontWeight.ExtraBold),
	)

	fun style(taille: androidx.compose.ui.unit.TextUnit, poids: FontWeight, couleur: Color) =
		TextStyle(fontFamily = Lexend, fontSize = taille, fontWeight = poids, color = couleur)
}
