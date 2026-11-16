package org.jellyfin.androidtv.mirtv.ui

import androidx.compose.foundation.Canvas
import androidx.compose.foundation.layout.size
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.Path
import androidx.compose.ui.graphics.StrokeCap
import androidx.compose.ui.graphics.StrokeJoin
import androidx.compose.ui.graphics.drawscope.DrawScope
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.dp
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.sin

/** Pictogrammes MirTV : grille de 24, trait de 2, extrémités arrondies (design/tokens.json). */
enum class MirtvPicto { TELE, REPRENDRE, FILMS, FAVORIS, AIDE, RETOUR }

@Composable
fun MirtvPictogramme(picto: MirtvPicto, couleur: Color, taille: Dp = 48.dp, modifier: Modifier = Modifier) {
	Canvas(modifier.size(taille)) {
		val u = size.minDimension / 24f
		val trait = Stroke(width = 2f * u, cap = StrokeCap.Round, join = StrokeJoin.Round)
		when (picto) {
			MirtvPicto.TELE -> {
				drawRoundRect(couleur, Offset(2f * u, 4f * u), Size(20f * u, 13f * u), CornerRadius(2.5f * u), style = trait)
				drawLine(couleur, Offset(8f * u, 21f * u), Offset(16f * u, 21f * u), 2f * u, StrokeCap.Round)
				drawTriangle(couleur, u, 10f, 7.5f, 15f, 10.5f, 10f, 13.5f)
			}
			MirtvPicto.REPRENDRE -> {
				drawCircle(couleur, 9.5f * u, Offset(12f * u, 12f * u), style = trait)
				drawTriangle(couleur, u, 10f, 8f, 16f, 12f, 10f, 16f)
			}
			MirtvPicto.FILMS -> {
				drawRoundRect(couleur, Offset(3f * u, 7f * u), Size(18f * u, 13f * u), CornerRadius(2f * u), style = trait)
				drawLine(couleur, Offset(3f * u, 11f * u), Offset(21f * u, 11f * u), 2f * u, StrokeCap.Round)
				drawLine(couleur, Offset(4f * u, 4f * u), Offset(19f * u, 7f * u), 2f * u, StrokeCap.Round)
				drawTriangle(couleur, u, 10f, 13f, 14.5f, 15.5f, 10f, 18f)
			}
			MirtvPicto.FAVORIS -> {
				val p = Path()
				for (i in 0 until 10) {
					val r = if (i % 2 == 0) 10f else 4.4f
					val a = -PI / 2 + i * PI / 5
					val x = (12f + r * cos(a).toFloat()) * u
					val y = (12.5f + r * sin(a).toFloat()) * u
					if (i == 0) p.moveTo(x, y) else p.lineTo(x, y)
				}
				p.close()
				drawPath(p, couleur, style = trait)
			}
			MirtvPicto.AIDE -> {
				drawCircle(couleur, 9.5f * u, Offset(12f * u, 12f * u), style = trait)
				val p = Path().apply {
					moveTo(9f * u, 9.5f * u)
					cubicTo(9f * u, 6f * u, 15f * u, 6f * u, 15f * u, 9.5f * u)
					cubicTo(15f * u, 12f * u, 12f * u, 11.5f * u, 12f * u, 14.5f * u)
				}
				drawPath(p, couleur, style = trait)
				drawCircle(couleur, 1.2f * u, Offset(12f * u, 17.8f * u))
			}
			MirtvPicto.RETOUR -> {
				val p = Path().apply {
					moveTo(14f * u, 5f * u); lineTo(7f * u, 12f * u); lineTo(14f * u, 19f * u)
				}
				drawPath(p, couleur, style = trait)
			}
		}
	}
}

private fun DrawScope.drawTriangle(c: Color, u: Float, x1: Float, y1: Float, x2: Float, y2: Float, x3: Float, y3: Float) {
	val p = Path().apply {
		moveTo(x1 * u, y1 * u); lineTo(x2 * u, y2 * u); lineTo(x3 * u, y3 * u); close()
	}
	drawPath(p, c)
}
