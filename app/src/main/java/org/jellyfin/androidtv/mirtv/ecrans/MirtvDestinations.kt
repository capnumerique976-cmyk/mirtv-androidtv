package org.jellyfin.androidtv.mirtv.ecrans

import org.jellyfin.androidtv.R
import org.jellyfin.androidtv.ui.navigation.fragmentDestination

/** Destinations MirTV. Les écrans pas encore faits pointent vers l'écran provisoire « Bientôt ici ». */
object MirtvDestinations {
	private fun bientot(titre: Int, enfants: Boolean = false) = fragmentDestination<BientotFragment> {
		putInt(BientotFragment.ARG_TITRE, titre)
		putBoolean(BientotFragment.ARG_ENFANTS, enfants)
	}

	val accueil = fragmentDestination<AccueilFragment>()
	var teleEnDirect = bientot(R.string.tuile_tele)
	var reprendre = bientot(R.string.tuile_reprendre)
	var films = bientot(R.string.tuile_films)
	var favoris = bientot(R.string.tuile_favoris)
	var aide = bientot(R.string.tuile_aide)
}
