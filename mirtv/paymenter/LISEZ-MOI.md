# MirTV — paiements et comptes (Paymenter)

- `installer-paymenter.sh` : installe ou met à jour Paymenter sur https://compte.mirtv.rahiss.com (relançable).
- `brancher-orange.sh` : **la seule étape restante quand le compte marchand Orange est ouvert** (coller 3 clés, choisir le mode).
- `extensions/Gateways/OrangeMoney` : paiement Orange Money Web Payment (simulation, sandbox, production).
- `extensions/Servers/MirTV` : familles dans Jellyfin (création, pause, réactivation, page /payer, état pour l'appli, code d'activation).
- `configurer.php` : ariary, français, formules de TEST, réglages des échéances.
- `tests/` : 18 tests (faux serveur Orange, faux Jellyfin). Ils se lancent dans une copie de Paymenter
  (`php vendor/bin/phpunit tests/Feature/MirTV`).
