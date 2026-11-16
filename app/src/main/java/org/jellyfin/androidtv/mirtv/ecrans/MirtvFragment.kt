package org.jellyfin.androidtv.mirtv.ecrans

import android.os.Bundle
import android.view.KeyEvent
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import androidx.compose.runtime.Composable
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.platform.ViewCompositionStrategy
import androidx.fragment.app.Fragment
import org.jellyfin.androidtv.ui.navigation.Destinations
import org.jellyfin.androidtv.ui.navigation.NavigationRepository
import org.koin.android.ext.android.inject

/**
 * Base des écrans MirTV (Compose). Appui long sur Retour = accueil (MainActivity relaie les touches
 * aux fragments qui sont des View.OnKeyListener).
 */
abstract class MirtvFragment : Fragment(), View.OnKeyListener {
	protected val navigationRepository by inject<NavigationRepository>()

	@Composable
	protected abstract fun Contenu()

	override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View =
		ComposeView(requireContext()).apply {
			setViewCompositionStrategy(ViewCompositionStrategy.DisposeOnViewTreeLifecycleDestroyed)
			setContent { Contenu() }
		}

	override fun onKey(v: View?, keyCode: Int, event: KeyEvent?): Boolean {
		if (keyCode == KeyEvent.KEYCODE_BACK && event != null && event.isLongPress) {
			allerALAccueil()
			return true
		}
		return false
	}

	protected fun allerALAccueil() {
		navigationRepository.reset(Destinations.home, true)
	}
}
