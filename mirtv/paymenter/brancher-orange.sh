#!/usr/bin/env bash
# MirTV : brancher le compte marchand Orange Money (à lancer par Ravi, une seule fois).
#   sudo bash /opt/mirtv/src/mirtv-androidtv/mirtv/paymenter/brancher-orange.sh
# 1. colle les 3 clés données par Orange (rien ne s'affiche)  2. choisis le mode  3. le script teste les clés.
set -euo pipefail
[[ $EUID -eq 0 ]] || { echo "À lancer avec sudo."; exit 1; }
SEC=/etc/mirtv/secrets/paiement
DEST=/opt/mirtv/paymenter
install -d -m 700 -o root -g root "$SEC"
echo "Clés Orange Money (portail Orange Developer et compte marchand). Rien ne s'affiche quand vous collez."
for nom in orange_client_id orange_client_secret orange_merchant_key; do
  libelle=$nom
  case $nom in
    orange_client_id) libelle="Client ID (identifiant de l'application)";;
    orange_client_secret) libelle="Client Secret (secret de l'application)";;
    orange_merchant_key) libelle="Merchant Key (clé marchand)";;
  esac
  if [[ -s "$SEC/$nom" ]]; then
    read -rp "« $libelle » est déjà enregistré. Le remplacer ? (oui/non) " r
    [[ "$r" == "oui" ]] || continue
  fi
  read -rsp "Collez $libelle puis Entrée : " v; echo
  [[ -n "$v" ]] || { echo "Valeur vide : rien n'est enregistré pour $nom."; continue; }
  ( umask 077; printf '%s' "$v" > "$SEC/.$nom.tmp" ); mv -f "$SEC/.$nom.tmp" "$SEC/$nom"; chmod 600 "$SEC/$nom"; unset v
  echo "  ✓ $nom enregistré"
done
echo
echo "Quel mode ?"
echo "  1 = sandbox     (test chez Orange, monnaie OUV, pas de vrai argent)"
echo "  2 = production  (VRAI argent, seulement quand le compte marchand est ouvert)"
echo "  3 = simulation  (aucun appel à Orange)"
read -rp "Votre choix (1, 2 ou 3) : " c
case $c in
  1) mode=sandbox;;
  2) read -rp "Écrivez PRODUCTION pour confirmer les vrais paiements : " ok; [[ "$ok" == "PRODUCTION" ]] || { echo "Annulé."; exit 1; }; mode=production;;
  3) mode=simulation;;
  *) echo "Choix inconnu : rien n'a changé."; exit 1;;
esac
read -rp "Code pays Orange (Entrée = mg, à confirmer par Orange Madagascar) : " pays
cd "$DEST"
docker compose restart paymenter >/dev/null    # recopie les clés dans le conteneur
sleep 8
docker compose exec -T -e MIRTV_MODE="$mode" -e MIRTV_PAYS="${pays:-}" paymenter php artisan tinker --execute="require '/app/mirtv/mode-orange.php';"
echo "Fini. Pour revenir en simulation : relancez ce script et choisissez 3."
