package org.jellyfin.androidtv.mirtv

import org.jellyfin.sdk.model.api.BaseItemDto
import java.util.UUID

/** Liste des chaînes de l'onglet en cours : sert au zapping haut/bas dans le lecteur. */
object MirtvZapping {
	@Volatile
	var liste: List<BaseItemDto> = emptyList()

	/** Chaîne voisine (delta -1 = précédente, +1 = suivante), en boucle. Null si la liste est vide ou ne contient pas la chaîne. */
	fun voisine(courante: UUID, delta: Int): BaseItemDto? {
		val l = liste
		if (l.size < 2) return null
		val i = l.indexOfFirst { it.id == courante }
		if (i < 0) return null
		return l[(i + delta + l.size) % l.size]
	}
}
