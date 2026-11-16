# MirTV pour Android TV

MirTV est le service de télévision en ligne de **RAHISS** pour Madagascar (lancement à Diego-Suarez).
Cette appli est celle des box MirTV.

**MirTV n'est pas le projet Jellyfin.** Elle est construite à partir de
[Jellyfin pour Android TV](https://github.com/jellyfin/jellyfin-androidtv) (branche `release-0.20.z`),
logiciel libre sous licence **GNU GPL v2** ; MirTV est distribuée sous la même licence (fichier `LICENSE`).
Le texte d'origine du projet est conservé dans [`README-jellyfin.md`](README-jellyfin.md).

## Ce qui change par rapport à Jellyfin pour Android TV

- Nom, icône, bannière et logo MirTV ; paquet `com.rahiss.mirtv` (s'installe à côté de l'appli officielle).
- Le détail des fichiers d'origine modifiés est dans [`MIRTV-CHANGES.md`](MIRTV-CHANGES.md).
- Le code ajouté par MirTV est regroupé dans le dossier `mirtv/` et dans le paquet `org.jellyfin.androidtv.mirtv`.

## Compiler

Prérequis : JDK 21 (voir `.tools-versions`), Android SDK.

```sh
python3 mirtv/outils/marque.py          # applique le nom MirTV aux textes visibles
./gradlew assembleRelease               # APK non signé dans app/build/outputs/apk/release/
```

Les versions officielles sont fabriquées par GitHub Actions (`.github/workflows/mirtv-build.yaml`) à chaque envoi
sur la branche `mirtv`, puis signées et publiées par le serveur MirTV (la clé de signature ne quitte jamais le serveur).

## Licences

- Code : GNU GPL v2 (`LICENSE`), auteurs d'origine : le projet Jellyfin et ses contributeurs.
- Police Lexend : SIL Open Font License 1.1 (`mirtv/licences/Lexend-OFL.txt`).

Contact : contact@rahiss.com
