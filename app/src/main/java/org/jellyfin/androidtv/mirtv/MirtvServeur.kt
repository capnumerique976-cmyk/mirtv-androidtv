package org.jellyfin.androidtv.mirtv

import kotlinx.coroutines.delay
import kotlinx.coroutines.flow.Flow
import kotlinx.coroutines.flow.first
import org.jellyfin.androidtv.BuildConfig
import org.jellyfin.androidtv.auth.model.ConnectedState
import org.jellyfin.androidtv.auth.model.ServerAdditionState
import org.jellyfin.androidtv.auth.model.UnableToConnectState
import timber.log.Timber
import java.util.UUID

/**
 * MirTV : le serveur https://mirtv.rahiss.com est préréglé. L'écran « Ajouter un serveur »
 * et le choix du serveur ne sont jamais montrés : on ajoute le serveur MirTV tout seul,
 * en réessayant tant qu'il ne répond pas (box sans internet au démarrage, par exemple).
 */
object MirtvServeur {
	const val ADRESSE: String = BuildConfig.MIRTV_SERVEUR
	private const val ATTENTE_ENTRE_ESSAIS_MS = 10_000L

	/**
	 * Ajoute (ou retrouve) le serveur MirTV et renvoie son identifiant.
	 * [ajouter] est la fonction d'ajout du dépôt de serveurs ; [surEchec] est appelée à chaque échec.
	 */
	suspend fun assurer(
		ajouter: (String) -> Flow<ServerAdditionState>,
		surEchec: () -> Unit,
	): UUID {
		while (true) {
			val etat = ajouter(ADRESSE).first { it is ConnectedState || it is UnableToConnectState }
			if (etat is ConnectedState) return etat.id
			Timber.w("MirTV : serveur injoignable, nouvel essai dans ${ATTENTE_ENTRE_ESSAIS_MS / 1000} s")
			surEchec()
			delay(ATTENTE_ENTRE_ESSAIS_MS)
		}
	}
}
