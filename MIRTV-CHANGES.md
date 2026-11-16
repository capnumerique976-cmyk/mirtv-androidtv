# Modifications MirTV des fichiers d'origine

Base : `jellyfin/jellyfin-androidtv`, branche `release-0.20.z` (commit `45c5292`).
Règle : le code MirTV va dans `mirtv/` et `org.jellyfin.androidtv.mirtv` ; un fichier d'origine n'est modifié
qu'au minimum, chaque retouche de code est marquée `// MirTV:`. Les fonctions d'origine sont cachées plutôt que supprimées.

| Fichier d'origine | Modification | Pourquoi |
|---|---|---|
| `README.md` | renommé en `README-jellyfin.md` ; nouveau `README.md` MirTV | présenter MirTV, garder le texte d'origine |
| `app/build.gradle.kts` | `applicationId` `com.rahiss.mirtv`, version MirTV (`mirtv.version.*`), identifiants de recherche, nom des APK, champs `MIRTV_*` de `BuildConfig` | appli distincte, installable à côté de l'officielle ; serveur préréglé |
| `app/src/main/res/values/strings.xml` | `app_name_release` / `app_name_debug` = MirTV | nom de l'appli |
| `app/src/main/res/drawable/app_logo.xml`, `app_banner_background.xml`, `app_banner_foreground.xml`, `app_icon_background.xml`, `app_icon_foreground.xml`, `app_icon_foreground_monochrome.xml`, `ic_jellyfin.xml` | dessins MirTV générés par `mirtv/outils/generer_logos.py` | aucun logo Jellyfin visible |
| `app/src/main/res/mipmap-*/app_icon.png`, `app_banner.png` | supprimés, remplacés par `mipmap-anydpi/app_icon.xml` et `app_banner.xml` (vectoriels) | aucun logo Jellyfin visible |

Ajouts (pas de fichier d'origine touché) :

| Fichier | Rôle |
|---|---|
| `mirtv/outils/marque.py` | remplace « Jellyfin » par « MirTV » dans les textes visibles, au moment de la compilation |
| `mirtv/outils/generer_logos.py` | génère les logos vectoriels depuis Lexend |
| `app/src/main/res/font/lexend_*.ttf`, `mirtv/licences/Lexend-OFL.txt` | police Lexend (OFL) |
| `.github/workflows/mirtv-build.yaml` | fabrication de l'APK non signé et publication en « release » GitHub |
| `MIRTV-CHANGES.md`, `README.md` | documentation du fork |
