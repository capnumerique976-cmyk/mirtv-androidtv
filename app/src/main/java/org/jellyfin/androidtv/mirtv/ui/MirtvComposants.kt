package org.jellyfin.androidtv.mirtv.ui

import androidx.compose.animation.core.animateFloatAsState
import androidx.compose.animation.core.tween
import androidx.compose.foundation.background
import androidx.compose.foundation.clickable
import androidx.compose.foundation.interaction.MutableInteractionSource
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicText
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.draw.drawBehind
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.focus.focusRequester
import androidx.compose.ui.focus.onFocusChanged
import androidx.compose.ui.geometry.CornerRadius
import androidx.compose.ui.geometry.Offset
import androidx.compose.ui.geometry.Size
import androidx.compose.ui.graphics.Color
import androidx.compose.ui.graphics.graphicsLayer
import androidx.compose.ui.graphics.drawscope.Stroke
import androidx.compose.ui.semantics.Role
import androidx.compose.ui.semantics.contentDescription
import androidx.compose.ui.semantics.role
import androidx.compose.ui.semantics.semantics
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.text.style.TextAlign
import androidx.compose.ui.unit.Dp
import androidx.compose.ui.unit.TextUnit
import androidx.compose.ui.unit.dp
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import kotlinx.coroutines.delay

/**
 * Tuile MirTV : couleur fixe, pictogramme, mot. Sélectionnée : +10 %, contour blanc décalé, halo, 160 ms.
 * Le texte est toujours « encre » sur la couleur vive (jamais de blanc sur une tuile vive).
 */
@Composable
fun MirtvTile(
	texte: String,
	couleur: Color,
	picto: MirtvPicto?,
	onClick: () -> Unit,
	modifier: Modifier = Modifier,
	tailleTexte: TextUnit = MirtvTailles.TexteTuile,
	tailleIcone: Dp = 48.dp,
	description: String = texte,
	focusRequester: FocusRequester? = null,
	rayon: Dp = MirtvTailles.RayonTuile,
	contenuSupplementaire: @Composable (() -> Unit)? = null,
) {
	var focus by remember { mutableStateOf(false) }
	val echelle by animateFloatAsState(
		targetValue = if (focus) MirtvTailles.FocusEchelle else 1f,
		animationSpec = tween(MirtvTailles.FocusDureeMs),
		label = "mirtv-tuile-echelle",
	)
	val forme = RoundedCornerShape(rayon)
	val sourceInteraction = remember { MutableInteractionSource() }
	Box(
		modifier = modifier
			.graphicsLayer { scaleX = echelle; scaleY = echelle }
			.drawBehind {
				if (focus) {
					val halo = MirtvTailles.FocusHalo.toPx()
					drawRoundRect(
						MirtvCouleurs.FocusHalo,
						Offset(-halo, -halo),
						Size(size.width + 2 * halo, size.height + 2 * halo),
						CornerRadius(rayon.toPx() + halo),
					)
					val decalage = MirtvTailles.FocusDecalage.toPx()
					drawRoundRect(
						MirtvCouleurs.Blanc,
						Offset(-decalage, -decalage),
						Size(size.width + 2 * decalage, size.height + 2 * decalage),
						CornerRadius(rayon.toPx() + decalage),
						style = Stroke(MirtvTailles.FocusContour.toPx()),
					)
				}
			}
			.background(couleur, forme)
			.semantics { contentDescription = description; role = Role.Button }
			.then(if (focusRequester != null) Modifier.focusRequester(focusRequester) else Modifier)
			.onFocusChanged { focus = it.isFocused }
			.clickable(interactionSource = sourceInteraction, indication = null, onClick = onClick),
		contentAlignment = Alignment.BottomStart,
	) {
		Column(Modifier.fillMaxSize().padding(MirtvTailles.InterneTuile), verticalArrangement = Arrangement.SpaceBetween) {
			if (picto != null) MirtvPictogramme(picto, MirtvCouleurs.Encre, tailleIcone)
			else Spacer(Modifier.size(1.dp))
			Column {
				BasicText(texte, style = MirtvPolice.style(tailleTexte, FontWeight.ExtraBold, MirtvCouleurs.Encre))
				contenuSupplementaire?.invoke()
			}
		}
	}
}

