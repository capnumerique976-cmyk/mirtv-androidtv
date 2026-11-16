package org.jellyfin.androidtv.mirtv

import android.annotation.SuppressLint
import android.app.Activity
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInfo
import android.content.pm.PackageInstaller
import android.content.pm.PackageManager
import android.os.Build
import androidx.core.content.edit
import androidx.work.BackoffPolicy
import androidx.work.Constraints
import androidx.work.CoroutineWorker
import androidx.work.ExistingPeriodicWorkPolicy
import androidx.work.ExistingWorkPolicy
import androidx.work.NetworkType
import androidx.work.OneTimeWorkRequestBuilder
import androidx.work.PeriodicWorkRequestBuilder
import androidx.work.WorkManager
import androidx.work.WorkerParameters
import androidx.work.await
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import org.jellyfin.androidtv.BuildConfig
import org.json.JSONObject
import timber.log.Timber
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.security.MessageDigest
import java.util.concurrent.TimeUnit

/**
 * MirTV : mise à jour de l'appli sans passer par un magasin d'applis.
 *
 * 1. Au démarrage puis toutes les 12 heures, [MiseAJourWorker] lit le fichier version.json du canal
 *    (« stable » pour les clients, « test » pour les box de Ravi et de Web Agence, voir [MirtvCanal]).
 * 2. Si la version est plus récente : téléchargement, vérification de l'empreinte SHA-256 **et** du certificat
 *    de signature (il doit être le même que celui de l'appli installée). Sinon le fichier est jeté.
 * 3. L'installation est proposée seulement au démarrage de MirTV ([proposerSiPrete]), jamais pendant une lecture.
 *    Android affiche alors son écran « Installer » (un appui), sauf si MirTV est propriétaire de l'appareil.
 */
object MiseAJour {
	private const val PREFS = "mirtv_mise_a_jour"
	private const val CLE_FICHIER = "fichier"
	private const val CLE_CODE = "versionCode"
	private const val NOM_PERIODIQUE = "MirtvMiseAJourPeriodique"
	private const val NOM_IMMEDIAT = "MirtvMiseAJourImmediate"
	internal const val ACTION_RESULTAT = "com.rahiss.mirtv.MISE_A_JOUR_RESULTAT"

	/** À appeler après chaque `cancelAllWork()` de l'appli (sinon la vérification périodique disparaît). */
	suspend fun planifier(workManager: WorkManager) {
		val reseau = Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build()
		workManager.enqueueUniquePeriodicWork(
			NOM_PERIODIQUE,
			ExistingPeriodicWorkPolicy.UPDATE,
			PeriodicWorkRequestBuilder<MiseAJourWorker>(12, TimeUnit.HOURS)
				.setConstraints(reseau)
				.setBackoffCriteria(BackoffPolicy.LINEAR, 30, TimeUnit.MINUTES)
				.build()
		).await()
		workManager.enqueueUniqueWork(
			NOM_IMMEDIAT,
			ExistingWorkPolicy.KEEP,
			OneTimeWorkRequestBuilder<MiseAJourWorker>().setConstraints(reseau).build()
		).await()
	}

