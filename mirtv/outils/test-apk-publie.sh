#!/usr/bin/env bash
# Télécharge l'APK réellement publié sur mirtv.rahiss.com (canal test), vérifie son empreinte,
# l'installe dans l'émulateur et le lance. Affiche l'erreur exacte d'Android si l'installation échoue.
CANAL="${1:-test}"
mkdir -p sortie-test
curl -fsSL "https://mirtv.rahiss.com/app/$CANAL/version.json" -o sortie-test/version.json || { echo "ECHEC : version.json introuvable"; exit 1; }
cat sortie-test/version.json
URL=$(python3 -c "import json;print(json.load(open('sortie-test/version.json'))['url'])")
SHA=$(python3 -c "import json;print(json.load(open('sortie-test/version.json'))['sha256'])")
time curl -fsSL "$URL" -o publie.apk || { echo "ECHEC : téléchargement"; exit 1; }
ls -l publie.apk
echo "$SHA  publie.apk" | sha256sum -c - || { echo "ECHEC : empreinte différente de version.json"; exit 1; }
BT=$(ls -d "$ANDROID_HOME"/build-tools/* | sort -V | tail -n 1)
echo "=== Manifeste (aapt2) ==="
"$BT/aapt2" dump badging publie.apk 2>&1 | grep -E "^package|sdkVersion|targetSdk|native-code|uses-feature|application-label:|leanback" | head -20
"$BT/apksigner" verify -v publie.apk 2>&1 | head -6
echo "=== Installation comme sur un téléphone (fichier dans Téléchargements, ouvert par l'installateur) ==="
adb push publie.apk /sdcard/Download/mirtv-test.apk >/dev/null
adb shell am broadcast -a android.intent.action.MEDIA_SCANNER_SCAN_FILE -d file:///sdcard/Download/mirtv-test.apk >/dev/null 2>&1
sleep 4
ID=$(adb shell content query --uri content://media/external/file --projection _id:_display_name 2>/dev/null | grep "mirtv-test.apk" | sed -n 's/.*_id=\([0-9]*\).*/\1/p' | head -1)
echo "id média : $ID"
if [ -n "$ID" ]; then
  adb shell am start -a android.intent.action.VIEW -t application/vnd.android.package-archive -d "content://media/external/file/$ID" --grant-read-uri-permission 2>&1 | tail -2
  sleep 6
  adb shell uiautomator dump /sdcard/i.xml >/dev/null 2>&1; adb pull /sdcard/i.xml sortie-test/installateur.xml >/dev/null 2>&1
  echo "--- textes de l'installateur :"
  grep -o 'text="[^"]\+"' sortie-test/installateur.xml | head -15
  adb exec-out screencap -p > sortie-test/installateur.png
  adb shell input keyevent KEYCODE_BACK; adb shell input keyevent KEYCODE_BACK
fi
echo "=== Installation ==="
adb install -r publie.apk 2>&1 | tee sortie-test/installation.txt
grep -q "Success" sortie-test/installation.txt || { echo "ECHEC installation (voir ligne ci-dessus)"; exit 1; }
adb logcat -c
adb shell am start -W -n com.rahiss.mirtv/org.jellyfin.androidtv.ui.startup.StartupActivity
sleep 20
adb exec-out screencap -p > sortie-test/publie-demarrage.png
adb shell uiautomator dump /sdcard/e.xml >/dev/null 2>&1; adb pull /sdcard/e.xml sortie-test/e.xml >/dev/null 2>&1
grep -o 'text="[^"]\+"' sortie-test/e.xml | head -15
adb logcat -d | grep -q "FATAL EXCEPTION" && { echo "ECHEC : plantage"; exit 1; }
echo "OK : l'APK publié s'installe et démarre"
