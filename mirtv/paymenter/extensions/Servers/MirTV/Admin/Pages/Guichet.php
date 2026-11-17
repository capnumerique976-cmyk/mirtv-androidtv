<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use App\Models\Setting;
use App\Models\User;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;
use Paymenter\Extensions\Servers\MirTV\Modeles\Famille;
use Paymenter\Extensions\Servers\MirTV\Modeles\PaiementOrange;
use Paymenter\Extensions\Servers\MirTV\Reglages;
use Throwable;

/**
 * Guichet unique MirTV : tout le pilotage et tous les réglages au même endroit.
 * Ouvert par https://mirtv.rahiss.com/admin (redirection) ou https://compte.mirtv.rahiss.com/admin/mirtv
 */
class Guichet extends PageMirTV
{
    protected static ?string $slug = 'mirtv';

    protected static ?string $title = 'Guichet MirTV';

    protected static ?string $navigationLabel = 'Guichet MirTV';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-dashboard-3-line';

    protected static string|\BackedEnum|null $activeNavigationIcon = 'ri-dashboard-3-fill';

    protected static ?int $navigationSort = -100;

    // Hors groupe : en tête du menu, à côté du tableau de bord
    protected static string|\UnitEnum|null $navigationGroup = null;

    public function getSubheading(): ?string
    {
        return 'Tout le pilotage et tous les réglages de MirTV au même endroit.';
    }

    /** Jellyfin répond-il ? (adresse publique sans clé, résultat gardé 1 minute) */
    private static function jellyfinOk(): bool
    {
        return Cache::remember('mirtv_guichet_jellyfin', 60, function () {
            try {
                return Http::timeout(3)->get(rtrim((string) Reglages::mirtv('url_jellyfin', 'http://jellyfin:8096'), '/') . '/System/Info/Public')->successful();
            } catch (Throwable $e) {
                return false;
            }
        });
    }

