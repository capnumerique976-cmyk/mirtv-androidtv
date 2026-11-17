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
  // Secondes avant d'afficher « Cette chaîne ne marche pas en ce moment. »
  delaiChaineSecondes: 10,
  // Tentatives silencieuses de reconnexion pendant la lecture d'une chaîne.
  reconnexions: 3,
  version: "web-0.1.6"
};
