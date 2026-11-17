package org.jellyfin.androidtv.mirtv.ecrans

import android.os.Bundle
import android.view.LayoutInflater
import android.view.View
import android.view.ViewGroup
import android.widget.Toast
import androidx.activity.compose.BackHandler
import androidx.compose.foundation.background
import androidx.compose.foundation.layout.Arrangement
import androidx.compose.foundation.layout.Box
import androidx.compose.foundation.layout.Column
import androidx.compose.foundation.layout.Row
import androidx.compose.foundation.layout.Spacer
import androidx.compose.foundation.layout.fillMaxSize
import androidx.compose.foundation.layout.height
import androidx.compose.foundation.layout.padding
import androidx.compose.foundation.layout.size
import androidx.compose.foundation.layout.width
import androidx.compose.foundation.shape.RoundedCornerShape
import androidx.compose.foundation.text.BasicText
import androidx.compose.runtime.Composable
import androidx.compose.runtime.LaunchedEffect
import androidx.compose.runtime.collectAsState
import androidx.compose.runtime.getValue
import androidx.compose.runtime.remember
import androidx.compose.ui.Alignment
import androidx.compose.ui.Modifier
import androidx.compose.ui.focus.FocusRequester
import androidx.compose.ui.platform.ComposeView
import androidx.compose.ui.platform.ViewCompositionStrategy
import androidx.compose.ui.res.stringResource
import androidx.compose.ui.text.font.FontWeight
import androidx.compose.ui.unit.dp
import androidx.fragment.app.Fragment
import androidx.fragment.app.commit
import androidx.fragment.app.replace
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.flow.launchIn
import kotlinx.coroutines.flow.onEach
import org.jellyfin.androidtv.R
import org.jellyfin.androidtv.auth.model.ApiClientErrorLoginState
import org.jellyfin.androidtv.auth.model.AuthenticatedState
import org.jellyfin.androidtv.auth.model.AuthenticatingState
import org.jellyfin.androidtv.auth.model.RequireSignInState
import org.jellyfin.androidtv.auth.model.Server
import org.jellyfin.androidtv.auth.model.ServerUnavailableState
import org.jellyfin.androidtv.auth.model.ServerVersionNotSupported
import org.jellyfin.androidtv.auth.model.User
import org.jellyfin.androidtv.data.service.BackgroundService
import org.jellyfin.androidtv.mirtv.MirtvProfils
import org.jellyfin.androidtv.mirtv.MirtvReglages
import org.jellyfin.androidtv.mirtv.ui.MirtvAnimal
import org.jellyfin.androidtv.mirtv.ui.MirtvAnimalImage
import org.jellyfin.androidtv.mirtv.ui.MirtvCarte
import org.jellyfin.androidtv.mirtv.ui.MirtvCouleurs
import org.jellyfin.androidtv.mirtv.ui.MirtvKeyHints
import org.jellyfin.androidtv.mirtv.ui.MirtvPolice
import org.jellyfin.androidtv.mirtv.ui.MirtvTailles
import org.jellyfin.androidtv.mirtv.ui.MirtvTopBar
import org.jellyfin.androidtv.ui.startup.StartupViewModel
import org.jellyfin.androidtv.ui.startup.fragment.UserLoginFragment
import org.jellyfin.androidtv.util.createBundle
import org.jellyfin.sdk.model.serializer.toUUIDOrNull
import org.koin.android.ext.android.inject
import org.koin.androidx.viewmodel.ext.android.activityViewModel

/**
 * « Qui regarde ? » : jusqu'à 6 profils de la famille (image d'animal + prénom, pastille « Enfants »).
 * OK ouvre l'accueil du profil. La sélection démarre sur le dernier profil choisi, sans l'ouvrir toute seule.
 * Remplace l'écran d'origine « choix de l'utilisateur » (ServerFragment) au démarrage.
 */
class QuiRegardeFragment : Fragment() {
	companion object {
		const val ARG_SERVER_ID = "server_id"
		private const val MAX_PROFILS = 6
	}

	private val startupViewModel: StartupViewModel by activityViewModel()
	private val backgroundService: BackgroundService by inject()
	private val serverId get() = arguments?.getString(ARG_SERVER_ID)?.ifBlank { null }?.toUUIDOrNull()

	override fun onCreateView(inflater: LayoutInflater, container: ViewGroup?, savedInstanceState: Bundle?): View {
		val server = serverId?.let(startupViewModel::getServer)
		return ComposeView(requireContext()).apply {
			setViewCompositionStrategy(ViewCompositionStrategy.DisposeOnViewTreeLifecycleDestroyed)
			setContent { if (server != null) Contenu(server) }
		}
	}

	override fun onResume() {
		super.onResume()
		startupViewModel.reloadStoredServers()
		backgroundService.clearBackgrounds()
		serverId?.let(startupViewModel::getServer)?.let(startupViewModel::loadUsers)
	}

