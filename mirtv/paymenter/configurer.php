<?php

/**
 * MirTV — réglages de Paymenter (relançable sans danger, ne remplace jamais ce que Ravi a changé à la main
 * sauf les réglages techniques marqués « toujours »).
 *
 * Lancé dans le conteneur :  php artisan tinker --execute="require '/app/mirtv/configurer.php';"
 *
 * - Langue française, fuseau Indian/Antananarivo, monnaie ariary (MGA, « Ar », sans décimales)
 * - Factures de renouvellement 3 jours avant l'échéance, pause le jour même, arrêt après 90 jours
 * - Passerelle « Orange Money » (mode simulation) et serveur « MirTV » (comptes Jellyfin)
 * - Catégorie « MirTV », produit « MirTV Famille » et ses 4 formules aux prix de TEST
 */

use App\Helpers\ExtensionHelper;
use App\Models\Category;
use App\Models\Currency;
use App\Models\CustomProperty;
use App\Models\Gateway;
use App\Models\Product;
use App\Models\Server;
use App\Models\Setting;
use App\Models\User;

$reglage = function (string $cle, $valeur, ?string $type = null, bool $toujours = true) {
    $s = Setting::where('key', $cle)->whereNull('settingable_type')->first();
    if ($s && !$toujours) {
        return;
    }
    $donnees = ['value' => $valeur];
    if ($type) {
        $donnees['type'] = $type;
    }
    Setting::updateOrCreate(['key' => $cle, 'settingable_type' => null, 'settingable_id' => null], $donnees);
};

$url = getenv('MIRTV_URL_COMPTE') ?: 'https://compte.mirtv.rahiss.com';

// 1. Réglages généraux
$nomActuel = Setting::where('key', 'company_name')->whereNull('settingable_type')->value('value');
$reglage('company_name', 'MirTV', null, !$nomActuel || strcasecmp($nomActuel, 'Paymenter') === 0); // le nom d'origine « Paymenter » devient « MirTV »
$reglage('app_url', $url);
$reglage('timezone', 'Indian/Antananarivo');
$reglage('app_language', 'fr');
$reglage('allowed_languages', ['fr', 'en'], 'array');
$reglage('trusted_proxies', ['*'], 'array');          // derrière Caddy du serveur
$reglage('cronjob_invoice', 3, 'integer');            // facture de renouvellement 3 jours avant la fin
$reglage('cronjob_invoice_reminder', 1, 'integer');
$reglage('cronjob_order_suspend', 0, 'integer');      // pause le jour de l'échéance
$reglage('cronjob_order_terminate', 90, 'integer');   // arrêt après 90 jours d'impayé
$reglage('cronjob_order_cancel', 7, 'integer');
$reglage('tax_enabled', false, 'boolean');
$reglage('mail_disable', true, 'boolean', false);     // pas d'e-mail tant qu'aucun serveur d'envoi n'est réglé
$reglage('mail_must_verify', false, 'boolean');

// 1 bis. Factures : numéros MIRTV-2026-0001, attribués seulement au paiement (pas de trou), logo MirTV
$format = Setting::where('key', 'invoice_number_format')->whereNull('settingable_type')->value('value');
if (!$format || $format === 'INV-{number}') {
    $reglage('invoice_number_format', 'MIRTV-{year}-{number}');
    $reglage('invoice_number_padding', 4, 'integer');
    $reglage('invoice_proforma', true, 'boolean');
}
$reglage('bill_to_text', 'MirTV', null, false);
foreach (['logo' => 'mirtv-logo-clair.png', 'logo_dark' => 'mirtv-logo-sombre.png'] as $cle => $fichier) {
    if (is_file(storage_path('app/public/' . $fichier))) {
        $reglage($cle, $fichier, null, false);
    }
}

// 1 ter. Téléphone WhatsApp du client : demandé à l'inscription, imprimé sur les factures, sert aux relances
CustomProperty::firstOrCreate(['key' => 'telephone'], [
    'name' => 'Téléphone (WhatsApp)',
    'description' => 'Pour l\'aide et les rappels de paiement. Exemple : 034 12 345 67',
    'type' => 'text',
    'model' => User::class,
    'validation' => 'regex:/^[0-9 +().-]{8,20}$/',
    'non_editable' => false,
    'required' => true,
    'show_on_invoice' => true,
]);

