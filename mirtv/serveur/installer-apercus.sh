#!/usr/bin/env bash
# MirTV : vignettes des chaînes (une image prise sur chaque chaîne toutes les 20 minutes). Relançable.
# - installe ffmpeg s'il manque
# - ajoute (une seule fois) l'adresse https://mirtv.rahiss.com/tv/apercus/ dans Caddy
# - installe le minuteur mirtv-apercus.timer
set -euo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
CADDY=/etc/caddy/sites.d/mirtv.caddy
command -v ffmpeg >/dev/null || { sudo apt-get update -qq && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ffmpeg >/dev/null; }
sudo mkdir -p /opt/mirtv/images-web/apercus
if ! sudo grep -q "apercus-mirtv" "$CADDY"; then
  sudo cp "$CADDY" "$CADDY.avant-apercus"
  sudo python3 - "$CADDY" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
repere = "\thandle_path /tv/* {"
bloc = """\t# apercus-mirtv : vignettes des chaînes (mirtv/serveur/apercus-mirtv.py)
\thandle_path /tv/apercus/* {
\t\troot * /opt/mirtv/images-web/apercus
\t\theader Cache-Control "public, max-age=300"
\t\tfile_server
\t}
"""
assert repere in s, "repère /tv/ introuvable dans le fichier Caddy MirTV"
open(p, "w").write(s.replace(repere, bloc + repere, 1))
PY
  if sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
    sudo systemctl reload caddy && echo "Caddy rechargé (vignettes)"
  else
    echo "Configuration vignettes refusée : retour à l'ancienne"; sudo cp "$CADDY.avant-apercus" "$CADDY"; exit 1
  fi
fi
sudo tee /etc/systemd/system/mirtv-apercus.service >/dev/null <<UNIT
[Unit]
Description=MirTV - vignettes des chaines
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 -B /opt/mirtv/src/mirtv-androidtv/mirtv/serveur/apercus-mirtv.py
Nice=10
TimeoutStartSec=900
UNIT
sudo tee /etc/systemd/system/mirtv-apercus.timer >/dev/null <<UNIT
[Unit]
Description=MirTV - vignettes des chaines toutes les 20 minutes
[Timer]
OnBootSec=3min
OnUnitActiveSec=20min
[Install]
WantedBy=timers.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now mirtv-apercus.timer >/dev/null
echo "Minuteur mirtv-apercus.timer actif (toutes les 20 minutes)"
