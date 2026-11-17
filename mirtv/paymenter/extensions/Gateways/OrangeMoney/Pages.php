<?php

namespace Paymenter\Extensions\Gateways\OrangeMoney;

/**
 * Pages simples aux couleurs MirTV (lisibles sur un petit téléphone, sans script).
 */
class Pages
{
    private static function cadre(string $titre, string $corps, string $entete = ''): string
    {
        $t = e($titre);

        return <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$t} · MirTV</title>{$entete}
<style>
body{margin:0;font-family:Lexend,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#120E36;color:#fff;font-size:18px;line-height:1.45}
main{max-width:30rem;margin:0 auto;padding:1.5rem 1.2rem 3rem}
.logo{font-weight:800;font-size:1.4rem}.logo b{background:#FF9F1C;color:#120E36;border-radius:.4rem;padding:0 .3rem;margin-right:.15rem}
h1{font-size:1.6rem;margin:1.6rem 0 .6rem}
p{margin:.6rem 0}.petit{opacity:.75;font-size:.95rem}
.carte{background:rgba(255,255,255,.08);border-radius:1rem;padding:1.1rem 1.2rem;margin:1.2rem 0}
.bouton{display:block;width:100%;box-sizing:border-box;min-height:3.2rem;border:0;border-radius:.9rem;font:inherit;font-weight:700;
  font-size:1.1rem;padding:.8rem 1rem;margin:.7rem 0;text-align:center;text-decoration:none;cursor:pointer}
.orange{background:#FF7900;color:#fff}.vert{background:#5BE37D;color:#120E36}.gris{background:rgba(255,255,255,.14);color:#fff}
.rond{width:4.5rem;height:4.5rem;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2.4rem;margin:1rem 0}
.ok{background:#5BE37D;color:#120E36}.attente{background:#FFD93D;color:#120E36}.non{background:#FF5C8A;color:#120E36}
.test{display:inline-block;background:#FFD93D;color:#120E36;border-radius:.4rem;padding:.1rem .5rem;font-weight:700;font-size:.85rem}
</style></head><body><main><div class="logo"><b>Mir</b>TV</div>{$corps}</main></body></html>
HTML;
    }

    public static function retour(string $statut, bool $annule, string $facture, string $ici): string
    {
        $f = e($facture);
        if ($statut === 'SUCCESS') {
            return self::cadre('Paiement reçu', <<<HTML
<div class="rond ok">✓</div><h1>Merci, c'est payé !</h1>
<p>Votre abonnement est prolongé. La télé se remet en marche toute seule dans une minute.</p>
<a class="bouton vert" href="{$f}">Voir ma facture</a>
HTML);
        }
        if ($annule || in_array($statut, ['FAILED', 'EXPIRED', 'MONTANT'])) {
            return self::cadre('Paiement non fait', <<<HTML
<div class="rond non">!</div><h1>Le paiement n'est pas fait.</h1>
<p>Aucun argent n'a été pris. Vous pouvez réessayer.</p>
<a class="bouton orange" href="{$f}">Réessayer</a>
HTML);
        }
        $i = e($ici);

        return self::cadre('Paiement en cours', <<<HTML
<div class="rond attente">…</div><h1>Paiement en cours de vérification</h1>
<p>Ne fermez pas cette page. Elle se met à jour toute seule.</p>
<p class="petit">Si rien ne change après 5 minutes, appelez l'aide MirTV.</p>
<a class="bouton gris" href="{$i}">Actualiser</a>
HTML, '<meta http-equiv="refresh" content="5">');
    }

    public static function simulation(object $ligne, string $csrf): string
    {
        $montant = number_format((int) $ligne->amount, 0, ',', ' ');
        $c = e($csrf);
        $o = e($ligne->order_id);

        return self::cadre('Simulation Orange Money', <<<HTML
<h1>Orange Money <span class="test">SIMULATION</span></h1>
<p>Cette page remplace la page Orange pendant les essais. <b>Aucun argent n'est pris.</b></p>
<div class="carte"><p>Montant : <b>{$montant} Ar</b></p><p class="petit">Commande {$o}</p></div>
<form method="post"><input type="hidden" name="_token" value="{$c}">
<button class="bouton orange" name="choix" value="payer">Payer (simulation)</button>
<button class="bouton gris" name="choix" value="refuser">Refuser le paiement</button></form>
HTML);
    }
}