// 2. Monnaie : ariary
Currency::updateOrCreate(['code' => 'MGA'], ['name' => 'Ariary', 'prefix' => null, 'suffix' => ' Ar', 'format' => '1 000,00']);
$reglage('default_currency', 'MGA');

// 3. Passerelle Orange Money (commence en simulation : aucun argent)
$om = Gateway::firstOrCreate(['extension' => 'OrangeMoney', 'type' => 'gateway'], ['name' => 'Orange Money', 'enabled' => true]);
foreach (['mode' => 'simulation', 'pays' => 'mg', 'pays_sandbox' => 'dev', 'url_api' => 'https://api.orange.com', 'monnaie_sandbox' => 'OUV', 'url_publique' => $url] as $k => $v) {
    if (!$om->settings()->where('key', $k)->exists()) {
        $om->settings()->create(['key' => $k, 'value' => $v]);
    }
}
ExtensionHelper::runMigrations('extensions/Gateways/OrangeMoney/database/migrations');

// 4. Serveur MirTV (comptes Jellyfin)
$srv = Server::firstOrCreate(['extension' => 'MirTV', 'type' => 'server'], ['name' => 'MirTV Jellyfin', 'enabled' => true]);
foreach ([
    'url_jellyfin' => getenv('MIRTV_URL_JELLYFIN') ?: 'http://jellyfin:8096',
    'url_publique' => $url,
    'url_appli' => 'https://mirtv.rahiss.com/tv/',
    'modele_adulte' => 'f901-parent',
    'modele_enfant' => 'f901-lova',
    'premier_numero' => 1001,
    'whatsapp' => '',
    'whatsapp_message' => 'Bonjour MirTV, j\'ai besoin d\'aide.',
    'aide_langues' => 'français, malgache',
    'annonce_active' => 0,
    'annonce_type' => 'info',
] as $k => $v) {
    if (!$srv->settings()->where('key', $k)->exists()) {
        $srv->settings()->create(['key' => $k, 'value' => $v]);
    }
}
ExtensionHelper::runMigrations('extensions/Servers/MirTV/database/migrations');

// 5. Produit et formules (prix de TEST, à changer dans le guichet MirTV : « Formules et prix »)
$cat = Category::firstOrCreate(['slug' => 'mirtv'], ['name' => 'MirTV', 'description' => 'La télé pour toute la famille.']);
$prod = Product::where('slug', 'mirtv-famille')->first();
if (!$prod) {
    $prod = Product::create([
        'category_id' => $cat->id,
        'name' => 'MirTV Famille',
        'slug' => 'mirtv-famille',
        'description' => 'Toutes les chaînes MirTV, les films et l\'espace enfants. 2 écrans en même temps.',
        'server_id' => $srv->id,
    ]);
    $prod->settings()->create(['key' => 'ecrans', 'value' => 2]);
    $prod->settings()->create(['key' => 'profils_enfants', 'value' => 2]);
    $formules = [
        ['Semaine (TEST)', 1, 'week', 5000],
        ['Mois (TEST)', 1, 'month', 15000],
        ['Trimestre (TEST)', 3, 'month', 40000],
        ['Année (TEST)', 1, 'year', 150000],
    ];
    foreach ($formules as $i => [$nom, $n, $unite, $prix]) {
        $plan = $prod->plans()->create(['name' => $nom, 'type' => 'recurring', 'billing_period' => $n, 'billing_unit' => $unite, 'sort' => $i]);
        $plan->prices()->create(['price' => $prix, 'currency_code' => 'MGA']);
    }
}

echo "MirTV : Paymenter réglé (MGA, français, Orange Money en " . $om->settings()->where('key', 'mode')->value('value') . ", produit « MirTV Famille » avec " . $prod->plans()->count() . " formules).\n";