    /** Points à régler avant d'ouvrir aux clients : [fait ?, texte, adresse du bouton, libellé du bouton]. */
    public static function aFaire(): array
    {
        $cles = collect(array_keys(Reglages::CLES_ORANGE))->every(fn ($n) => (bool) Reglages::origineCle($n));
        $mode = Reglages::orange('mode', 'simulation');
        $produit = Formules::produit();
        $test = $produit ? $produit->plans()->where('name', 'like', '%(TEST)%')->exists() : true;
        $familleTest = User::where('email', 'famille-test@mirtv.invalid')->exists();
        $nom = Setting::whereNull('settingable_type')->where('key', 'company_name')->first()?->value;
        $mails = !(bool) (Setting::whereNull('settingable_type')->where('key', 'mail_disable')->first()?->value ?? true);

        return [
            [$cles, 'Saisir les 3 clés du compte marchand Orange', CompteOrange::getUrl(), 'Compte Orange'],
            [$mode === 'production', $mode === 'production' ? 'Paiements Orange en production (vrai argent)' : 'Passer Orange Money en production (aujourd\'hui : ' . $mode . ')', CompteOrange::getUrl(), 'Compte Orange'],
            [Reglages::whatsapp() !== '', 'Indiquer le numéro WhatsApp de l\'aide', AideWhatsapp::getUrl(), 'WhatsApp'],
            [!$test, 'Mettre les vrais noms et prix des formules (enlever « (TEST) »)', Formules::getUrl(), 'Formules'],
            [!$familleTest, 'Effacer la famille de test (commande : famille-test.sh supprimer)', Familles::getUrl(), 'Familles'],
            [$nom && strcasecmp($nom, 'Paymenter') !== 0, 'Nom du portail des comptes : « MirTV »', Appli::getUrl(), 'Appli'],
            [$mails, 'Facultatif : envoyer les reçus par e-mail (serveur d\'envoi à régler)', '/admin/settings', 'Réglages du portail'],
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([Html::make(fn () => $this->rendu())]);
    }

    private function rendu(): string
    {
        $mode = Reglages::orange('mode', 'simulation');
        $wa = Reglages::whatsapp();
        $n = Famille::query()->select('statut', DB::raw('count(*) as n'))->groupBy('statut')->pluck('n', 'statut');
        $mois = PaiementOrange::query()->where('status', 'SUCCESS')->where('mode', 'production')->where('updated_at', '>=', now()->startOfMonth());
        $total = (int) (clone $mois)->sum('amount');
        $jf = self::jellyfinOk();
        $annonce = (bool) Reglages::mirtv('annonce_active', false);
        $produit = Formules::produit();
        $nbFormules = $produit ? $produit->plans()->count() : 0;
        $minPrix = $produit ? (int) DB::table('prices')->whereIn('plan_id', $produit->plans()->pluck('id'))->where('currency_code', 'MGA')->min('price') : 0;
        $appli = (string) Reglages::mirtv('url_appli', 'https://mirtv.rahiss.com/tv/');
        $site = rtrim(preg_replace('#/tv/?$#', '', $appli), '/');

        $pastille = fn (string $texte, string $couleur) => '<span class="mg-pastille" style="--c:' . $couleur . '">' . e($texte) . '</span>';
        $modeTxt = ['production' => 'Paiements : RÉELS', 'sandbox' => 'Paiements : sandbox (test Orange)'][$mode] ?? 'Paiements : simulation';

        $etat = '<div class="mg-etat">'
            . $pastille($modeTxt, $mode === 'production' ? '#22c55e' : ($mode === 'sandbox' ? '#f59e0b' : '#3b82f6'))
            . $pastille($wa !== '' ? 'WhatsApp : ' . Reglages::lisible($wa) : 'WhatsApp : pas encore réglé', $wa !== '' ? '#22c55e' : '#f59e0b')
            . $pastille(($n['active'] ?? 0) . ' famille(s) active(s) · ' . ($n['pause'] ?? 0) . ' en pause', '#ff7a00')
            . $pastille('Ce mois : ' . number_format($total, 0, ',', ' ') . ' Ar reçus', '#22c55e')
            . $pastille($jf ? 'Télé (Jellyfin) : en marche' : 'Télé (Jellyfin) : ne répond pas', $jf ? '#22c55e' : '#ef4444')
            . ($annonce ? $pastille('Message affiché sur les écrans', '#3b82f6') : '')
            . '</div>';

        // À faire
        $liste = self::aFaire();
        $restants = count(array_filter($liste, fn ($l) => !$l[0]));
        $faire = '<div class="mg-bloc"><div class="mg-titre-bloc">' . ($restants ? '✅ Avant d\'ouvrir aux clients — encore ' . $restants . ' point(s)' : '🎉 Tout est prêt pour les clients') . '</div><ul class="mg-liste">';
        foreach ($liste as [$fait, $texte, $url, $bouton]) {
            $faire .= '<li class="' . ($fait ? 'fait' : '') . '"><span class="mg-coche">' . ($fait ? '✔' : '○') . '</span><span class="mg-txt">' . e($texte) . '</span>'
                . ($fait ? '' : '<a class="mg-mini" href="' . e($url) . '">' . e($bouton) . ' →</a>') . '</li>';
        }
        $faire .= '</ul></div>';

        // Les guichets
        $carte = function (string $emoji, string $titre, string $texte, string $url, string $etat = '', bool $externe = false) {
            return '<a class="mg-carte" href="' . e($url) . '"' . ($externe ? ' target="_blank" rel="noopener"' : '') . '>'
                . '<span class="mg-emoji">' . $emoji . '</span><span class="mg-ct"><span class="mg-t">' . e($titre) . ($externe ? ' ↗' : '') . '</span>'
                . '<span class="mg-d">' . e($texte) . '</span>' . ($etat !== '' ? '<span class="mg-e">' . e($etat) . '</span>' : '') . '</span></a>';
        };
        $groupe = fn (string $titre, array $cartes) => '<div class="mg-groupe"><div class="mg-titre-groupe">' . e($titre) . '</div><div class="mg-grille">' . implode('', $cartes) . '</div></div>';

        $groupes = $groupe('Paiements', [
            $carte('🟠', 'Compte marchand Orange Money', 'Les 3 clés d\'Orange, le mode (simulation, sandbox, production), le test des clés.', CompteOrange::getUrl(), 'Mode : ' . $mode),
            $carte('🏷️', 'Formules et prix', 'Semaine, mois, trimestre, année : noms, prix en ariary, écrans par famille.', Formules::getUrl(), $nbFormules . ' formule(s)' . ($minPrix ? ' · dès ' . number_format($minPrix, 0, ',', ' ') . ' Ar' : '')),
            $carte('💰', 'Paiements reçus', 'Chaque paiement Orange Money : payé, en attente, échoué. Totaux du jour et du mois.', Paiements::getUrl(), number_format($total, 0, ',', ' ') . ' Ar ce mois'),
            $carte('🧾', 'Factures', 'Toutes les factures du portail (Paymenter).', '/admin/invoice/invoices'),
        ])
        . $groupe('Familles et clients', [
            $carte('👨‍👩‍👧', 'Familles abonnées', 'État, échéance, pause, réactivation, jours offerts, écrans, code d\'activation, lien de paiement.', Familles::getUrl(), ($n['active'] ?? 0) . ' active(s)'),
            $carte('👤', 'Clients', 'Comptes des clients du portail (nom, e-mail, mot de passe oublié).', '/admin/users'),
            $carte('💬', 'Numéro WhatsApp et aide', 'Le numéro que les clients appellent depuis l\'appli et la page de paiement, horaires, langues.', AideWhatsapp::getUrl(), $wa !== '' ? Reglages::lisible($wa) : 'À régler'),
        ])
        . $groupe('Appli et chaînes', [
            $carte('📢', 'Message aux écrans', 'Un bandeau sur toutes les télés : travaux, nouvelle chaîne, promotion. Nom du portail.', Appli::getUrl(), $annonce ? 'Message affiché' : 'Aucun message'),
            $carte('📺', 'Chaînes', 'Ajouter, tester, valider (preuve) ou retirer une chaîne. MirTV se met à jour tout seul.', 'https://admin.mirtv.rahiss.com', 'Back office des chaînes', true),
            $carte('▶️', 'Ouvrir l\'appli MirTV', 'L\'appli telle que les familles la voient.', $appli, '', true),
            $carte('🎛️', 'Jellyfin (serveur télé)', 'Tableau de bord du serveur : écrans connectés, comptes, guide des programmes.', $site . '/web/#/dashboard', '', true),
        ])
        . $groupe('Portail et système', [
            $carte('⚙️', 'Réglages du portail', 'Nom, logo, e-mails d\'envoi, langue, échéances des factures.', '/admin/settings'),
            $carte('⏱️', 'Tâches automatiques', 'Factures de renouvellement, pauses et arrêts faits chaque jour.', '/admin/cron-stats'),
            $carte('🩺', 'Journal des erreurs', 'Ce qui n\'a pas marché (à regarder si un client signale un problème).', '/admin/error-logs'),
        ]);

        $style = '<style>
            .mg-etat{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:18px}
            .mg-pastille{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:13px;font-weight:600;background:color-mix(in srgb,var(--c) 14%,transparent);color:var(--c);border:1px solid color-mix(in srgb,var(--c) 35%,transparent)}
            .mg-pastille::before{content:"";width:8px;height:8px;border-radius:50%;background:var(--c)}
            .mg-bloc{padding:16px 18px;border-radius:14px;background:rgba(255,122,0,.07);border:1px solid rgba(255,122,0,.25);margin-bottom:22px}
            .mg-titre-bloc{font-weight:700;font-size:15px;margin-bottom:8px}
            .mg-liste{list-style:none;margin:0;padding:0;display:grid;gap:6px}
            .mg-liste li{display:flex;align-items:center;gap:10px;font-size:14px}
            .mg-liste li.fait{opacity:.55}
            .mg-coche{width:20px;text-align:center;font-weight:700;color:#ff7a00}
            .mg-liste li.fait .mg-coche{color:#22c55e}
            .mg-txt{flex:1}
            .mg-mini{font-size:13px;font-weight:600;color:#ff7a00;text-decoration:none;white-space:nowrap}
            .mg-mini:hover{text-decoration:underline}
            .mg-groupe{margin-bottom:22px}
            .mg-titre-groupe{font-size:12px;font-weight:700;letter-spacing:.08em;text-transform:uppercase;opacity:.6;margin-bottom:10px}
            .mg-grille{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
            .mg-carte{display:flex;gap:14px;align-items:flex-start;padding:16px;border-radius:14px;text-decoration:none;color:inherit;background:rgba(127,127,127,.07);border:1px solid rgba(127,127,127,.18);transition:transform .12s,border-color .12s,background .12s}
            .mg-carte:hover,.mg-carte:focus{transform:translateY(-2px);border-color:#ff7a00;background:rgba(255,122,0,.08);outline:none}
            .mg-emoji{font-size:28px;line-height:1}
            .mg-ct{display:flex;flex-direction:column;gap:4px}
            .mg-t{font-weight:700;font-size:15px}
            .mg-d{font-size:13px;opacity:.75;line-height:1.35}
            .mg-e{font-size:12px;font-weight:600;color:#ff7a00;margin-top:2px}
        </style>';

        return $style . $etat . $faire . $groupes;
    }
}