	private fun choisir(server: Server, user: User) {
		MirtvReglages.retenirProfil(requireContext(), user.id.toString())
		startupViewModel.authenticate(server, user).onEach { etat ->
			when (etat) {
				AuthenticatingState, AuthenticatedState -> Unit
				RequireSignInState -> parentFragmentManager.commit {
					replace<UserLoginFragment>(
						R.id.content_view, null,
						createBundle {
							putString(UserLoginFragment.ARG_SERVER_ID, server.id.toString())
							putString(UserLoginFragment.ARG_USERNAME, user.name)
						},
					)
					addToBackStack(null)
				}
				ServerUnavailableState, is ApiClientErrorLoginState, is ServerVersionNotSupported ->
					Toast.makeText(context, R.string.mirtv_serveur_injoignable, Toast.LENGTH_LONG).show()
			}
		}.launchIn(lifecycleScope)
	}

	private fun seConnecter(server: Server) {
		parentFragmentManager.commit {
			replace<UserLoginFragment>(
				R.id.content_view, null,
				createBundle {
					putString(UserLoginFragment.ARG_SERVER_ID, server.id.toString())
					putString(UserLoginFragment.ARG_USERNAME, null)
				},
			)
			addToBackStack(null)
		}
	}

	@Composable
	private fun Contenu(server: Server) {
		val profils by startupViewModel.users.collectAsState()
		val liste = profils.take(MAX_PROFILS)
		val dernier = remember { MirtvReglages.dernierProfil(requireContext()) }
		// Dernière case : « Se connecter » (le serveur ne publie pas la liste des profils : on se connecte à la main une fois, puis le profil est mémorisé sur la box)
		val elements: List<User?> = liste + listOf(null)
		val demandes = remember(elements.size) { List(elements.size) { FocusRequester() } }
		val libelleParent = stringResource(R.string.profil_parent)
		BackHandler { } // pas de sortie de l'appli depuis « Qui regarde ? »
		LaunchedEffect(liste.map { it.id }) {
			val i = liste.indexOfFirst { it.id.toString() == dernier }.let { if (it >= 0) it else 0 }
			runCatching { demandes[i].requestFocus() }
		}
		Box(Modifier.fillMaxSize().background(MirtvCouleurs.Nuit)) {
			Column(
				Modifier.fillMaxSize().padding(horizontal = MirtvTailles.MargeHorizontale, vertical = MirtvTailles.MargeVerticale),
				verticalArrangement = Arrangement.SpaceBetween,
			) {
				MirtvTopBar(titre = stringResource(R.string.qui_regarde_titre), profil = null)
				Column(
					Modifier.weight(1f).fillMaxSize(),
					verticalArrangement = Arrangement.Center,
					horizontalAlignment = Alignment.CenterHorizontally,
				) {
					if (liste.isEmpty()) {
						BasicText(
							stringResource(R.string.qui_regarde_aucun),
							style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.SemiBold, MirtvCouleurs.Doux),
						)
						Spacer(Modifier.height(24.dp))
					}
					elements.chunked(3).forEachIndexed { rang, ligne ->
						if (rang > 0) Spacer(Modifier.height(32.dp))
						Row(horizontalArrangement = Arrangement.spacedBy(40.dp)) {
							ligne.forEachIndexed { col, user ->
								val index = rang * 3 + col
								if (user == null) {
									MirtvCarte(
										onClick = { seConnecter(server) },
										description = stringResource(R.string.qui_regarde_se_connecter),
										focusRequester = demandes[index],
										modifier = Modifier.width(150.dp),
									) {
										Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(8.dp)) {
											Box(
												Modifier.size(104.dp).background(MirtvCouleurs.Nuit3, RoundedCornerShape(50)),
												contentAlignment = Alignment.Center,
											) {
												BasicText("+", style = MirtvPolice.style(MirtvTailles.TexteAccueilTitre, FontWeight.ExtraBold, MirtvCouleurs.Blanc))
											}
											Spacer(Modifier.height(10.dp))
											BasicText(
												stringResource(R.string.qui_regarde_se_connecter),
												style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.Bold, MirtvCouleurs.Blanc),
											)
										}
									}
									return@forEachIndexed
								}
								val enfant = MirtvProfils.estEnfant(user.name)
								val prenom = MirtvProfils.nomAffiche(user.name, libelleParent)
								MirtvCarte(
									onClick = { choisir(server, user) },
									description = stringResource(R.string.profil_description, prenom),
									focusRequester = demandes[index],
									modifier = Modifier.width(150.dp),
								) {
									Column(horizontalAlignment = Alignment.CenterHorizontally, modifier = Modifier.padding(8.dp)) {
										MirtvAnimalImage(MirtvAnimal.pour(index), 104.dp)
										Spacer(Modifier.height(10.dp))
										BasicText(
											prenom,
											style = MirtvPolice.style(MirtvTailles.TexteMessage, FontWeight.Bold, MirtvCouleurs.Blanc),
										)
										if (enfant) {
											Spacer(Modifier.height(6.dp))
											Box(
												Modifier.background(MirtvCouleurs.Feuille, RoundedCornerShape(50)).padding(horizontal = 12.dp, vertical = 3.dp),
											) {
												BasicText(
													stringResource(R.string.pastille_enfants),
													style = MirtvPolice.style(MirtvTailles.TexteCourant, FontWeight.Bold, MirtvCouleurs.Encre),
												)
											}
										} else Spacer(Modifier.size(1.dp).height(0.dp))
									}
								}
							}
						}
					}
				}
				MirtvKeyHints(listOf(stringResource(R.string.indice_fleches_choisir), stringResource(R.string.indice_ok_ouvrir)))
			}
		}
	}
}
