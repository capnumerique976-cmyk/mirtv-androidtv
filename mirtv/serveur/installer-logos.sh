#!/usr/bin/env bash
# MirTV : logos des chaînes pour l'appli web. Relançable sans danger.
# Ajoute (une seule fois) l'adresse https://mirtv.rahiss.com/tv/logos/ dans Caddy,
# puis cherche les logos officiels (base libre iptv-org) avec logos-mirtv.py.
set -euo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
CADDY=/etc/caddy/sites.d/mirtv.caddy
# /tv/logos/ = /opt/mirtv/images-web/logos (une seule fois), puis recherche des logos
sudo mkdir -p /opt/mirtv/images-web/logos
if ! sudo grep -q "logos-mirtv" "$CADDY"; then
  sudo cp "$CADDY" "$CADDY.avant-logos"
  sudo python3 - "$CADDY" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
repere = "\thandle_path /tv/* {"
bloc = """\t# logos-mirtv : logos des chaînes (mirtv/serveur/logos-mirtv.py)
\thandle_path /tv/logos/* {
\t\troot * /opt/mirtv/images-web/logos
\t\theader Cache-Control "public, max-age=3600"
\t\tfile_server
\t}
"""
assert repere in s, "repère /tv/ introuvable dans le fichier Caddy MirTV"
open(p, "w").write(s.replace(repere, bloc + repere, 1))
PY
  if sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
    sudo systemctl reload caddy && echo "Caddy rechargé (logos)"
  else
    echo "Configuration logos refusée : retour à l'ancienne"; sudo cp "$CADDY.avant-logos" "$CADDY"
  fi
fi
sudo python3 "$ICI/logos-mirtv.py" | tail -3 || echo "Logos : recherche impossible pour le moment (l'appli affiche les initiales)"

