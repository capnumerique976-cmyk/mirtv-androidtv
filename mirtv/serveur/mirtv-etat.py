#!/usr/bin/env python3
"""MirTV — état du service en un coup d'œil (en français simple).

  sudo mirtv-etat            résumé lisible : ce qui marche, ce qui ne marche pas
  sudo mirtv-etat --pousser  même contrôle, sans affichage, puis envoie le résultat à Uptime Kuma
                             (adresse « Push » rangée par Ravi dans /etc/mirtv/secrets/kuma_push_url)
                             → lancé toutes les 5 minutes par mirtv-etat.timer
  sudo mirtv-etat --json     résultat brut (pour d'autres outils)

Contrôles (docs/07-exploitation.md) :
  appli web, portail des comptes, serveur télé (santé), gestion des chaînes, place disque (alerte à 80 %),
  certificats HTTPS (alerte 14 jours avant la fin), sauvegarde de la nuit (moins de 30 h),
  transfert du mois (alerte à 70 % de 16 To, si vnstat est installé), tâches automatiques MirTV,
  familles et écrans en cours (pour information).
Code de sortie : 0 si tout va bien, 1 s'il y a un problème grave, 2 s'il n'y a que des avertissements.
Aucun secret n'est affiché.
"""
import datetime, glob, json, os, shutil, socket, ssl, subprocess, sys, time, urllib.error, urllib.parse, urllib.request

SITE = os.environ.get("MIRTV_SITE", "mirtv.rahiss.com")
COMPTE = os.environ.get("MIRTV_COMPTE", "compte.mirtv.rahiss.com")
JELLYFIN = os.environ.get("MIRTV_JELLYFIN_URL", "http://127.0.0.1:8096")
DISPATCHARR = os.environ.get("MIRTV_DISPATCHARR", "http://127.0.0.1:9191")
SAUVEGARDES = os.environ.get("MIRTV_SAUVEGARDES", "/opt/mirtv/sauvegardes/quotidien")
PUSH = os.environ.get("MIRTV_KUMA_PUSH", "/etc/mirtv/secrets/kuma_push_url")
QUOTA_OCTETS = 16 * 10**12          # 16 To par mois (offre Hostinger)
DISQUE_ALERTE = 80                  # %
CERT_JOURS = 14
SAUVEGARDE_HEURES = 30
TACHES = ["mirtv-garde", "mirtv-sauvegarde", "mirtv-chaines", "mirtv-etiquettes", "mirtv-guide",
          "mirtv-guide-iptv-org", "mirtv-apercus"]

OK, ATTENTION, GRAVE = "ok", "attention", "grave"
resultats = []  # (niveau, sujet, détail)


def noter(niveau, sujet, detail):
    resultats.append((niveau, sujet, detail))


def http(url, delai=10):
    req = urllib.request.Request(url, headers={"User-Agent": "MirTV-etat/1.0"})
    try:
        with urllib.request.urlopen(req, timeout=delai) as r:
            return r.status, r.read(2000).decode("utf-8", "replace")
    except urllib.error.HTTPError as e:
        return e.code, ""
    except Exception as e:  # pas de réponse du tout
        return 0, type(e).__name__


def controle_web():
    for nom, url, attendus in [
        ("Appli MirTV", f"https://{SITE}/tv/", {200}),
        ("Portail des comptes", f"https://{COMPTE}/", {200, 302}),
    ]:
        code, _ = http(url)
        if code in attendus:
            noter(OK, nom, "répond")
        else:
            noter(GRAVE, nom, f"ne répond pas ({code or 'aucune réponse'})")
    code, texte = http(JELLYFIN + "/health")
    if code == 200 and "Healthy" in texte:
        noter(OK, "Serveur télé", "en bonne santé")
    else:
        noter(GRAVE, "Serveur télé", f"ne va pas bien ({code or 'aucune réponse'})")
    code, _ = http(DISPATCHARR + "/")
    if code and code < 500:
        noter(OK, "Gestion des chaînes", "répond")
    else:
        noter(GRAVE, "Gestion des chaînes", f"ne répond pas ({code or 'aucune réponse'})")


def controle_disque():
    total, utilise, libre = shutil.disk_usage("/")
    pc = round(utilise * 100 / total)
    detail = f"{pc} % utilisé, {libre // 10**9} Go libres"
    noter(GRAVE if pc >= 90 else ATTENTION if pc >= DISQUE_ALERTE else OK, "Place sur le disque", detail)


