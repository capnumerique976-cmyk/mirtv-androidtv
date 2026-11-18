#!/usr/bin/env bash
# MirTV : programme compact pour l'appli web (guide-court.py toutes les 15 min) + réglages de cache de Caddy.
# Sans danger : relançable. Caddy : copie de sauvegarde, vérification, retour en arrière automatique si refus.
set -euo pipefail
ICI=$(cd "$(dirname "$0")" && pwd)
CADDY=/etc/caddy/sites.d/mirtv.caddy
sudo mkdir -p /opt/mirtv/images-web/guide

sudo tee /etc/systemd/system/mirtv-guide-court.service >/dev/null <<UNIT
[Unit]
Description=MirTV - programme compact pour l'appli web
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 $ICI/guide-court.py
UNIT
sudo tee /etc/systemd/system/mirtv-guide-court.timer >/dev/null <<'UNIT'
[Unit]
Description=MirTV - programme compact toutes les 15 minutes
[Timer]
OnBootSec=2min
OnUnitActiveSec=15min
[Install]
WantedBy=timers.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now mirtv-guide-court.timer >/dev/null
sudo systemctl start mirtv-guide-court.service

if ! sudo grep -q "guide-court-mirtv" "$CADDY"; then
  sudo cp "$CADDY" "$CADDY.avant-perf"
  sudo python3 - "$CADDY" <<'PY'
import sys
p = sys.argv[1]
s = open(p).read()
repere = "\thandle_path /tv/* {"
if repere not in s:
    repere = "        handle_path /tv/* {"
bloc = """# guide-court-mirtv : programme compact (mirtv/serveur/guide-court.py)
\thandle_path /tv/guide/* {
\t\troot * /opt/mirtv/images-web/guide
\t\theader Cache-Control "public, max-age=300"
\t\tfile_server
\t}
"""
s = s.replace(repere, bloc + repere, 1)
# fichiers qui ne changent pas entre deux versions : gardés 7 jours par le navigateur
ancien = '@frais path / /index.html /sw.js /js/* /css/*'
if ancien in s and "@durable" not in s:
    s = s.replace(ancien, ancien + '\n\t\t@durable path /vendor/* /fonts/* /icons/*\n\t\theader @durable Cache-Control "public, max-age=604800"', 1)
open(p, "w").write(s)
PY
  if sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
    sudo systemctl reload caddy && echo "Caddy : programme compact et cache durable en place"
  else
    sudo cp "$CADDY.avant-perf" "$CADDY"; echo "Caddy a refusé : ancienne version remise"; exit 1
  fi
fi
code=$(curl -s -o /dev/null -w '%{http_code}' https://mirtv.rahiss.com/tv/guide/programmes.json)
echo "https://mirtv.rahiss.com/tv/guide/programmes.json : $code"
