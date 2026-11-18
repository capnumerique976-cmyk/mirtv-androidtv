#!/usr/bin/env bash
# MirTV : ajoute de la mémoire d'échange (swap) au VPS 2 pour qu'il ralentisse moins quand la mémoire vive est pleine.
# Ajoute un fichier /swapfile-mirtv pour arriver à 8 Go de swap en tout (rien si c'est déjà le cas). Relançable.
# Retour arrière : sudo swapoff /swapfile-mirtv && sudo rm /swapfile-mirtv && sudo sed -i '\|/swapfile-mirtv|d' /etc/fstab
set -euo pipefail
CIBLE_GO=${1:-8}
FICHIER=/swapfile-mirtv

echo "Avant :"; free -h | sed -n '1,3p'
actuel_mo=$(swapon --show=SIZE,NAME --bytes --noheadings | awk -v f="$FICHIER" '$2!=f {s+=$1} END {print int(s/1048576)}')
deja_mo=0
[ -f "$FICHIER" ] && deja_mo=$(( $(stat -c %s "$FICHIER") / 1048576 ))
ajout_mo=$(( CIBLE_GO * 1024 - actuel_mo ))
if [ "$ajout_mo" -le 512 ]; then
  echo "Déjà $(( actuel_mo / 1024 )) Go de swap sans $FICHIER : rien à ajouter."
elif [ "$deja_mo" -ge "$ajout_mo" ]; then
  echo "$FICHIER existe déjà ($deja_mo Mo)."
  swapon --show=NAME --noheadings | grep -qx "$FICHIER" || sudo swapon "$FICHIER"
else
  [ -f "$FICHIER" ] && { sudo swapoff "$FICHIER" 2>/dev/null || true; sudo rm -f "$FICHIER"; }
  echo "Création de $FICHIER : $ajout_mo Mo…"
  sudo fallocate -l "${ajout_mo}M" "$FICHIER"
  sudo chmod 600 "$FICHIER"
  sudo mkswap -q "$FICHIER"
  sudo swapon "$FICHIER"
fi
grep -q "^$FICHIER " /etc/fstab || echo "$FICHIER none swap sw 0 0" | sudo tee -a /etc/fstab >/dev/null
echo; echo "Après :"; free -h | sed -n '1,3p'
echo; echo "Ce qui prend le plus de mémoire :"
ps -eo rss,comm --sort=-rss | head -9 | awk 'NR==1 {print "  Mo    programme"; next} {printf "  %-5d %s\n", $1/1024, $2}'
