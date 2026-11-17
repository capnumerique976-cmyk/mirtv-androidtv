#!/usr/bin/env bash
# MirTV : installe la commande « mirtv-etat » et son contrôle toutes les 5 minutes. Sans danger : relançable.
# - sudo mirtv-etat : résumé lisible de l'état du service ;
# - le minuteur envoie le résultat à Uptime Kuma (déjà présent sur le VPS : https://uptime.rahiss.com)
#   dès que Ravi a rangé l'adresse de la sonde « Push » : sudo bash ~/mirtv/scripts/saisir-secret.sh kuma_push_url
# - vnstat (paquet officiel Ubuntu) mesure le transfert du mois (quota Hostinger 16 To).
set -euo pipefail
ICI=$(cd "$(dirname "$0")" && pwd)

if ! command -v vnstat >/dev/null; then
  echo "Installation de vnstat (mesure du transfert du mois)…"
  sudo apt-get install -y -qq vnstat >/dev/null && sudo systemctl enable --now vnstat >/dev/null 2>&1 || echo "vnstat non installé : le transfert ne sera pas mesuré."
fi
sudo ln -sf "$ICI/mirtv-etat.py" /usr/local/bin/mirtv-etat
sudo chmod 755 "$ICI/mirtv-etat.py"

sudo tee /etc/systemd/system/mirtv-etat.service >/dev/null <<UNIT
[Unit]
Description=MirTV - controle de l'etat du service (envoi a Uptime Kuma)
After=network-online.target
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 $ICI/mirtv-etat.py --pousser
SuccessExitStatus=1 2
UNIT
sudo tee /etc/systemd/system/mirtv-etat.timer >/dev/null <<'UNIT'
[Unit]
Description=MirTV - controle de l'etat toutes les 5 minutes
[Timer]
OnBootSec=3min
OnUnitActiveSec=5min
[Install]
WantedBy=timers.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now mirtv-etat.timer >/dev/null
echo "Commande installée : sudo mirtv-etat"
if sudo test -s /etc/mirtv/secrets/kuma_push_url; then
  echo "Adresse Uptime Kuma : présente (envoi toutes les 5 minutes)."
else
  echo "Adresse Uptime Kuma : pas encore rangée → voir aide/00-surveillance.md"
fi
