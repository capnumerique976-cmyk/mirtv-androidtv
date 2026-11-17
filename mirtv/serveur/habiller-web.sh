#!/usr/bin/env bash
# MirTV : retire « Jellyfin » de la page web du serveur (https://mirtv.rahiss.com/web/).
#  - écran de chargement, logos, icônes, favicon : remplacés par ceux de MirTV (dossier marque-web/) ;
#  - titre, manifeste, textes des traductions (français, anglais, malgache) : « Jellyfin » → « MirTV ».
# Les fichiers d'origine de Jellyfin ne sont pas modifiés : Caddy sert nos copies à leur place.
# À relancer après chaque mise à jour de l'image Jellyfin (les noms de fichiers changent). Sans danger.
set -euo pipefail
ICI=$(cd "$(dirname "$0")" && pwd)
CONTENEUR=mirtv-jellyfin-1
WEB=/jellyfin/jellyfin-web
DEST=/opt/mirtv/habillage/web
CADDY=/etc/caddy/sites.d/mirtv.caddy

mkdir -p "$DEST.nouveau"
FICHIERS=$(docker exec "$CONTENEUR" sh -c "cd $WEB && ls")

# 1. Images : chaque fichier d'origine (nom avec empreinte) reçoit l'image MirTV du même type
for nom in icon-transparent banner-light banner-dark touchicon touchicon144 32px 40px notificationicon favicon; do
  for f in $(echo "$FICHIERS" | grep -E "^${nom}\.[0-9a-f]+\.(png|ico)$" || true); do
    ext=${f##*.}
    cp "$ICI/marque-web/$nom.$ext" "$DEST.nouveau/$f"
  done
done

# 2. Textes : page d'accueil, manifeste, traductions et nom de l'appli
for f in index.html manifest.json main.jellyfin.bundle.js $(echo "$FICHIERS" | grep -E '^(fr|en-us|en-gb|mg)-json\.[0-9a-f]+\.chunk\.js$' || true); do
  docker cp "$CONTENEUR:$WEB/$f" "$DEST.nouveau/$f" >/dev/null
  # « Jellyfin » écrit avec une majuscule = texte visible ; les adresses (jellyfin.org…) sont en minuscules et restent.
  sed -i 's/Jellyfin Web/MirTV/g; s/Jellyfin Server/MirTV/g; s/Jellyfin/MirTV/g' "$DEST.nouveau/$f"
done
sed -i 's#"short_name": *"[^"]*"#"short_name":"MirTV"#; s#"name": *"[^"]*"#"name":"MirTV"#' "$DEST.nouveau/manifest.json"

rm -rf "$DEST.ancien"; [ -d "$DEST" ] && mv "$DEST" "$DEST.ancien"; mv "$DEST.nouveau" "$DEST"
echo "Habillage MirTV : $(ls "$DEST" | wc -l) fichiers dans $DEST"

# 3. Caddy (une seule fois) : tout fichier présent dans /opt/mirtv/habillage/web remplace celui de Jellyfin
if ! sudo grep -q "habillage-mirtv-v2" "$CADDY"; then
  sudo cp "$CADDY" "$CADDY.avant-habillage-v2"
  sudo python3 - "$CADDY" <<'PY'
import re, sys
p = sys.argv[1]
s = open(p).read()
# ancien habillage (index.html et manifeste seuls) remplacé par le nouveau
s = re.sub(r"\t# habille-mirtv :.*?\n\n", "", s, flags=re.S)
repere = "\t# Jellyfin (conteneur"
bloc = """\t# habillage-mirtv-v2 : nom et logos MirTV sur la page web (voir mirtv/serveur/habiller-web.sh)
\t@accueilweb path /web /web/
\thandle @accueilweb {
\t\troot * /opt/mirtv/habillage
\t\trewrite * /web/index.html
\t\theader Cache-Control "no-cache"
\t\tfile_server
\t}
\t@habille {
\t\tpath_regexp habille ^/web/[^/]+\\.(html|json|js|png|ico)$
\t\tfile {
\t\t\troot /opt/mirtv/habillage
\t\t\ttry_files {path}
\t\t}
\t}
\thandle @habille {
\t\troot * /opt/mirtv/habillage
\t\theader Cache-Control "no-cache"
\t\tfile_server
\t}

"""
assert repere in s, "repère introuvable"
open(p, "w").write(s.replace(repere, bloc + repere, 1))
PY
  if sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
    sudo systemctl reload caddy && echo "Caddy rechargé"
  else
    echo "Configuration refusée : retour à l'ancienne"; sudo cp "$CADDY.avant-habillage-v2" "$CADDY"; exit 1
  fi
fi
for f in $(ls "$DEST" | grep -E '^icon-transparent|^index'); do
  curl -s -o /dev/null -w "Contrôle /web/$f : %{http_code}\n" "https://mirtv.rahiss.com/web/$f"
done
