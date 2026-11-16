package org.jellyfin.androidtv.mirtv

import android.app.admin.DeviceAdminReceiver

/**
 * MirTV : récepteur d'administration de l'appareil.
 * Sert uniquement à l'option « propriétaire de l'appareil » du script de préparation des box
 * (adb shell dpm set-device-owner com.rahiss.mirtv/org.jellyfin.androidtv.mirtv.MirtvAdminReceiver).
 * Aucune politique n'est demandée pour l'instant (res/xml/mirtv_device_admin.xml est vide).
 */
class MirtvAdminReceiver : DeviceAdminReceiver()
