package org.jellyfin.androidtv.mirtv.ecrans

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.fillMaxHeight
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.fillMaxWidth
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.text.BasicText
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.remember
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import org.jellyfin.androidtv.R
import org.jellyfin.androidtv.auth.repository.UserRepository
import org.jellyfin.androidtv.mirtv.MirtvProfils
import org.jellyfin.androidtv.mirtv.ui.MirtvCouleurs
import org.jellyfin.androidtv.mirtv.ui.demanderFocusAvecReessais
import org.jellyfin.androidtv.mirtv.ui.MirtvKeyHints
import org.jellyfin.androidtv.mirtv.ui.MirtvPicto
import org.jellyfin.androidtv.mirtv.ui.MirtvPolice
import org.jellyfin.androidtv.mirtv.ui.MirtvTailles
import org.jellyfin.androidtv.mirtv.ui.MirtvTile
import org.jellyfin.androidtv.mirtv.ui.MirtvTopBar
import org.koin.android.ext.android.inject

/**
 * Accueil à 5 tuiles fixes : « Télé en direct » grande à gauche (2 rangées), puis « Reprendre »,
 * « Films et vidéos », « Mes favoris », « Aide ». Les tuiles ne changent jamais de place ni de couleur.
 * La sélection démarre sur « Télé en direct ». Retour sur l'accueil : rien ne se passe.
 * Profil enfant : écran provisoire (accueil enfants au prompt 08).
 */
class AccueilFragment : MirtvFragment() {
	private val userRepository by inject<UserRepository>()

	@Composable
	override fun Contenu() {
		val nom = userRepository.currentUser.value?.name.orEmpty()
		if (MirtvProfils.estEnfant(nom)) {
			// Accueil enfants : provisoire jusqu'au prompt 08
			BientotFragmentContenuEnfants()
			return
		}
		val libelleParent = stringResource(R.string.profil_parent)
		val demandeTele = remember { FocusRequester() }
		BackHandler { } // Retour sur l'accueil ne quitte pas l'appli
		LaunchedEffect(Unit) { demandeTele.demanderFocusAvecReessais() }

		fun ouvrir(d: org.jellyfin.androidtv.ui.navigation.Destination.Fragment) = navigationRepository.navigate(d)

		Box(Modifier.fillMaxSize().background(MirtvCouleurs.Nuit)) {
			Column(
				Modifier.fillMaxSize().padding(horizontal = MirtvTailles.MargeHorizontale, vertical = MirtvTailles.MargeVerticale),
				verticalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles),
			) {
				MirtvTopBar(
					titre = stringResource(R.string.accueil_titre),
					profil = MirtvProfils.nomAffiche(nom, libelleParent),
				)
				Row(
					Modifier.fillMaxWidth().weight(1f),
					horizontalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles),
				) {
					MirtvTile(
						texte = stringResource(R.string.tuile_tele),
						couleur = MirtvCouleurs.Mangue,
						picto = MirtvPicto.TELE,
						onClick = { ouvrir(MirtvDestinations.teleEnDirect) },
						modifier = Modifier.weight(1.15f).fillMaxHeight(),
						tailleTexte = MirtvTailles.TexteTuilePrincipale,
						tailleIcone = 96.dp,
						focusRequester = demandeTele,
						contenuSupplementaire = {
							BasicText(
								stringResource(R.string.tuile_tele_sous_titre),
								style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.SemiBold, MirtvCouleurs.Encre),
							)
						},
					)
					Column(Modifier.weight(2f).fillMaxHeight(), verticalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles)) {
						Row(Modifier.weight(1f).fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles)) {
							MirtvTile(
								texte = stringResource(R.string.tuile_reprendre),
								couleur = MirtvCouleurs.Corail,
								picto = MirtvPicto.REPRENDRE,
								onClick = { ouvrir(MirtvDestinations.reprendre) },
								modifier = Modifier.weight(1f).fillMaxHeight(),
							)
							MirtvTile(
								texte = stringResource(R.string.tuile_films),
								couleur = MirtvCouleurs.Lagon,
								picto = MirtvPicto.FILMS,
								onClick = { ouvrir(MirtvDestinations.films) },
								modifier = Modifier.weight(1f).fillMaxHeight(),
							)
						}
						Row(Modifier.weight(1f).fillMaxWidth(), horizontalArrangement = Arrangement.spacedBy(MirtvTailles.EntreTuiles)) {
							MirtvTile(
								texte = stringResource(R.string.tuile_favoris),
								couleur = MirtvCouleurs.Citron,
								picto = MirtvPicto.FAVORIS,
								onClick = { ouvrir(MirtvDestinations.favoris) },
								modifier = Modifier.weight(1f).fillMaxHeight(),
							)
							MirtvTile(
								texte = stringResource(R.string.tuile_aide),
								couleur = MirtvCouleurs.Lilas,
								picto = MirtvPicto.AIDE,
								onClick = { ouvrir(MirtvDestinations.aide) },
								modifier = Modifier.weight(1f).fillMaxHeight(),
							)
						}
					}
				}
				MirtvKeyHints(
					listOf(stringResource(R.string.indice_fleches_choisir), stringResource(R.string.indice_ok_ouvrir)),
				)
			}
		}
	}

	@Composable
	private fun BientotFragmentContenuEnfants() {
		BackHandler { }
		Box(Modifier.fillMaxSize().background(MirtvCouleurs.Foret)) {
			Column(Modifier.fillMaxSize().padding(MirtvTailles.MargeHorizontale), verticalArrangement = Arrangement.Center) {
				BasicText(
					stringResource(R.string.enfants_titre),
					style = MirtvPolice.style(MirtvTailles.TexteAccueilTitre, FontWeight.ExtraBold, MirtvCouleurs.Blanc),
				)
			}
		}
	}
}