def jours_certificat(hote):
    ctx = ssl.create_default_context()
    with socket.create_connection((hote, 443), timeout=10) as s:
        with ctx.wrap_socket(s, server_hostname=hote) as t:
            fin = ssl.cert_time_to_seconds(t.getpeercert()["notAfter"])
    return int((fin - time.time()) // 86400)


def controle_certificats():
    for hote in (SITE, COMPTE):
        try:
            j = jours_certificat(hote)
            noter(GRAVE if j < 3 else ATTENTION if j < CERT_JOURS else OK, f"Cadenas HTTPS {hote}", f"valable encore {j} jours")
        except Exception as e:
            noter(GRAVE, f"Cadenas HTTPS {hote}", f"illisible ({type(e).__name__})")


def controle_sauvegarde():
    fichiers = [f for f in glob.glob(os.path.join(SAUVEGARDES, "*")) if os.path.isfile(f)]
    if not fichiers:
        noter(GRAVE, "Sauvegarde de la nuit", "aucune sauvegarde trouvée")
        return
    dernier = max(fichiers, key=os.path.getmtime)
    age = (time.time() - os.path.getmtime(dernier)) / 3600
    octets = os.path.getsize(dernier)
    quand = datetime.datetime.fromtimestamp(os.path.getmtime(dernier)).strftime("%d/%m à %H:%M")
    if age > SAUVEGARDE_HEURES:
        noter(GRAVE, "Sauvegarde de la nuit", f"la dernière date du {quand} (plus de {SAUVEGARDE_HEURES} h)")
    elif octets == 0:
        noter(GRAVE, "Sauvegarde de la nuit", "le dernier fichier est vide")
    else:
        noter(OK, "Sauvegarde de la nuit", f"faite le {quand} ({octets / 10**6:.1f} Mo)")


def controle_transfert():
    if not shutil.which("vnstat"):
        noter(OK, "Transfert du mois", "non mesuré (vnstat n'est pas installé)")
        return
    try:
        d = json.loads(subprocess.run(["vnstat", "--json", "m", "1"], capture_output=True, text=True, timeout=20).stdout)
        total = 0
        for interface in d.get("interfaces", []):
            for m in interface.get("traffic", {}).get("month", [])[-1:]:
                total += m.get("rx", 0) + m.get("tx", 0)
        pc = total * 100 / QUOTA_OCTETS
        detail = f"{total / 10**12:.2f} To sur 16 To ({pc:.0f} %)"
        noter(GRAVE if pc >= 90 else ATTENTION if pc >= 70 else OK, "Transfert du mois", detail)
    except Exception as e:
        noter(ATTENTION, "Transfert du mois", f"illisible ({type(e).__name__})")


def controle_taches():
    ratees = []
    for t in TACHES:
        r = subprocess.run(["systemctl", "show", f"{t}.service", "-p", "Result", "-p", "LoadState"],
                           capture_output=True, text=True)
        infos = dict(l.split("=", 1) for l in r.stdout.splitlines() if "=" in l)
        if infos.get("LoadState") == "loaded" and infos.get("Result") not in ("success", ""):
            ratees.append(t.replace("mirtv-", ""))
    if ratees:
        noter(ATTENTION, "Tâches automatiques", "dernier passage raté : " + ", ".join(ratees))
    else:
        noter(OK, "Tâches automatiques", "toutes réussies au dernier passage")


def controle_familles():
    """Pour information : comptes ouverts et écrans en train de regarder (jamais une alerte)."""
    try:
        sys.path.insert(0, "/opt/mirtv/bin")
        import mirtv_api as api
        jeton = api.cle_api()
        _, utilisateurs = api.appel("GET", "/Users", token=jeton)
        _, sessions = api.appel("GET", "/Sessions", token=jeton, params={"activeWithinSeconds": 300})
        actifs = [u for u in utilisateurs or [] if not u.get("Policy", {}).get("IsDisabled")
                  and not u.get("Policy", {}).get("IsAdministrator")]
        en_cours = [s for s in sessions or [] if s.get("NowPlayingItem")]
        noter(OK, "Familles et écrans", f"{len(actifs)} profils ouverts, {len(en_cours)} écran(s) en train de regarder")
    except Exception as e:
        noter(OK, "Familles et écrans", f"non lu ({type(e).__name__})")


def tout_controler():
    for f in (controle_web, controle_disque, controle_certificats, controle_sauvegarde, controle_transfert,
              controle_taches, controle_familles):
        try:
            f()
        except Exception as e:
            noter(ATTENTION, f.__name__.replace("controle_", "Contrôle "), f"impossible ({type(e).__name__})")
    if any(n == GRAVE for n, _, _ in resultats):
        return 1
    return 2 if any(n == ATTENTION for n, _, _ in resultats) else 0


def pousser(code):
    """Envoie « en marche » ou « en panne » à la sonde Push d'Uptime Kuma (adresse secrète, jamais affichée)."""
    if not os.path.isfile(PUSH) or os.path.getsize(PUSH) == 0:
        print("Pas d'adresse Uptime Kuma (sudo scripts/saisir-secret.sh kuma_push_url) : rien envoyé.", file=sys.stderr)
        return
    adresse = open(PUSH, encoding="utf-8").read().strip().split("?")[0]
    problemes = [f"{s} : {d}" for n, s, d in resultats if n != OK]
    message = "Tout va bien" if not problemes else " | ".join(problemes)[:250]
    statut = "down" if code == 1 else "up"
    url = adresse + "?" + urllib.parse.urlencode({"status": statut, "msg": message, "ping": ""})
    try:
        urllib.request.urlopen(urllib.request.Request(url, headers={"User-Agent": "MirTV-etat/1.0"}), timeout=15).read()
    except Exception as e:
        print(f"Envoi à Uptime Kuma impossible ({type(e).__name__}).", file=sys.stderr)


def afficher(code):
    icone = {OK: "✅", ATTENTION: "🟠", GRAVE: "🔴"}
    print(f"MirTV — état du {datetime.datetime.now():%d/%m/%Y à %H:%M}")
    print()
    for n, s, d in resultats:
        print(f"  {icone[n]} {s} : {d}")
    print()
    print({0: "Tout va bien.", 1: "Problème grave : voir les lignes 🔴 et les fiches d'aide (aide/).",
           2: "À surveiller : voir les lignes 🟠."}[code])


if __name__ == "__main__":
    code = tout_controler()
    if "--json" in sys.argv:
        print(json.dumps({"code": code, "controles": [{"niveau": n, "sujet": s, "detail": d} for n, s, d in resultats]},
                         ensure_ascii=False, indent=1))
    elif "--pousser" in sys.argv:
        pousser(code)
    else:
        afficher(code)
    sys.exit(code)
