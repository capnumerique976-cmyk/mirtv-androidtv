#!/usr/bin/env python3
"""MirTV — programme compact pour l'appli web (écran « Le programme », vignettes « en ce moment »).

Au lieu de demander 2 000 émissions à Jellyfin (4,4 Mo), l'appli lit un seul petit fichier :
  /opt/mirtv/images-web/guide/programmes.json  →  https://mirtv.rahiss.com/tv/guide/programmes.json
  {"maj": 1763450000, "c": {"<id de la chaîne Jellyfin>": [[début, fin, "titre"], ...]}}
  (début et fin en secondes depuis 1970, heure universelle ; de 1 h avant à 26 h après maintenant)
Les « programmes » que Jellyfin invente avec le nom de la chaîne (chaîne sans guide) sont retirés.

Lancé toutes les 15 minutes par mirtv-guide-court.timer (installer-guide-court.sh).
Usage : sudo python3 guide-court.py
"""
import json, os, sys, time
from datetime import datetime, timedelta, timezone

sys.path.insert(0, "/opt/mirtv/bin")
DEST = os.environ.get("MIRTV_GUIDE_WEB", "/opt/mirtv/images-web/guide")
PAGE = 2000


def lire_date(texte):
    # Jellyfin : "2026-11-18T05:30:00.0000000Z"
    t = texte.rstrip("Z").split(".")[0]
    return int(datetime.strptime(t, "%Y-%m-%dT%H:%M:%S").replace(tzinfo=timezone.utc).timestamp())


def main():
    import mirtv_api as api
    jeton = api.cle_api()
    maintenant = datetime.now(timezone.utc)
    debut = (maintenant - timedelta(hours=1)).strftime("%Y-%m-%dT%H:%M:%SZ")
    fin = (maintenant + timedelta(hours=26)).strftime("%Y-%m-%dT%H:%M:%SZ")
    _, ch = api.appel("GET", "/LiveTv/Channels?EnableImages=false&EnableUserData=false&Limit=5000", token=jeton)
    noms = {c["Id"]: (c.get("Name") or "").strip().lower() for c in (ch or {}).get("Items", [])}
    guide, index, total = {}, 0, None
    while total is None or index < total:
        _, r = api.appel("GET", f"/LiveTv/Programs?MinEndDate={debut}&MaxStartDate={fin}&SortBy=StartDate"
                                f"&EnableImages=false&EnableUserData=false&EnableTotalRecordCount=true"
                                f"&StartIndex={index}&Limit={PAGE}", token=jeton)
        items = (r or {}).get("Items", [])
        total = (r or {}).get("TotalRecordCount", 0)
        if not items:
            break
        for p in items:
            cid, titre = p.get("ChannelId"), (p.get("Name") or "").strip()
            if not cid or not titre or titre.lower() == noms.get(cid, ""):
                continue
            try:
                guide.setdefault(cid, []).append([lire_date(p["StartDate"]), lire_date(p["EndDate"]), titre])
            except (KeyError, ValueError):
                continue
        index += len(items)
    for l in guide.values():
        l.sort()
    os.makedirs(DEST, exist_ok=True)
    tmp = os.path.join(DEST, "programmes.json.tmp")
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump({"maj": int(time.time()), "c": guide}, f, ensure_ascii=False, separators=(",", ":"))
    os.chmod(tmp, 0o644)
    os.replace(tmp, os.path.join(DEST, "programmes.json"))
    n = sum(len(l) for l in guide.values())
    print(f"Programme compact : {len(guide)} chaînes, {n} émissions, "
          f"{os.path.getsize(os.path.join(DEST, 'programmes.json')) // 1024} Ko")


if __name__ == "__main__":
    main()
