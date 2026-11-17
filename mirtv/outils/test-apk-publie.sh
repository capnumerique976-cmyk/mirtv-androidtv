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
