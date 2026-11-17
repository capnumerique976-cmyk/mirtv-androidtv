#!/usr/bin/env python3
"""MirTV — Jellyfin lit le guide fabriqué par guide-mirtv.py au lieu du guide vide de Dispatcharr,
puis actualise le guide tout de suite. Lancement : sudo python3 brancher-guide.py
(utilise synchro.py du back office, qui lit la clé API dans /etc/mirtv/secrets/ sans l'afficher)."""
import sys, time
sys.path.insert(0, "/home/basania/mirtv/scripts/mirtv-admin")
import synchro  # noqa: E402

ANCIEN = "http://dispatcharr:9191/output/epg"
NOUVEAU = "/config/guide/mirtv-guide.xml"

j = synchro.Jellyfin()
conf = j.appel("GET", "/System/Configuration/livetv") or {}
fournisseurs = conf.get("ListingProviders") or []
deja = next((p for p in fournisseurs if p.get("Path") == NOUVEAU), None)
ancien = next((p for p in fournisseurs if p.get("Path") == ANCIEN), None)
corps = {"Type": "xmltv", "Path": NOUVEAU, "EnableAllTuners": True, "EnabledTuners": []}
cible = deja or ancien
if cible:
    corps["Id"] = cible["Id"]
j.appel("POST", "/LiveTv/ListingProviders", corps, params={"validateListings": "false", "validateLogin": "false"})
print("Guide de Jellyfin :", NOUVEAU, "(remplace celui de Dispatcharr)" if ancien and not deja else "")
tache = j.tache("RefreshGuide")
if tache:
    j.appel("POST", f"/ScheduledTasks/Running/{tache['Id']}")
    print("Actualisation du guide lancée")
