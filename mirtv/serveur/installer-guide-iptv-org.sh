#!/usr/bin/env bash
# MirTV : installe le « récupérateur » de programmes iptv-org/epg et son minuteur de nuit. Sans danger : relançable.
# Il cherche chaque nuit les programmes des chaînes qui n'en ont pas dans les guides tout faits
# (liste écrite par guide-mirtv.py), puis relance guide-mirtv.py pour mettre le guide de Jellyfin à jour.
set -euo pipefail
ICI=$(cd "$(dirname "$0")" && pwd)
OUTIL=/opt/mirtv/epg-iptv-org
DOSSIER=/opt/mirtv/guide
MOI=${SUDO_USER:-$(id -un)}

sudo mkdir -p "$DOSSIER"
sudo chown "$MOI:$MOI" "$DOSSIER"
if [ ! -d "$OUTIL/.git" ]; then
  sudo mkdir -p "$OUTIL" && sudo chown "$MOI:$MOI" "$OUTIL"
  git clone -q --depth 1 https://github.com/iptv-org/epg.git "$OUTIL"
else
  git -C "$OUTIL" pull -q --ff-only || true
fi
echo "Installation des modules de l'outil (quelques minutes la première fois)…"
(cd "$OUTIL" && npm ci --no-audit --no-fund --loglevel=error >/tmp/mirtv-epg-npm.log 2>&1) \
  || { echo "Échec de l'installation : voir /tmp/mirtv-epg-npm.log"; exit 1; }
echo "Outil iptv-org/epg prêt : $(ls -d "$OUTIL"/sites/*/ | wc -l) sites connus"

sudo tee /etc/systemd/system/mirtv-guide-iptv-org.service >/dev/null <<UNIT
[Unit]
Description=MirTV - programmes lus sur les sites des chaines (iptv-org/epg)
After=network-online.target
[Service]
Type=oneshot
User=$MOI
TimeoutStartSec=3h
Nice=10
ExecStartPre=-/bin/sh -c 'cd $OUTIL && git pull -q --ff-only && (git diff --quiet HEAD@{1} HEAD -- package-lock.json 2>/dev/null || npm ci --no-audit --no-fund --loglevel=error)'
ExecStart=/usr/bin/python3 $ICI/guide-iptv-org.py
ExecStartPost=+/usr/bin/python3 $ICI/guide-mirtv.py
UNIT
sudo tee /etc/systemd/system/mirtv-guide-iptv-org.timer >/dev/null <<'UNIT'
[Unit]
Description=MirTV - programmes lus sur les sites, chaque nuit
[Timer]
OnCalendar=*-*-* 00:40:00
Persistent=true
[Install]
WantedBy=timers.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now mirtv-guide-iptv-org.timer >/dev/null
echo "Minuteur : $(systemctl list-timers mirtv-guide-iptv-org.timer --no-legend | awk '{print $1, $2, $3}') (UTC)"
echo "Premier passage maintenant ? sudo systemctl start --no-block mirtv-guide-iptv-org.service"
echo "Suivi : tail -f $DOSSIER/iptv-org/passe1.log ; bilan : cat $DOSSIER/rapport-iptv-org.txt"
