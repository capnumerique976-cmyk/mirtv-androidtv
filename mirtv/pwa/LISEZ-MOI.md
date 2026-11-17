# MirTV — appli web (PWA)

Version web de MirTV pour télé connectée (navigateur), ordinateur et téléphone. Elle s'installe
comme une appli (« Ajouter à l'écran d'accueil » / bouton « Installer MirTV sur cet appareil »).

- Adresse : https://mirtv.rahiss.com/tv/
- Parle directement à Jellyfin, sur la même adresse (pas de serveur en plus).
- Textes : générés depuis `design/textes.csv` du kit (`scripts/textes-vers-web.py --pwa ...`). Ne pas modifier `js/textes.js` à la main.
- Réglages : `js/config.js` (limite d'écrans, décalage horaire, numéro WhatsApp, code parent de test).
- Lecteur : hls.js (licence Apache 2.0, `vendor/hls.LICENSE`). Police : Lexend (licence OFL).
- Installation sur le serveur : `scripts/deployer-pwa.sh` du kit.
- Tests : `mirtv/tests-web` (Playwright), avec un faux Jellyfin pour les parcours et sur le vrai site.
