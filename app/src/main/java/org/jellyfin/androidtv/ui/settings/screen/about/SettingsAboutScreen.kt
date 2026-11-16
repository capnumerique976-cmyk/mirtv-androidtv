package org.jellyfin.androidtv.ui.settings.screen.about

import android.content.ClipData
import android.os.Build
import androidx.compose.runtime.Composable
import androidx.compose.ui.Modifier
import androidx.compose.ui.res.painterResource
import androidx.compose.ui.res.stringResource
import org.jellyfin.androidtv.BuildConfig
import org.jellyfin.androidtv.R
import org.jellyfin.androidtv.ui.base.Icon
import org.jellyfin.androidtv.ui.base.Text
import org.jellyfin.androidtv.ui.base.list.ListButton
import org.jellyfin.androidtv.ui.base.list.ListSection
import org.jellyfin.androidtv.ui.navigation.LocalRouter
import org.jellyfin.androidtv.ui.navigation.focus.focusKey
import org.jellyfin.androidtv.ui.settings.Routes
import org.jellyfin.androidtv.ui.settings.composable.SettingsColumn
import org.jellyfin.androidtv.ui.settings.util.copyAction

@Composable
fun SettingsAboutScreen(launchedFromLogin: Boolean = false) {
	val router = LocalRouter.current

	SettingsColumn {
		if (launchedFromLogin) item {
			ListSection(
				overlineContent = { Text(stringResource(R.string.pref_login).uppercase()) },
				headingContent = { Text(stringResource(R.string.pref_about_title)) },
			)
		} else item {
			ListSection(
				overlineContent = { Text(stringResource(R.string.settings).uppercase()) },
				headingContent = { Text(stringResource(R.string.pref_about_title)) },
			)
		}

		item {
			// MirTV: version MirTV
			val heading = stringResource(R.string.apropos_version)
			val caption = "MirTV ${BuildConfig.VERSION_NAME} (${BuildConfig.VERSION_CODE}) ${BuildConfig.BUILD_TYPE}"
			ListButton(
				leadingContent = { Icon(painterResource(R.drawable.ic_jellyfin), contentDescription = null) },
				headingContent = { Text(heading) },
				captionContent = { Text(caption) },
				onClick = copyAction(ClipData.newPlainText(heading, caption)),
				modifier = Modifier.focusKey("version")
			)
		}

		// MirTV: mention de licence et adresse du code source (licence GPL)
		item {
			val heading = stringResource(R.string.apropos_licence)
			val caption = stringResource(R.string.apropos_code_source) + " : " + BuildConfig.MIRTV_CODE_SOURCE
			ListButton(
				leadingContent = { Icon(painterResource(R.drawable.ic_guide), contentDescription = null) },
				headingContent = { Text(heading) },
				captionContent = { Text(caption) },
				onClick = copyAction(ClipData.newPlainText(heading, BuildConfig.MIRTV_CODE_SOURCE)),
				modifier = Modifier.focusKey("mirtv_licence")
			)
		}

		item {
			val heading = stringResource(R.string.pref_device_model)
			val caption = "${Build.MANUFACTURER} ${Build.MODEL}"
			ListButton(
				leadingContent = { Icon(painterResource(R.drawable.ic_tv), contentDescription = null) },
				headingContent = { Text(heading) },
				captionContent = { Text(caption) },
				onClick = copyAction(ClipData.newPlainText(heading, caption)),
				modifier = Modifier.focusKey("device_model")
			)
		}

		item {
			ListButton(
				leadingContent = { Icon(painterResource(R.drawable.ic_guide), contentDescription = null) },
				headingContent = { Text(stringResource(R.string.licenses_link)) },
				onClick = { router.push(Routes.LICENSES) },
				modifier = Modifier.focusKey(Routes.LICENSES)
			)
		}

		if (!launchedFromLogin) item {
			ListButton(
				leadingContent = { Icon(painterResource(R.drawable.ic_flask), contentDescription = null) },
				headingContent = { Text(stringResource(R.string.pref_developer_link)) },
				onClick = { router.push(Routes.DEVELOPER) },
				modifier = Modifier.focusKey(Routes.DEVELOPER)
			)
		}
	}
}
