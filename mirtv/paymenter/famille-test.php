<?php

/*
 * MirTV : famille de TEST pour vérifier tout le parcours en mode simulation (aucun vrai paiement).
 * Lancé par famille-test.sh :  bash famille-test.sh creer | etat | pause | supprimer
 *  - creer     : client fictif « Famille Test » (famille-test@mirtv.invalid) + commande « Semaine (TEST) »
 *                + facture, et affiche l'adresse de la page de paiement simulé
 *  - etat      : où en est la famille (service, n° de famille, profils Jellyfin, page « payer »)
 *  - pause     : fait comme si l'abonnement avait expiré (profils mis en pause)
 *  - supprimer : efface tout ce que le test a créé (profils Jellyfin, service, factures, client)
 * N'affiche jamais de mot de passe.
 */

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Paymenter\Extensions\Servers\MirTV\MirTV;

$mode = getenv('MIRTV_TEST') ?: 'etat';
$email = 'famille-test@mirtv.invalid';
$client = User::where('email', $email)->first();
$service = $client ? Service::where('user_id', $client->id)->latest('id')->first() : null;
$f = $service ? MirTV::famille($service) : null;
$publique = rtrim(MirTV::instance()->config('url_publique') ?: config('app.url'), '/');

if ($mode === 'creer') {
    if (!$client) {
        $client = User::create([
            'first_name' => 'Famille',
            'last_name' => 'Test',
            'email' => $email,
            'password' => Str::random(40), // jamais utilisé ni affiché
        ]);
    }
    $produit = Product::where('slug', 'mirtv-famille')->firstOrFail();
    $formule = $produit->plans()->orderBy('sort')->firstOrFail(); // Semaine (TEST)
    $prix = (float) $formule->price('MGA')->price;
    if (!$service || in_array($service->status, ['cancelled'])) {
        $service = Service::create([
            'user_id' => $client->id,
            'product_id' => $produit->id,
            'plan_id' => $formule->id,
            'price' => $prix,
            'quantity' => 1,
            'currency_code' => 'MGA',
            'status' => 'pending',
        ]);
    }
    $facture = MirTV::factureAPayer($service, $formule);
    $orange = Gateway::where('extension', 'OrangeMoney')->firstOrFail();
    $url = ExtensionHelper::pay($orange, $facture);
    echo "TEST : client n° {$client->id}, service n° {$service->id}, facture n° {$facture->id} (" . number_format($prix, 0, ',', ' ') . " Ar)\n";
    echo "TEST : page de paiement simulé : {$url}\n";
} elseif ($mode === 'pause') {
    abort_unless($service, 404);
    ExtensionHelper::suspendServer($service);
    $service->update(['status' => 'suspended']);
    echo "TEST : service n° {$service->id} mis en pause\n";
} elseif ($mode === 'supprimer') {
    if ($f) {
        $jf = MirTV::instance()->jf();
        foreach ($jf->famille((int) $f->numero) as $u) {
            $jf->supprimer($u['Id']);
            echo "TEST : profil {$u['Name']} supprimé de Jellyfin\n";
        }
        DB::table('ext_mirtv_familles')->where('id', $f->id)->delete();
    }
    if ($client) {
        foreach (Service::where('user_id', $client->id)->get() as $s) {
            foreach ($s->invoices as $i) {
                DB::table('ext_orange_paiements')->where('invoice_id', $i->id)->delete();
                $i->transactions()->delete();
                $i->items()->delete();
                $i->delete();
            }
            $s->delete();
        }
        $client->delete();
    }
    echo "TEST : famille de test effacée\n";
}

// État (toujours affiché à la fin)
$client = User::where('email', $email)->first();
$service = $client ? Service::where('user_id', $client->id)->latest('id')->first() : null;
$f = $service ? MirTV::famille($service) : null;
if (!$service) {
    echo "ÉTAT : pas de famille de test\n";

    return;
}
echo "ÉTAT : service n° {$service->id} « " . $service->status . ' », échéance ' . ($service->expires_at ? $service->expires_at->format('d/m/Y H:i') : '—') . "\n";
foreach ($service->invoices()->latest('id')->take(3)->get() as $i) {
    echo "ÉTAT : facture n° {$i->id} : {$i->status}\n";
}
if ($f) {
    $profils = collect(MirTV::instance()->jf()->famille((int) $f->numero))
        ->map(fn ($u) => $u['Name'] . (($u['Policy']['IsDisabled'] ?? false) ? ' (en pause)' : ' (actif)'))->implode(', ');
    echo "ÉTAT : famille n° {$f->numero} « {$f->statut} », {$f->ecrans} écrans, profils : {$profils}\n";
    echo "ÉTAT : code d'activation " . substr($f->code_activation, 0, 4) . '-' . substr($f->code_activation, 4) . "\n";
    echo "ÉTAT : page « payer » : {$publique}/payer/{$f->jeton_paiement}\n";
    echo "ÉTAT : état pour l'appli : {$publique}/abonnement/{$f->jeton_paiement}\n";
} else {
    echo "ÉTAT : famille pas encore créée dans Jellyfin (paiement attendu)\n";
}
