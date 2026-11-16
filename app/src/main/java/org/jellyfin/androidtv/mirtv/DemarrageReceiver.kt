package org.jellyfin.androidtv.mirtv

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.Build
import android.provider.Settings
import org.jellyfin.androidtv.ui.startup.StartupActivity
import timber.log.Timber

/**
 * MirTV : ouvre MirTV quand la box démarre.
 * Depuis Android 10, une appli ne peut s'ouvrir seule que si elle a l'autorisation
 * « afficher par-dessus les autres applis » (accordée par le script de préparation de la box).
 * Sans cette autorisation, MirTV ne s'ouvre pas seule, mais rien ne plante.
 */
class DemarrageReceiver : BroadcastReceiver() {
	override fun onReceive(context: Context, intent: Intent) {
		if (intent.action !in ACTIONS) return

		val autorise = Build.VERSION.SDK_INT < Build.VERSION_CODES.Q || Settings.canDrawOverlays(context)
		if (!autorise) {
			Timber.i("MirTV : démarrage automatique non autorisé (« afficher par-dessus » non accordé)")
			return
		}

		runCatching {
			context.startActivity(
				Intent(context, StartupActivity::class.java)
					.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_RESET_TASK_IF_NEEDED)
			)
		}.onFailure { Timber.w(it, "MirTV : démarrage automatique impossible") }
	}

	private companion object {
		val ACTIONS = setOf(
			Intent.ACTION_BOOT_COMPLETED,
			"android.intent.action.QUICKBOOT_POWERON",
			"com.htc.intent.action.QUICKBOOT_POWERON",
		)
	}
}
