#!/usr/bin/env bash
# MirTV : famille de TEST (mode simulation, aucun vrai paiement). Voir famille-test.php.
# Usage (compte basania) : bash famille-test.sh creer | etat | pause | supprimer
set -euo pipefail
ICI="$(cd "$(dirname "$0")" && pwd)"
DEST=/opt/mirtv/paymenter
cp "$ICI/famille-test.php" "$DEST/mirtv/"
cd "$DEST"
docker compose exec -T -e MIRTV_TEST="${1:-etat}" paymenter php artisan tinker --execute="require '/app/mirtv/famille-test.php';"