	internal fun preferences(context: Context) = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)

	internal fun versionInstallee(context: Context): Long = infoPaquet(context.packageManager, context.packageName)
		?.let { if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) it.longVersionCode else @Suppress("DEPRECATION") it.versionCode.toLong() }
		?: BuildConfig.VERSION_CODE.toLong()

	internal fun marquerPrete(context: Context, fichier: File, versionCode: Long) = preferences(context).edit {
		putString(CLE_FICHIER, fichier.absolutePath)
		putLong(CLE_CODE, versionCode)
	}

	private fun oublier(context: Context) = preferences(context).edit { clear() }

	/**
	 * À appeler au démarrage de MirTV (écran de démarrage) : propose l'installation d'une mise à jour prête.
	 * Le travail (contrôles, copie du fichier) se fait hors du fil de l'interface.
	 */
	fun proposerSiPrete(activity: Activity) {
		val contexte = activity.applicationContext
		val prefs = preferences(contexte)
		val chemin = prefs.getString(CLE_FICHIER, null) ?: return
		val code = prefs.getLong(CLE_CODE, 0)
		Thread {
			val fichier = File(chemin)
			when {
				!fichier.isFile || code <= versionInstallee(contexte) -> {
					fichier.delete()
					oublier(contexte)
				}
				// Contrôle de sécurité refait juste avant l'installation (le fichier aurait pu changer)
				!memeCertificat(contexte, fichier) -> {
					Timber.w("MirTV : certificat de la mise à jour différent, fichier supprimé")
					fichier.delete()
					oublier(contexte)
				}
				else -> runCatching { installer(contexte, fichier) }
					.onFailure { Timber.w(it, "MirTV : installation de la mise à jour impossible") }
			}
		}.apply { name = "mirtv-mise-a-jour" }.start()
	}

	private fun installer(context: Context, fichier: File) {
		val installateur = context.packageManager.packageInstaller
		val parametres = PackageInstaller.SessionParams(PackageInstaller.SessionParams.MODE_FULL_INSTALL).apply {
			setAppPackageName(context.packageName)
			if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) setRequireUserAction(PackageInstaller.SessionParams.USER_ACTION_NOT_REQUIRED)
		}
		val id = installateur.createSession(parametres)
		installateur.openSession(id).use { session ->
			session.openWrite("mirtv.apk", 0, fichier.length()).use { sortie ->
				fichier.inputStream().use { it.copyTo(sortie) }
				session.fsync(sortie)
			}
			val intent = Intent(context, MiseAJourReceiver::class.java).setAction(ACTION_RESULTAT)
			val drapeaux = PendingIntent.FLAG_UPDATE_CURRENT or
				(if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) PendingIntent.FLAG_MUTABLE else 0)
			session.commit(PendingIntent.getBroadcast(context, id, intent, drapeaux).intentSender)
		}
		Timber.i("MirTV : installation de la mise à jour lancée")
	}

	// ---------- contrôles ----------

	internal fun sha256(fichier: File): String {
		val md = MessageDigest.getInstance("SHA-256")
		fichier.inputStream().use { entree ->
			val tampon = ByteArray(64 * 1024)
			while (true) {
				val n = entree.read(tampon)
				if (n < 0) break
				md.update(tampon, 0, n)
			}
		}
		return md.digest().joinToString("") { "%02x".format(it) }
	}

	@SuppressLint("PackageManagerGetSignatures")
	private fun infoPaquet(pm: PackageManager, paquet: String): PackageInfo? = runCatching {
		if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) pm.getPackageInfo(paquet, PackageManager.GET_SIGNING_CERTIFICATES)
		else @Suppress("DEPRECATION") pm.getPackageInfo(paquet, PackageManager.GET_SIGNATURES)
	}.getOrNull()

	@SuppressLint("PackageManagerGetSignatures")
	private fun certificats(info: PackageInfo?): Set<String> {
		if (info == null) return emptySet()
		val signatures = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P) {
			val s = info.signingInfo ?: return emptySet()
			if (s.hasMultipleSigners()) s.apkContentsSigners else s.signingCertificateHistory
		} else @Suppress("DEPRECATION") info.signatures
		val md = MessageDigest.getInstance("SHA-256")
		return signatures.orEmpty().map { sig -> md.digest(sig.toByteArray()).joinToString("") { "%02x".format(it) } }.toSet()
	}

	/** Vrai si l'APK est signé par le même certificat que l'appli installée. */
	@SuppressLint("PackageManagerGetSignatures")
	internal fun memeCertificat(context: Context, fichier: File): Boolean {
		val pm = context.packageManager
		val archive = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.P)
			pm.getPackageArchiveInfo(fichier.absolutePath, PackageManager.GET_SIGNING_CERTIFICATES)
		else @Suppress("DEPRECATION") pm.getPackageArchiveInfo(fichier.absolutePath, PackageManager.GET_SIGNATURES)
		if (archive?.packageName != context.packageName) return false
		val installes = certificats(infoPaquet(pm, context.packageName))
		val nouveaux = certificats(archive)
		return installes.isNotEmpty() && nouveaux.isNotEmpty() && installes.intersect(nouveaux).isNotEmpty()
	}
}

/** Canal de mise à jour : « stable » (clients) ou « test » (box de Ravi et de Web Agence). */
object MirtvCanal {
	private const val PREFS = "mirtv_canal"
	private const val CLE = "canal"
	const val STABLE = "stable"
	const val TEST = "test"

	fun lire(context: Context): String = context.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
		.getString(CLE, STABLE) ?: STABLE

	fun ecrire(context: Context, canal: String) {
		require(canal == STABLE || canal == TEST)
		context.getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit { putString(CLE, canal) }
	}

