// MirTV — réglages de l'appli web. Modifiables sans toucher au reste du code.
window.MIRTV_CONFIG = {
  // Adresse de Jellyfin : la même que celle de la page (mirtv.rahiss.com).
  serveur: location.origin,
  // Nombre d'écrans permis par la formule (message « déjà ouvert sur N écrans »).
  limiteEcrans: 2,
  // Décalage horaire des familles par rapport à l'heure universelle (Madagascar : +3).
  // Sert à l'écran « C'est l'heure de la pause ! » avec l'heure du serveur.
  decalageHeures: 3,
  // Numéro WhatsApp de l'aide MirTV (vide = « Numéro bientôt disponible. »).
  whatsapp: "",
  // Code parent utilisé tant que le parent n'en a pas choisi un (valeur de test).
  codeParentParDefaut: "1234",
  // Secondes avant « Cette chaîne ne marche pas en ce moment. » (certaines petites chaînes mettent ~20 s)
  delaiChaineSecondes: 25,
  // Tentatives silencieuses de reconnexion pendant la lecture d'une chaîne.
  reconnexions: 3,
  // Qualité maximale des chaînes (hauteur d'image) : 720 par défaut, 480 ou 1080 dans Réglages (par écran)
  qualiteMax: 720,
  // Portail des comptes et paiements (Paymenter). Tant qu'il n'est pas installé, l'appli fait comme avant.
  urlCompte: (typeof window !== "undefined" && window.MIRTV_URL_COMPTE) || "https://compte.mirtv.rahiss.com",
  version: "web-0.1.18"
};
