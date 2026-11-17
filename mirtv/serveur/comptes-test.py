#!/usr/bin/env python3
"""MirTV — crée ou met à jour des comptes de TEST à partir d'une liste collée.

Usage (sur le serveur) :  sudo python3 comptes-test.py
puis collez les lignes de votre fichier, par exemple :

    Famille 901
     parent1    MotDePasse
     irchad     MotDePasse
    Famille 902
     parent2    MotDePasse

et appuyez deux fois sur Entrée.

- Un nom qui commence par « parent » = profil adulte ; les autres = profils enfants.
- Les droits sont copiés sur les comptes modèles f901-parent (adulte) et f901-lova (enfant) :
  bibliothèques, plage horaire des enfants (7 h - 19 h 30), etc.
- Un compte qui existe déjà garde ses droits et reçoit seulement le nouveau mot de passe.
- Le bloc « Utilisateur : mirtv-admin / Mot de passe : … » change aussi le mot de passe de l'administrateur
  (et la copie gardée dans /etc/mirtv/secrets/jellyfin_admin_password pour les outils du serveur).
- La famille de chaque compte est notée dans /opt/mirtv/registre/familles.json (limite d'écrans).
- Les mots de passe ne sont jamais affichés ; ils sont rangés dans /etc/mirtv/secrets/test/<nom>.
"""
import json, os, re, sys, unicodedata, urllib.error, urllib.request

BASE = os.environ.get("MIRTV_JELLYFIN_URL", "http://127.0.0.1:8096")
SECRETS = "/etc/mirtv/secrets"
FAMILLES = "/opt/mirtv/registre/familles.json"
MODELES = {"adulte": "f901-parent", "enfant": "f901-lova"}


def cle_api():
    with open(os.path.join(SECRETS, "jellyfin_api_key"), encoding="utf-8") as f:
        return f.read().strip()


def appel(methode, chemin, corps=None):
    donnees = json.dumps(corps).encode() if corps is not None else None
    req = urllib.request.Request(BASE + chemin, data=donnees, method=methode, headers={
        "Authorization": f'MediaBrowser Client="MirTV-outils", Device="vps2", DeviceId="mirtv-comptes", Version="1.0", Token="{CLE}"',
        "Content-Type": "application/json", "Accept": "application/json"})
    try:
        with urllib.request.urlopen(req, timeout=30) as r:
            brut = r.read()
            return json.loads(brut) if brut else None
    except urllib.error.HTTPError as e:
        raise RuntimeError(f"le serveur répond {e.code} sur {methode} {chemin.split('?')[0]}") from None


def nettoyer(s):
    s = unicodedata.normalize("NFKC", s).replace("\\par", "")
    return re.sub(r"[​-‍⁠﻿­]", "", s).strip()


def lire_liste():
    print("Collez les lignes de votre fichier (tout le fichier convient), puis appuyez deux fois sur Entrée :")
    comptes, famille, vides, admin = [], None, 0, None
    for ligne in sys.stdin:
        ligne = nettoyer(ligne)
        if not ligne:
            vides += 1
            if vides >= 2:
                break
            continue
        vides = 0
        m = re.match(r"^famille\s+(\d+)", ligne, re.I)
        if m:
            famille = m.group(1)
            continue
        if ligne.startswith("==="):
            famille = None
            continue
        m = re.match(r"^utilisateur\s*:\s*(\S+)$", ligne, re.I)
        if m:
            admin = m.group(1)
            continue
        m = re.match(r"^mot de passe\s*:\s*(\S+)$", ligne, re.I)
        if m and admin:  # bloc « Utilisateur : mirtv-admin / Mot de passe : … »
            comptes.append((admin.lower(), m.group(1), None))
            admin = None
            continue
        morceaux = ligne.split()
        if famille and len(morceaux) == 2 and ":" not in morceaux[0]:
            comptes.append((morceaux[0].lower(), morceaux[1], famille))
    return comptes


def ecrire_secret(nom, valeur):
    dossier = os.path.join(SECRETS, "test")
    os.makedirs(dossier, mode=0o700, exist_ok=True)
    chemin = os.path.join(dossier, nom)
    ancien = os.umask(0o077)
    try:
        with open(chemin, "w", encoding="utf-8") as f:
            f.write(valeur + "\n")
    finally:
        os.umask(ancien)
    os.chmod(chemin, 0o600)


def main():
    if os.geteuid() != 0:
        print("À lancer avec sudo.", file=sys.stderr)
        return 1
    global CLE
    CLE = cle_api()
    comptes = lire_liste()
    if not comptes:
        print("Aucun compte reconnu dans le texte collé.")
        return 1
    utilisateurs = {u["Name"].lower(): u for u in appel("GET", "/Users")}
    modeles = {}
    for type_profil, nom in MODELES.items():
        if nom not in utilisateurs:
            print(f"Compte modèle {nom} introuvable : impossible de copier les droits {type_profil}.")
            return 1
        modeles[type_profil] = appel("GET", f"/Users/{utilisateurs[nom]['Id']}")["Policy"]

    familles = {}
    if os.path.exists(FAMILLES):
        with open(FAMILLES, encoding="utf-8") as f:
            familles = json.load(f)

    vus, erreurs = set(), 0
    for nom, mdp, famille in comptes:
        if nom in vus:
            print(f"  {nom} : en double dans la liste, deuxième ligne ignorée")
            continue
        vus.add(nom)
        try:
            u = utilisateurs.get(nom)
            if u:
                appel("POST", f"/Users/{u['Id']}/Password", {"NewPw": mdp, "ResetPassword": False})
                print(f"  {nom} : existait déjà, mot de passe changé (droits inchangés)")
            else:
                if not famille:
                    print(f"  {nom} : pas de ligne « Famille … » avant ce nom, compte non créé")
                    erreurs += 1
                    continue
                type_profil = "adulte" if nom.startswith("parent") else "enfant"
                u = appel("POST", "/Users/New", {"Name": nom, "Password": mdp})
                politique = dict(modeles[type_profil])
                politique["AccessSchedules"] = [dict(p, UserId=u["Id"]) for p in politique.get("AccessSchedules", [])]
                politique["IsAdministrator"] = False
                politique["IsDisabled"] = False
                appel("POST", f"/Users/{u['Id']}/Policy", politique)
                print(f"  {nom} : créé ({type_profil}, famille {famille})")
            if famille:
                familles[nom] = famille
            if nom == "mirtv-admin":  # les outils du serveur se connectent avec ce mot de passe
                ancien = os.umask(0o077)
                try:
                    with open(os.path.join(SECRETS, "jellyfin_admin_password"), "w", encoding="utf-8") as f:
                        f.write(mdp + "\n")
                finally:
                    os.umask(ancien)
            else:
                ecrire_secret(nom, mdp)
        except RuntimeError as e:
            print(f"  {nom} : échec ({e})")
            erreurs += 1

    os.makedirs(os.path.dirname(FAMILLES), exist_ok=True)
    with open(FAMILLES, "w", encoding="utf-8") as f:
        json.dump(familles, f, ensure_ascii=False, indent=1, sort_keys=True)
    os.chmod(FAMILLES, 0o644)
    print(f"Terminé : {len(vus) - erreurs} compte(s) prêts, {erreurs} problème(s). Familles notées dans {FAMILLES}.")
    return 1 if erreurs else 0


if __name__ == "__main__":
    sys.exit(main())
