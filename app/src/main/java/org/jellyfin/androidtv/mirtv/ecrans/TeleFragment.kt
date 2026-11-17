package org.jellyfin.androidtv.mirtv.ecrans

import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.BasicText
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.getValue
import androidx.compose.runtime.mutableStateOf
import androidx.compose.runtime.remember
import androidx.compose.runtime.setValue
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.platform.LocalContext
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.jellyfin.androidtv.R
import org.jellyfin.androidtv.mirtv.MirtvProfils
import org.jellyfin.androidtv.mirtv.MirtvZapping
import org.jellyfin.androidtv.mirtv.ui.MirtvBanner
import org.jellyfin.androidtv.mirtv.ui.MirtvCarte
import org.jellyfin.androidtv.mirtv.ui.MirtvCouleurs
import org.jellyfin.androidtv.mirtv.ui.demanderFocusAvecReessais
import org.jellyfin.androidtv.mirtv.ui.MirtvKeyHints
import org.jellyfin.androidtv.mirtv.ui.MirtvPicto
import org.jellyfin.androidtv.mirtv.ui.MirtvPolice
import org.jellyfin.androidtv.mirtv.ui.MirtvTailles
import org.jellyfin.androidtv.mirtv.ui.MirtvTile
import org.jellyfin.androidtv.mirtv.ui.MirtvTopBar
import org.jellyfin.androidtv.auth.repository.UserRepository
import org.jellyfin.androidtv.ui.playback.PlaybackLauncher
import org.jellyfin.sdk.api.client.ApiClient
import org.jellyfin.sdk.api.client.extensions.liveTvApi
import org.jellyfin.sdk.model.api.BaseItemDto
import org.jellyfin.sdk.model.api.ItemFields
import org.koin.android.ext.android.inject

/**
 * Télé en direct : 4 onglets (étiquettes onglet-*), grille de chaînes 3 x 2, OK = lecture plein écran.
 * Zapping haut/bas dans le lecteur : voir MirtvZapping.
 */
class TeleFragment : MirtvFragment() {
	private val api by inject<ApiClient>()
	private val userRepository by inject<UserRepository>()
	private val lanceur by inject<PlaybackLauncher>()

	private data class Onglet(val etiquette: String, val titre: Int, val couleur: androidx.compose.ui.graphics.Color)

	private val onglets = listOf(
		Onglet("onglet-infos", R.string.onglet_infos, MirtvCouleurs.Ciel),
		Onglet("onglet-culture", R.string.onglet_culture, MirtvCouleurs.Lilas),
		Onglet("onglet-sport", R.string.onglet_sport, MirtvCouleurs.Feuille),
		Onglet("onglet-enfants", R.string.onglet_enfants, MirtvCouleurs.Citron),
	)

	@Composable
	override fun Contenu() {
		val contexte = LocalContext.current
		var chaines by remember { mutableStateOf<List<BaseItemDto>?>(null) }
		var erreur by remember { mutableStateOf(false) }
		var ongletIndex by remember { mutableStateOf(0) }
		val demande = remember { FocusRequester() }
		val nom = userRepository.currentUser.value?.name

		LaunchedEffect(Unit) {
			runCatching {
				withContext(Dispatchers.IO) {
					api.liveTvApi.getLiveTvChannels(addCurrentProgram = true, fields = setOf(ItemFields.TAGS)).content.items
				}
			}.fold(onSuccess = { chaines = it }, onFailure = { erreur = true })
		}

		val liste = chaines.orEmpty().filter { c -> c.tags.orEmpty().contains(onglets[ongletIndex].etiquette) }
		LaunchedEffect(liste.size, ongletIndex) { MirtvZapping.liste = liste }
		LaunchedEffect(chaines) { if (chaines != null) demande.demanderFocusAvecReessais() }

		Box(Modifier.fillMaxSize().background(MirtvCouleurs.Nuit)) {
			Column(
				Modifier.fillMaxSize().padding(horizontal = MirtvTailles.MargeHorizontale, vertical = MirtvTailles.MargeVerticale),
				verticalArrangement = Arrangement.SpaceBetween,
			) {
				MirtvTopBar(stringResource(R.string.tuile_tele), nom?.let { MirtvProfils.nomAffiche(it, stringResource(R.string.profil_parent)) })
				Column(Modifier.fillMaxWidth().padding(top = 12.dp), verticalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles)) {
					Row(horizontalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles)) {
						onglets.forEachIndexed { i, o ->
							MirtvTile(
								texte = stringResource(o.titre),
								couleur = o.couleur,
								picto = null,
								onClick = { ongletIndex = i },
								modifier = Modifier.weight(1f).height(56.dp),
								tailleTexte = MirtvTailles.TexteMessage,
								focusRequester = if (i == 0) demande else null,
							)
						}
					}
					when {
						erreur -> MirtvBanner(stringResource(R.string.tele_erreur), couleur = MirtvCouleurs.Mangue)
						chaines == null -> MirtvBanner(stringResource(R.string.tele_chargement))
						liste.isEmpty() -> MirtvBanner(stringResource(R.string.tele_vide))
						else -> liste.take(6).chunked(3).forEach { ligne ->
							Row(horizontalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles)) {
								ligne.forEach { c ->
									MirtvCarte(
										onClick = { lanceur.launch(contexte, listOf(c)) },
										description = c.name.orEmpty(),
										modifier = Modifier.weight(1f).height(120.dp),
										couleur = MirtvCouleurs.Nuit2,
									) {
										Column(Modifier.padding(16.dp), verticalArrangement = Arrangement.spacedBy(6.dp)) {
											BasicText(c.name.orEmpty(), style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.Bold, MirtvCouleurs.Blanc))
											val prog = c.currentProgram?.name
											if (prog != null) BasicText(prog, style = MirtvPolice.style(MirtvTailles.TexteCourant, FontWeight.Normal, MirtvCouleurs.Doux), maxLines = 2)
										}
									}
								}
								repeat(3 - ligne.size) { Box(Modifier.weight(1f)) }
							}
						}
					}
				}
				MirtvKeyHints(
					listOf(stringResource(R.string.indice_fleches_choisir), stringResource(R.string.indice_ok_ouvrir), stringResource(R.string.indice_retour_accueil)),
				)
			}
		}
	}
}
