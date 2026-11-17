<?php

namespace Paymenter\Extensions\Servers\MirTV;

use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Page de paiement de la famille (lien du QR code) aux couleurs MirTV.
 * Aucune donnée personnelle : seulement « Famille n° … », l'état et la date de fin.
 */
class Pages
{
    public static function payer(object $f, Service $service, Collection $plans, ?string $whatsapp, string $csrf): string
    {
        $num = (int) $f->numero;
        $fin = $service->expires_at ? $service->expires_at->locale('fr')->isoFormat('D MMMM YYYY') : null;
        $etat = match ($service->status) {
            'active' => $fin ? "Votre abonnement marche jusqu'au <b>{$fin}</b>." : 'Votre abonnement marche.',
            'suspended' => '<b>Votre abonnement est en pause.</b> Payez pour remettre la télé en marche.',
            'pending' => 'Votre abonnement attend le premier paiement.',
            default => 'Votre abonnement est arrêté.',
        };
        $choix = '';
        foreach ($plans as $i => $p) {
            $prix = $p->prices->where('currency_code', $service->currency_code)->first();
            $montant = number_format((float) $prix->price, 0, ',', ' ');
            $coche = ((int) $p->id === (int) $service->plan_id || ($i === 0 && !$plans->firstWhere('id', $service->plan_id))) ? 'checked' : '';
            $nom = e($p->name);
            $choix .= "<label class=\"formule\"><input type=\"radio\" name=\"formule\" value=\"{$p->id}\" {$coche}><span><b>{$nom}</b><i>{$montant} Ar</i></span></label>";
        }
        $lien = $whatsapp ? (str_starts_with($whatsapp, 'http') ? $whatsapp : 'https://wa.me/' . preg_replace('/\D/', '', $whatsapp)) : '';
        $aide = $lien ? '<a class="bouton gris" href="' . e($lien) . '" target="_blank" rel="noopener">Aide sur WhatsApp</a>' : '';
        $c = e($csrf);

        return <<<HTML
<!doctype html>
<html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>Payer MirTV</title>
<style>
body{margin:0;font-family:Lexend,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;background:#120E36;color:#fff;font-size:18px;line-height:1.45}
main{max-width:30rem;margin:0 auto;padding:1.5rem 1.2rem 3rem}
.logo{font-weight:800;font-size:1.4rem}.logo b{background:#FF9F1C;color:#120E36;border-radius:.4rem;padding:0 .3rem;margin-right:.15rem}
h1{font-size:1.6rem;margin:1.4rem 0 .4rem}.petit{opacity:.75;font-size:.95rem}
.etat{background:rgba(255,255,255,.08);border-radius:1rem;padding:1rem 1.1rem;margin:1rem 0}
.formule{display:block;margin:.7rem 0;cursor:pointer}.formule input{position:absolute;opacity:0}
.formule span{display:flex;justify-content:space-between;align-items:center;min-height:3.2rem;padding:.6rem 1.1rem;border-radius:.9rem;
  background:rgba(255,255,255,.08);border:.18rem solid transparent}
.formule i{font-style:normal;font-weight:700;color:#FFD93D}
.formule input:checked+span{border-color:#FF9F1C;background:rgba(255,159,28,.16)}
.formule input:focus-visible+span{outline:.2rem solid #fff}
.bouton{display:block;width:100%;box-sizing:border-box;min-height:3.4rem;border:0;border-radius:.9rem;font:inherit;font-weight:800;
  font-size:1.15rem;padding:.8rem 1rem;margin:.8rem 0;text-align:center;text-decoration:none;cursor:pointer}
.orange{background:#FF7900;color:#fff}.gris{background:rgba(255,255,255,.14);color:#fff}
</style></head><body><main>
<div class="logo"><b>Mir</b>TV</div>
<h1>Famille n° {$num}</h1>
<div class="etat">{$etat}</div>
<form method="post">
<input type="hidden" name="_token" value="{$c}">
<p class="petit">Choisissez votre formule :</p>
{$choix}
<button class="bouton orange">Payer avec Orange Money</button>
<p class="petit">Orange vous demandera un code. Gardez votre téléphone près de vous.</p>
</form>
{$aide}
</main></body></html>
HTML;
    }
}