	fun adresseVersion(context: Context): String =
		BuildConfig.MIRTV_MISE_A_JOUR + (if (lire(context) == TEST) "test/" else "") + "version.json"
}

/**
 * Règle le canal depuis le script de préparation de la box (adb) :
 * adb shell am broadcast -a com.rahiss.mirtv.CANAL --es canal test -n com.rahiss.mirtv/org.jellyfin.androidtv.mirtv.CanalReceiver
 * Réservé au shell adb (permission DUMP exigée de l'expéditeur dans le manifeste).
 */
class CanalReceiver : BroadcastReceiver() {
	override fun onReceive(context: Context, intent: Intent) {
		val canal = intent.getStringExtra("canal") ?: return
		runCatching { MirtvCanal.ecrire(context, canal) }
			.onSuccess { Timber.i("MirTV : canal de mise à jour = $canal") }
			.onFailure { Timber.w("MirTV : canal inconnu $canal") }
	}
}

/** Résultat de l'installation (PackageInstaller) : affiche l'écran « Installer » d'Android si besoin. */
class MiseAJourReceiver : BroadcastReceiver() {
	override fun onReceive(context: Context, intent: Intent) {
		when (val statut = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, PackageInstaller.STATUS_FAILURE)) {
			PackageInstaller.STATUS_PENDING_USER_ACTION -> {
				val confirmation = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.TIRAMISU)
					intent.getParcelableExtra(Intent.EXTRA_INTENT, Intent::class.java)
				else @Suppress("DEPRECATION") intent.getParcelableExtra(Intent.EXTRA_INTENT)
				confirmation?.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)?.let { runCatching { context.startActivity(it) } }
			}
			PackageInstaller.STATUS_SUCCESS -> Timber.i("MirTV : mise à jour installée")
			else -> Timber.w("MirTV : mise à jour refusée ou en échec (statut $statut)")
		}
	}
}

/** Vérifie le canal, télécharge et contrôle la nouvelle version (sans l'installer). */
class MiseAJourWorker(
	private val context: Context,
	params: WorkerParameters,
) : CoroutineWorker(context, params) {
	override suspend fun doWork(): Result = withContext(Dispatchers.IO) {
		runCatching { verifier() }
			.onFailure { Timber.w(it, "MirTV : vérification de mise à jour impossible") }
		Result.success()
	}

	private fun lireTexte(adresse: String): String {
		val connexion = URL(adresse).openConnection() as HttpURLConnection
		connexion.connectTimeout = 15_000
		connexion.readTimeout = 30_000
		connexion.setRequestProperty("Cache-Control", "no-cache")
		try {
			if (connexion.responseCode != 200) error("version.json : code ${connexion.responseCode}")
			return connexion.inputStream.bufferedReader().use { it.readText() }
		} finally {
			connexion.disconnect()
		}
	}

	private fun telecharger(adresse: String, cible: File) {
		val connexion = URL(adresse).openConnection() as HttpURLConnection
		connexion.connectTimeout = 15_000
		connexion.readTimeout = 60_000
		try {
			if (connexion.responseCode != 200) error("APK : code ${connexion.responseCode}")
			cible.outputStream().use { sortie -> connexion.inputStream.use { it.copyTo(sortie) } }
		} finally {
			connexion.disconnect()
		}
	}

	private fun verifier() {
		val json = JSONObject(lireTexte(MirtvCanal.adresseVersion(context)))
		val code = json.getLong("versionCode")
		val url = json.getString("url")
		val sha = json.getString("sha256").lowercase()
		if (code <= MiseAJour.versionInstallee(context)) return
		if (!url.startsWith("https://")) error("adresse de l'APK refusée")

		val dossier = File(context.cacheDir, "mise-a-jour").apply { mkdirs() }
		dossier.listFiles()?.forEach { it.delete() }
		val fichier = File(dossier, "mirtv-$code.apk")
		telecharger(url, fichier)
		if (MiseAJour.sha256(fichier) != sha) {
			fichier.delete(); error("empreinte SHA-256 différente")
		}
		if (!MiseAJour.memeCertificat(context, fichier)) {
			fichier.delete(); error("certificat de signature différent")
		}
		MiseAJour.marquerPrete(context, fichier, code)
		Timber.i("MirTV : version $code prête, installation proposée au prochain démarrage")
	}
}
