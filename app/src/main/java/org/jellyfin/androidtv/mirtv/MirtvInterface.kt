package org.jellyfin.androidtv.mirtv

import android.content.Context

/** MirTV : ce qui est montré ou caché dans l'interface d'origine. */
object MirtvInterface {
	/** Recherche au clavier : cachée (public qui ne lit pas). */
	const val RECHERCHE = false

	/**
	 * Réglages : visibles seulement sur les box du canal « test » (Ravi, Web Agence),
	 * en attendant l'accès par le code parent (prompt 08).
	 */
	fun reglagesVisibles(context: Context): Boolean = MirtvCanal.lire(context) == MirtvCanal.TEST
}
