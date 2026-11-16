package org.jellyfin.androidtv.mirtv.ecrans

import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.text.BasicText
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import org.jellyfin.androidtv.R
import org.jellyfin.androidtv.mirtv.ui.MirtvCouleurs
import org.jellyfin.androidtv.mirtv.ui.MirtvKeyHints
import org.jellyfin.androidtv.mirtv.ui.MirtvPicto
import org.jellyfin.androidtv.mirtv.ui.MirtvPolice
import org.jellyfin.androidtv.mirtv.ui.MirtvTailles
import org.jellyfin.androidtv.mirtv.ui.MirtvTile
import org.jellyfin.androidtv.mirtv.ui.MirtvTopBar
import org.jellyfin.androidtv.auth.repository.UserRepository
import org.koin.android.ext.android.inject

/** Écran provisoire des destinations pas encore faites : titre, « Bientôt ici », Retour. */
class BientotFragment : MirtvFragment() {
	companion object {
		const val ARG_TITRE = "titre"
		const val ARG_ENFANTS = "enfants"
	}

	private val userRepository by inject<UserRepository>()

	@Composable
	override fun Contenu() {
		val titreRes = arguments?.getInt(ARG_TITRE) ?: R.string.accueil_titre
		val enfants = arguments?.getBoolean(ARG_ENFANTS) ?: false
		val fond = if (enfants) MirtvCouleurs.Foret else MirtvCouleurs.Nuit
		val demande = androidx.compose.runtime.remember { FocusRequester() }
		val nom = userRepository.currentUser.value?.name
		// Retour de la télécommande : un cran en arrière (comportement par défaut), sauf à l'accueil des enfants
		if (enfants) BackHandler { }
		LaunchedEffect(Unit) { demande.requestFocus() }
		Box(Modifier.fillMaxSize().background(fond)) {
			Column(
				Modifier.fillMaxSize().padding(horizontal = MirtvTailles.MargeHorizontale, vertical = MirtvTailles.MargeVerticale),
				verticalArrangement = Arrangement.SpaceBetween,
			) {
				MirtvTopBar(stringResource(titreRes), nom?.let { org.jellyfin.androidtv.mirtv.MirtvProfils.nomAffiche(it, stringResource(R.string.profil_parent)) }, enfants = enfants)
				Column(Modifier.fillMaxSize().padding(top = 40.dp), horizontalAlignment = Alignment.CenterHorizontally) {
					BasicText(
						stringResource(R.string.bientot_ici),
						style = MirtvPolice.style(MirtvTailles.TexteAccueilTitre, FontWeight.ExtraBold, MirtvCouleurs.Blanc),
					)
					Column(Modifier.height(32.dp)) {}
					if (!enfants) MirtvTile(
						texte = stringResource(R.string.bouton_retour),
						couleur = MirtvCouleurs.Citron,
						picto = MirtvPicto.RETOUR,
						onClick = { navigationRepository.goBack() },
						modifier = Modifier.width(240.dp).height(120.dp),
						tailleTexte = MirtvTailles.TexteMessage,
						focusRequester = demande,
					)
				}
				MirtvKeyHints(
					listOf(stringResource(R.string.indice_fleches_choisir), stringResource(R.string.indice_ok_ouvrir), stringResource(R.string.indice_retour)),
				)
			}
		}
	}
}
