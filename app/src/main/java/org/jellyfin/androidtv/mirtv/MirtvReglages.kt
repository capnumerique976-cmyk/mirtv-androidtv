package org.jellyfin.androidtv.mirtv

import android.content.Context
import android.content.Intent
import timber.log.Timber

/**
 * MirTV : réglages posés par le script de préparation des box (adb shell am start … --es langue fr|mg --es activation CODE).
 * La langue est gardée dans les préférences de l'appli. Le code d'activation est gardé tel quel jusqu'au prompt 11
 * (qui l'échangera contre les jetons de la famille, puis l'effacera).
 */
object MirtvReglages {
	private const val FICHIER = "mirtv_reglages"
	private val LANGUES = setOf("fr", "mg")

	fun lireIntent(context: Context, intent: Intent) {
		val prefs = context.getSharedPreferences(FICHIER, Context.MODE_PRIVATE)
		val editeur = prefs.edit()
		intent.getStringExtra("langue")?.takeIf { it in LANGUES }?.let {
			editeur.putString("langue", it)
			Timber.i("MirTV : langue = $it")
		}
		intent.getStringExtra("activation")?.takeIf { it.matches(Regex("[A-Za-z0-9]{4,16}")) }?.let {
			editeur.putString("activation", it.uppercase())
			Timber.i("MirTV : code d'activation reçu")
		}
		editeur.apply()
	}

	fun langue(context: Context): String =
		context.getSharedPreferences(FICHIER, Context.MODE_PRIVATE).getString("langue", "fr") ?: "fr"

	fun codeActivation(context: Context): String? =
		context.getSharedPreferences(FICHIER, Context.MODE_PRIVATE).getString("activation", null)
}
