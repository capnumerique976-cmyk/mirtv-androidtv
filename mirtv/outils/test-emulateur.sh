#!/usr/bin/env bash
# Test de démarrage dans l'émulateur : installe l'APK, lance MirTV, attend, fait des captures,
# cherche un plantage (FATAL EXCEPTION) et le mot « Jellyfin » à l'écran.
# Usage : test-emulateur.sh <apk-signé>
APP=com.rahiss.mirtv
SORTIE=sortie-test
mkdir -p "$SORTIE"
RESULTAT=0

adb install -r "$1" || { echo "ECHEC installation"; exit 1; }
adb logcat -c
adb shell am start -W -n "$APP/org.jellyfin.androidtv.ui.startup.StartupActivity"
sleep 8
adb exec-out screencap -p > "$SORTIE/01-apres-8s.png"
sleep 22
adb exec-out screencap -p > "$SORTIE/02-apres-30s.png"
adb shell uiautomator dump /sdcard/ecran.xml >/dev/null 2>&1
adb pull /sdcard/ecran.xml "$SORTIE/ecran.xml" >/dev/null 2>&1
adb logcat -d > "$SORTIE/logcat.txt"

if ! adb shell pidof "$APP" | grep -q '[0-9]'; then
  echo "ECHEC : l'appli ne tourne plus (plantage)"; RESULTAT=1
fi
if grep -q "FATAL EXCEPTION" "$SORTIE/logcat.txt"; then
  echo "ECHEC : plantage détecté"; grep -A25 "FATAL EXCEPTION" "$SORTIE/logcat.txt" | head -60; RESULTAT=1
fi
if [ -f "$SORTIE/ecran.xml" ] && grep -qi "jellyfin" "$SORTIE/ecran.xml"; then
  echo "AVERTISSEMENT : le mot Jellyfin est visible à l'écran"; grep -io '.\{40\}jellyfin.\{40\}' "$SORTIE/ecran.xml" | head -5
fi
# Navigation simple : droite, OK, puis nouvelle capture
adb shell input keyevent KEYCODE_DPAD_CENTER; sleep 6
adb exec-out screencap -p > "$SORTIE/03-apres-ok.png"
adb logcat -d > "$SORTIE/logcat.txt"
grep -q "FATAL EXCEPTION" "$SORTIE/logcat.txt" && { echo "ECHEC : plantage après OK"; grep -A25 "FATAL EXCEPTION" "$SORTIE/logcat.txt" | head -60; RESULTAT=1; }
exit $RESULTAT
