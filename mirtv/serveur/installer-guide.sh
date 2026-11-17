#!/usr/bin/env bash
# MirTV : installe le guide des programmes (sources gratuites) et le branche dans Jellyfin. Sans danger : relançable.
set -euo pipefail
ICI=$(cd "$(dirname "$0")" && pwd)
sudo python3 "$ICI/guide-mirtv.py"
sudo tee /etc/systemd/system/mirtv-guide.service >/dev/null <<UNIT
[Unit]
Description=MirTV - guide des programmes (sources gratuites)
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 $ICI/guide-mirtv.py
UNIT
sudo tee /etc/systemd/system/mirtv-guide.timer >/dev/null <<'UNIT'
[Unit]
Description=MirTV - guide des programmes toutes les 3 heures
[Timer]
OnCalendar=*-*-* 00/3:10:00
Persistent=true
[Install]
WantedBy=timers.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now mirtv-guide.timer >/dev/null
echo "Minuteur : $(systemctl list-timers mirtv-guide.timer --no-legend | awk '{print $1, $2}')"
# Le script de branchement des chaînes doit aussi viser le nouveau guide s'il est relancé
sed -i 's|^XMLTV = "http://dispatcharr:9191/output/epg"|XMLTV = "/config/guide/mirtv-guide.xml"  # fabriqué par mirtv/serveur/guide-mirtv.py|' /home/basania/mirtv/scripts/mirtv-admin/jellyfin_chaines.py
sudo python3 "$ICI/brancher-guide.py"
# Récupérateur de programmes sur les sites (iptv-org/epg), chaque nuit
bash "$ICI/installer-guide-iptv-org.sh"
