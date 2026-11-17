#!/usr/bin/env bash
# MirTV : installe (ou met à jour) Paymenter sur https://compte.mirtv.rahiss.com. Relançable sans danger.
# Usage (compte basania) : bash /opt/mirtv/src/mirtv-androidtv/mirtv/paymenter/installer-paymenter.sh
# - secrets générés sans jamais les afficher (/etc/mirtv/secrets/paiement, root, 600)
# - conteneurs mirtv-paymenter-* (Paymenter, MariaDB, Redis), seulement sur 127.0.0.1:8092
# - Caddy : ajoute le site compte.mirtv.rahiss.com (une seule fois, avec sauvegarde)
# - réglages MirTV (ariary, français, formules TEST, Orange Money en SIMULATION)
# Ne touche à aucun autre conteneur (TADY, Nawili…).
set -euo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
DEST=/opt/mirtv/paymenter
SEC=/etc/mirtv/secrets/paiement
CADDY=/etc/caddy/sites.d/mirtv.caddy
log() { echo "[paymenter] $*"; }

# 1. Dossiers
sudo mkdir -p "$DEST"/{database,storage/logs,storage/public,themes,extensions/Gateways,extensions/Servers,mirtv,partage}
sudo chown -R basania:basania "$DEST/mirtv" "$DEST/partage"
sudo chmod 777 "$DEST/partage"   # écrit par l'appli (limite d'écrans par famille), lu par mirtv-garde

# 2. Secrets (générés une fois, jamais affichés)
sudo mkdir -p "$SEC" && sudo chmod 700 "$SEC"
gen() { sudo test -s "$SEC/$1" || sudo sh -c "umask 077; openssl rand -base64 48 | tr -dc 'A-Za-z0-9' | head -c ${2:-32} > '$SEC/$1'"; }
gen paymenter_db_password 32
gen paymenter_db_root_password 32
gen paymenter_app_key 32
gen paymenter_admin_password 20
# Clé API Jellyfin : copie pour l'extension comptes (même valeur, jamais affichée)
sudo test -s "$SEC/jellyfin_api_key" || sudo sh -c "umask 077; cp /etc/mirtv/secrets/jellyfin_api_key '$SEC/jellyfin_api_key'"
sudo chmod 600 "$SEC"/*
log "secrets prêts : $(sudo ls "$SEC" | tr '\n' ' ')"

# 3. Réseau de Jellyfin (pour que l'extension comptes lui parle)
JF=$(docker ps --format '{{.Names}} {{.Image}}' | awk 'tolower($2) ~ /jellyfin/ {print $1; exit}')
[ -n "$JF" ] || { log "conteneur Jellyfin introuvable"; exit 1; }
RES=$(docker inspect -f '{{range $k, $v := .NetworkSettings.Networks}}{{$k}} {{end}}' "$JF" | awk '{print $1}')
log "Jellyfin : conteneur $JF, réseau $RES"

# 4. Fichiers MirTV
sed -e "s/__JELLYFIN__/$JF/" -e "s/__RESEAU_JELLYFIN__/$RES/" "$ICI/docker-compose.yml.modele" | sudo tee "$DEST/docker-compose.yml" >/dev/null
cp "$ICI/mirtv-entree.sh" "$ICI/configurer.php" "$ICI/creer-admin.php" "$ICI/mode-orange.php" "$DEST/mirtv/"
sudo rm -rf "$DEST/extensions/Gateways/OrangeMoney" "$DEST/extensions/Servers/MirTV"
sudo cp -r "$ICI/extensions/Gateways/OrangeMoney" "$DEST/extensions/Gateways/"
sudo cp -r "$ICI/extensions/Servers/MirTV" "$DEST/extensions/Servers/"

# 5. Démarrage
cd "$DEST"
docker compose pull -q
docker compose up -d
log "attente du démarrage (migrations de la base)…"
for i in $(seq 1 90); do
  if curl -fsS -o /dev/null -m 5 http://127.0.0.1:8092/ 2>/dev/null; then break; fi
  sleep 5
done
curl -fsS -o /dev/null -m 5 http://127.0.0.1:8092/ && log "Paymenter répond" || { log "Paymenter ne répond pas : docker compose -f $DEST/docker-compose.yml logs paymenter"; exit 1; }

# 6. Réglages MirTV et administrateur
docker compose exec -T paymenter php artisan tinker --execute="require '/app/mirtv/configurer.php';"
docker compose exec -T paymenter php artisan tinker --execute="require '/app/mirtv/creer-admin.php';"
docker compose exec -T paymenter php artisan optimize:clear >/dev/null

# 6 bis. Garde des écrans : limite propre à chaque famille payante
for g in /opt/mirtv/garde/garde.py "$HOME/mirtv/scripts/mirtv-garde/garde.py"; do
  [ -f "$g" ] && sudo python3 "$ICI/garde-limites.py" "$g" || true
done

# 7. Caddy : site compte.mirtv.rahiss.com (une seule fois)
if ! sudo grep -q "compte-mirtv" "$CADDY"; then
  sudo cp "$CADDY" "$CADDY.avant-compte"
  sudo tee -a "$CADDY" >/dev/null <<'BLOC'

# compte-mirtv : Paymenter (factures, paiements) — mirtv/paymenter/installer-paymenter.sh
compte.mirtv.rahiss.com {
	encode zstd gzip
	header {
		Strict-Transport-Security "max-age=31536000"
		X-Content-Type-Options nosniff
		Referrer-Policy strict-origin-when-cross-origin
		-Server
	}
	request_body {
		max_size 10MB
	}
	reverse_proxy 127.0.0.1:8092
}
BLOC
  if sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1; then
    sudo systemctl reload caddy && log "Caddy rechargé (compte.mirtv.rahiss.com)"
  else
    log "configuration Caddy refusée : retour à l'ancienne"; sudo cp "$CADDY.avant-compte" "$CADDY"; exit 1
  fi
fi
sleep 3
curl -s -o /dev/null -w "[paymenter] contrôle : https://compte.mirtv.rahiss.com répond %{http_code}\n" https://compte.mirtv.rahiss.com/ || true
log "fini. Administration : https://compte.mirtv.rahiss.com/admin  (identifiant : admin@mirtv.rahiss.com)"
log "mot de passe : sudo cat $SEC/paymenter_admin_password   (à lancer par Ravi)"