/** Carte sélectionnable au contenu libre (même effet de sélection que la tuile). */
@Composable
fun MirtvCarte(
	onClick: () -> Unit,
	description: String,
	modifier: Modifier = Modifier,
	couleur: Color = Color.Transparent,
	rayon: Dp = MirtvTailles.RayonCarte,
	focusRequester: FocusRequester? = null,
	contenu: @Composable androidx.compose.foundation.layout.BoxScope.() -> Unit,
) {
	var focus by remember { mutableStateOf(false) }
	val echelle by animateFloatAsState(
		targetValue = if (focus) MirtvTailles.FocusEchelle else 1f,
		animationSpec = tween(MirtvTailles.FocusDureeMs),
		label = "mirtv-carte-echelle",
	)
	val sourceInteraction = remember { MutableInteractionSource() }
	Box(
		modifier = modifier
			.graphicsLayer { scaleX = echelle; scaleY = echelle }
			.drawBehind {
				if (focus) {
					val halo = MirtvTailles.FocusHalo.toPx()
					drawRoundRect(
						MirtvCouleurs.FocusHalo,
						Offset(-halo, -halo),
						Size(size.width + 2 * halo, size.height + 2 * halo),
						CornerRadius(rayon.toPx() + halo),
					)
					val decalage = MirtvTailles.FocusDecalage.toPx()
					drawRoundRect(
						MirtvCouleurs.Blanc,
						Offset(-decalage, -decalage),
						Size(size.width + 2 * decalage, size.height + 2 * decalage),
						CornerRadius(rayon.toPx() + decalage),
						style = Stroke(MirtvTailles.FocusContour.toPx()),
					)
				}
			}
			.background(couleur, RoundedCornerShape(rayon))
			.semantics { contentDescription = description; role = Role.Button }
			.then(if (focusRequester != null) Modifier.focusRequester(focusRequester) else Modifier)
			.onFocusChanged { focus = it.isFocused }
			.clickable(interactionSource = sourceInteraction, indication = null, onClick = onClick),
		content = contenu,
	)
}

/** Barre du haut : logo, titre, heure, pastille du profil. */
@Composable
fun MirtvTopBar(
	titre: String,
	profil: String?,
	modifier: Modifier = Modifier,
	fond: Color = MirtvCouleurs.Blanc,
	enfants: Boolean = false,
) {
	var heure by remember { mutableStateOf(formatHeure()) }
	LaunchedEffect(Unit) {
		while (true) {
			heure = formatHeure()
			delay(20_000)
		}
	}
	Row(modifier.fillMaxWidth(), verticalAlignment = Alignment.CenterVertically) {
		Row(verticalAlignment = Alignment.CenterVertically) {
			BasicText("Mir", style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.ExtraBold, fond))
			BasicText("TV", style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.ExtraBold, MirtvCouleurs.Mangue))
		}
		Spacer(Modifier.width(24.dp))
		BasicText(
			titre,
			style = MirtvPolice.style(MirtvTailles.TexteTitreEcran, FontWeight.Bold, fond),
			modifier = Modifier.weight(1f).semantics { contentDescription = titre },
		)
		BasicText(heure, style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.SemiBold, fond))
		if (profil != null) {
			Spacer(Modifier.width(16.dp))
			MirtvPastilleProfil(profil, enfants)
		}
	}
}

@Composable
fun MirtvPastilleProfil(nom: String, enfants: Boolean, modifier: Modifier = Modifier) {
	val couleur = if (enfants) MirtvCouleurs.Feuille else MirtvCouleurs.Lilas
	Box(
		modifier
			.size(40.dp)
			.background(couleur, RoundedCornerShape(50))
			.semantics { contentDescription = nom },
		contentAlignment = Alignment.Center,
	) {
		BasicText(
			nom.take(1).uppercase(),
			style = MirtvPolice.style(MirtvTailles.TexteTitreEcran, FontWeight.ExtraBold, MirtvCouleurs.Encre),
		)
	}
}

/** Aide aux touches, en bas de l'écran (sert aussi de repère à ceux qui ne lisent pas : une flèche = une action). */
@Composable
fun MirtvKeyHints(indices: List<String>, modifier: Modifier = Modifier, couleur: Color = MirtvCouleurs.Indice) {
	Row(modifier, horizontalArrangement = Arrangement.spacedBy(32.dp)) {
		indices.forEach {
			BasicText(it, style = MirtvPolice.style(MirtvTailles.TexteCourant, FontWeight.Normal, couleur))
		}
	}
}

/** Bandeau d'information (ex. fin d'abonnement). Reste jusqu'à un appui : jamais de disparition seule. */
@Composable
fun MirtvBanner(texte: String, modifier: Modifier = Modifier, couleur: Color = MirtvCouleurs.Ciel) {
	Box(
		modifier
			.fillMaxWidth()
			.background(couleur, RoundedCornerShape(MirtvTailles.RayonBandeau))
			.padding(horizontal = 24.dp, vertical = 14.dp)
			.semantics { contentDescription = texte },
	) {
		BasicText(texte, style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.SemiBold, MirtvCouleurs.Encre), modifier = Modifier.fillMaxWidth(), )
	}
}

private fun formatHeure(): String = SimpleDateFormat("HH:mm", Locale.getDefault()).format(Date())

/**
 * Donne le focus (sélection) à un élément, en réessayant : juste après l'affichage de l'écran,
 * l'élément n'est pas encore prêt et la demande échoue. Sans ça, rien n'est sélectionné au lancement.
 */
suspend fun FocusRequester.demanderFocusAvecReessais(essais: Int = 20) {
	repeat(essais) {
		val ok = runCatching { requestFocus(); true }.getOrDefault(false)
		if (ok) return
		delay(100)
	}
}
