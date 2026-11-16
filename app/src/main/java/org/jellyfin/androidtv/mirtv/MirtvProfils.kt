package org.jellyfin.androidtv.mirtv

/**
 * MirTV : noms des profils d'une famille. Convention de docs/02-architecture.md :
 * `f<numéro>-parent` pour le parent, `f<numéro>-<prénom>` pour les autres (profils enfants).
 * Un nom sans ce préfixe (compte de test) est traité comme un profil adulte.
 */
object MirtvProfils {
	private val FAMILLE = Regex("^f\\d+-(.+)$", RegexOption.IGNORE_CASE)

	private fun reste(nom: String) = FAMILLE.matchEntire(nom.trim())?.groupValues?.get(1)

	fun estEnfant(nom: String): Boolean {
		val r = reste(nom) ?: return false
		return !r.equals("parent", ignoreCase = true)
	}

	fun estParent(nom: String): Boolean = reste(nom)?.equals("parent", ignoreCase = true) ?: true

	/** Prénom affiché sous l'image : sans le préfixe de famille, première lettre en majuscule. */
	fun nomAffiche(nom: String, parentLibelle: String): String {
		val r = reste(nom) ?: return nom.trim().replaceFirstChar { it.uppercase() }
		return if (r.equals("parent", ignoreCase = true)) parentLibelle else r.replaceFirstChar { it.uppercase() }
	}
}
