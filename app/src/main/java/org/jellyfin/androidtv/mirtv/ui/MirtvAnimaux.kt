package org.jellyfin.androidtv.mirtv.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp

/**
 * Six images de profil : animaux originaux MirTV (jamais de personnages connus), dessinés en formes simples.
 * L'animal d'un profil dépend de sa place dans la liste : il ne change pas tant que la liste ne change pas.
 */
enum class MirtvAnimal(val nom: String, val couleur: Color, val oreilles: Oreilles) {
	MAKI("maki", MirtvCouleurs.Mangue, Oreilles.RONDES),
	CAMELEON("caméléon", MirtvCouleurs.Feuille, Oreilles.AUCUNE),
	TORTUE("tortue", MirtvCouleurs.Lagon, Oreilles.RONDES),
	ZEBU("zébu", MirtvCouleurs.Citron, Oreilles.CORNES),
	LAPIN("lapin", MirtvCouleurs.Corail, Oreilles.LONGUES),
	CHAT("chat", MirtvCouleurs.Lilas, Oreilles.POINTUES);

	enum class Oreilles { AUCUNE, RONDES, POINTUES, LONGUES, CORNES }

	companion object {
		fun pour(index: Int) = entries[((index % entries.size) + entries.size) % entries.size]
	}
}

@Composable
fun MirtvAnimalImage(animal: MirtvAnimal, taille: Dp = 120.dp, modifier: Modifier = Modifier) {
	Canvas(modifier.size(taille)) {
		val s = size.minDimension
		val c = animal.couleur
		val encre = MirtvCouleurs.Encre
		// oreilles ou cornes (derrière la tête)
		when (animal.oreilles) {
			MirtvAnimal.Oreilles.RONDES -> {
				drawCircle(c, s * 0.14f, Offset(s * 0.22f, s * 0.2f)); drawCircle(c, s * 0.14f, Offset(s * 0.78f, s * 0.2f))
			}
			MirtvAnimal.Oreilles.POINTUES -> {
				for (cote in listOf(0.18f, 0.82f)) {
					val p = Path().apply {
						moveTo(s * (cote - 0.14f), s * 0.34f); lineTo(s * cote, s * 0.04f); lineTo(s * (cote + 0.14f), s * 0.34f); close()
					}
					drawPath(p, c)
				}
			}
			MirtvAnimal.Oreilles.LONGUES -> {
				drawOval(c, Offset(s * 0.22f, s * 0.0f), androidx.compose.ui.geometry.Size(s * 0.18f, s * 0.46f))
				drawOval(c, Offset(s * 0.60f, s * 0.0f), androidx.compose.ui.geometry.Size(s * 0.18f, s * 0.46f))
			}
			MirtvAnimal.Oreilles.CORNES -> {
				drawCircle(encre, s * 0.07f, Offset(s * 0.2f, s * 0.2f)); drawCircle(encre, s * 0.07f, Offset(s * 0.8f, s * 0.2f))
			}
			MirtvAnimal.Oreilles.AUCUNE -> Unit
		}
		// tête
		drawCircle(c, s * 0.36f, Offset(s * 0.5f, s * 0.58f))
		// yeux
		drawCircle(Color.White, s * 0.09f, Offset(s * 0.36f, s * 0.52f)); drawCircle(Color.White, s * 0.09f, Offset(s * 0.64f, s * 0.52f))
		drawCircle(encre, s * 0.045f, Offset(s * 0.37f, s * 0.53f)); drawCircle(encre, s * 0.045f, Offset(s * 0.65f, s * 0.53f))
		// museau
		drawCircle(encre, s * 0.035f, Offset(s * 0.5f, s * 0.68f))
	}
}
