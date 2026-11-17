#!/bin/ash
# MirTV : démarrage du conteneur Paymenter.
# Les secrets (montés en lecture seule depuis /etc/mirtv/secrets/paiement du serveur) sont copiés dans
# /run/mirtv, lisibles seulement par l'utilisateur de l'appli (nginx). Rien n'est affiché.
set -e
umask 077
mkdir -p /run/mirtv
if [ -d /run/secrets-mirtv ]; then
  for f in /run/secrets-mirtv/*; do
    [ -f "$f" ] || continue
    cp "$f" "/run/mirtv/$(basename "$f")"
  done
fi
chown -R nginx:nginx /run/mirtv
chmod 500 /run/mirtv
chmod 400 /run/mirtv/* 2>/dev/null || true
# Mot de passe de la base et clé de l'appli : depuis les fichiers, jamais écrits dans le compose
export DB_PASSWORD="$(cat /run/secrets-mirtv/paymenter_db_password)"
export APP_KEY="$(cat /run/secrets-mirtv/paymenter_app_key)"
# Le fichier .env (dossier temporaire en mémoire, jamais sur le disque du serveur) est refait à chaque
# démarrage avec la clé et le mot de passe de la base : ainsi les tâches planifiées (cron) et les
# commandes « docker compose exec … php artisan » y ont accès aussi.
mkdir -p /app/var
printf 'APP_KEY=%s\nDB_PASSWORD=%s\n' "$APP_KEY" "$DB_PASSWORD" > /app/var/.env
chown root:nginx /app/var/.env
chmod 640 /app/var/.env
# Droits normaux pour la suite (sinon les fichiers de cache créés par root seraient illisibles par nginx)
umask 022
rm -f /app/bootstrap/cache/*.php
# PHP-FPM garde l'environnement (clear_env = no) : DB_PASSWORD et APP_KEY arrivent jusqu'à l'appli
exec /bin/ash /app/.github/docker/entrypoint.sh "$@"
