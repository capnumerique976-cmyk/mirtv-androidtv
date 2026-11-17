#!/usr/bin/env bash
# MirTV : choisir un nouveau mot de passe pour admin@mirtv.rahiss.com (guichet / back office du portail).
#   sudo bash /opt/mirtv/src/mirtv-androidtv/mirtv/paymenter/changer-mot-de-passe-admin.sh
# Rien ne s'affiche quand on tape. Le mot de passe est rangé dans /etc/mirtv/secrets/paiement (root, 600).
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "À lancer avec sudo."; exit 1; }
SEC=/etc/mirtv/secrets/paiement
DEST=/opt/mirtv/paymenter
read -rsp "Nouveau mot de passe (12 caractères au moins) : " m1; echo
read -rsp "Retapez-le : " m2; echo
[[ "$m1" == "$m2" ]] || { echo "Les deux ne sont pas pareils : rien n'a changé."; exit 1; }
[[ ${#m1} -ge 12 ]] || { echo "Trop court (12 caractères au moins) : rien n'a changé."; exit 1; }
( umask 077; printf '%s' "$m1" > "$SEC/.paymenter_admin_password.tmp" )
mv -f "$SEC/.paymenter_admin_password.tmp" "$SEC/paymenter_admin_password"; chmod 600 "$SEC/paymenter_admin_password"
unset m1 m2
cd "$DEST"
cp /opt/mirtv/src/mirtv-androidtv/mirtv/paymenter/mot-de-passe-admin.php "$DEST/mirtv/"
docker compose exec -T paymenter php artisan tinker --execute="require '/app/mirtv/mot-de-passe-admin.php';"
