<?php

namespace Paymenter\Extensions\Servers\MirTV;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Server;
use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\Plan;
use App\Models\Server as ServerModel;
use App\Models\Service;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/**
 * MirTV — comptes des familles dans Jellyfin.
 *
 * - Commande payée : la famille est créée (profils f<numéro>-parent, f<numéro>-enfant1…, droits copiés sur les
 *   comptes modèles), avec un jeton de paiement (lien /payer/<jeton>) et un code d'activation des écrans.
 * - Échéance non payée : Paymenter suspend → les profils sont désactivés (les écrans s'arrêtent).
 * - Paiement : Paymenter réactive → les profils remarchent tout seuls.
 * - Résiliation : profils désactivés, supprimés 90 jours plus tard.
 *
 * Adresses publiques :
 *   GET  /payer/{jeton}          page de paiement de la famille (sans mot de passe, sans donnée personnelle)
 *   GET  /abonnement/{jeton}     état de l'abonnement en JSON (lu par l'appli)
 *   GET  /mirtv/etat             même chose pour un écran connecté (en-tête X-MirTV-Jeton : jeton Jellyfin)
 *   POST /mirtv/activer          code d'activation → profils connectés sur cet écran
 */
#[ExtensionMeta(
    name: 'MirTV (comptes Jellyfin)',
    description: 'Crée, suspend et réactive les familles MirTV dans Jellyfin.',
    version: '1.0.0',
    author: 'MirTV',
)]
class MirTV extends Server
{
    public const TABLE = 'ext_mirtv_familles';

    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Familles résiliées depuis 90 jours : profils supprimés (une fois par jour)
        Schedule::call(fn () => self::nettoyer())->dailyAt('03:30')->name('mirtv-familles-nettoyage')->withoutOverlapping();
    }

    public function installed()
    {
        ExtensionHelper::runMigrations('extensions/Servers/MirTV/database/migrations');
    }

    public function getConfig($values = []): array
    {
        return [
            ['name' => 'url_jellyfin', 'label' => 'Adresse interne de Jellyfin', 'type' => 'text', 'default' => 'http://jellyfin:8096', 'required' => true],
            ['name' => 'url_publique', 'label' => 'Adresse publique du portail', 'type' => 'text', 'default' => 'https://compte.mirtv.rahiss.com', 'required' => true],
            ['name' => 'url_appli', 'label' => 'Adresse de l\'appli MirTV', 'type' => 'text', 'default' => 'https://mirtv.rahiss.com/tv/', 'required' => true],
            ['name' => 'modele_adulte', 'label' => 'Compte modèle adulte (droits copiés)', 'type' => 'text', 'default' => 'f901-parent', 'required' => true],
            ['name' => 'modele_enfant', 'label' => 'Compte modèle enfant (droits copiés)', 'type' => 'text', 'default' => 'f901-lova', 'required' => true],
            ['name' => 'premier_numero', 'label' => 'Premier numéro de famille', 'type' => 'number', 'default' => 1001, 'required' => true],
            ['name' => 'whatsapp', 'label' => 'Numéro WhatsApp de l\'aide (ex. 261340000000)', 'type' => 'text', 'required' => false],
        ];
    }

    public function testConfig(): bool|string
    {
        try {
            $this->jf()->utilisateurs();

            return true;
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    public function getProductConfig($values = []): array
    {
        return [
            ['name' => 'ecrans', 'label' => 'Écrans en même temps', 'type' => 'number', 'default' => 2, 'required' => true],
            ['name' => 'profils_enfants', 'label' => 'Profils enfants créés', 'type' => 'number', 'default' => 2, 'required' => true],
        ];
    }

    /* ------------------------------------------------------------------ outils */

    public function jf(): Jellyfin
    {
        return new Jellyfin($this->config('url_jellyfin') ?: 'http://jellyfin:8096');
    }

    public static function instance(): self
    {
        $s = ServerModel::where('extension', 'MirTV')->first();
        if (!$s) {
            throw new Exception('Extension MirTV non configurée');
        }

        return new self($s->settings->pluck('value', 'key')->toArray());
    }

    public static function famille(Service $service): ?object
    {
        return DB::table(self::TABLE)->where('service_id', $service->id)->first();
    }

    public static function code(): string
    {
        $lettres = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // sans 0/O, 1/I/L

        return collect(range(1, 8))->map(fn () => $lettres[random_int(0, strlen($lettres) - 1)])->implode('');
    }

    /* ------------------------------------------------------------------ cycle de vie */

    public function createServer(Service $service, $settings, $properties)
    {
        if (self::famille($service)) {
            return ['famille' => self::famille($service)->numero]; // déjà créée (commande relancée)
        }
        $jf = $this->jf();
        $adulte = $jf->parNom($this->config('modele_adulte'));
        $enfant = $jf->parNom($this->config('modele_enfant'));
        if (!$adulte || !$enfant) {
            throw new Exception('Comptes modèles introuvables dans Jellyfin');
        }
        $polAdulte = $jf->politique($adulte['Id']);
        $polEnfant = $jf->politique($enfant['Id']);

        $numero = DB::transaction(function () use ($service) {
            $max = (int) DB::table(self::TABLE)->lockForUpdate()->max('numero');
            $numero = max($max + 1, (int) ($this->config('premier_numero') ?: 1001));
            DB::table(self::TABLE)->insert([
                'service_id' => $service->id,
                'numero' => $numero,
                'jeton_paiement' => bin2hex(random_bytes(16)),
                'code_activation' => self::code(),
                'code_expire_at' => now()->addHours(48),
                'statut' => 'creation',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $numero;
        });

        $profils = [];
        $nb = max(0, min(5, (int) ($settings['profils_enfants'] ?? 2)));
        $noms = ["f{$numero}-parent"];
        for ($i = 1; $i <= $nb; $i++) {
            $noms[] = "f{$numero}-enfant{$i}";
        }
        foreach ($noms as $i => $nom) {
            $mdp = Jellyfin::motDePasse();
            $u = $jf->creer($nom, $mdp, $i === 0 ? $polAdulte : $polEnfant);
            $profils[] = ['nom' => $nom, 'id' => $u['Id'], 'mdp' => $mdp];
        }
        DB::table(self::TABLE)->where('service_id', $service->id)->update([
            'profils' => Crypt::encryptString(json_encode($profils)),
            'ecrans' => (int) ($settings['ecrans'] ?? 2),
            'statut' => 'active',
            'updated_at' => now(),
        ]);
        self::noterLimite($numero, (int) ($settings['ecrans'] ?? 2));
        Log::info("MirTV : famille {$numero} créée (" . count($profils) . ' profils)');

        return ['famille' => $numero];
    }

    private function changerEtat(Service $service, bool $actif, string $statut): bool
    {
        $f = self::famille($service);
        if (!$f) {
            return true;
        }
        $jf = $this->jf();
        foreach ($jf->famille((int) $f->numero) as $u) {
            $jf->activer($u['Id'], $actif);
        }
        DB::table(self::TABLE)->where('id', $f->id)->update(['statut' => $statut, 'updated_at' => now()]);

        return true;
    }

    public function suspendServer(Service $service, $settings, $properties)
    {
        return $this->changerEtat($service, false, 'pause');
    }

    public function unsuspendServer(Service $service, $settings, $properties)
    {
        return $this->changerEtat($service, true, 'active');
    }

    public function terminateServer(Service $service, $settings, $properties)
    {
        $this->changerEtat($service, false, 'resiliee');
        DB::table(self::TABLE)->where('service_id', $service->id)->update(['resiliee_at' => now()]);

        return true;
    }

    public function upgradeServer(Service $service, $settings, $properties)
    {
        $f = self::famille($service);
        if ($f) {
            DB::table(self::TABLE)->where('id', $f->id)->update(['ecrans' => (int) ($settings['ecrans'] ?? $f->ecrans), 'updated_at' => now()]);
            self::noterLimite((int) $f->numero, (int) ($settings['ecrans'] ?? $f->ecrans));
        }

        return true;
    }

    public function getActions(Service $service)
    {
        $f = self::famille($service);
        if (!$f) {
            return [];
        }

        return [
            ['type' => 'button', 'label' => 'Payer ou renouveler', 'url' => rtrim($this->config('url_publique'), '/') . '/payer/' . $f->jeton_paiement],
            ['type' => 'button', 'label' => 'Ouvrir MirTV', 'url' => $this->config('url_appli')],
        ];
    }

    /** Limite d'écrans par famille, lue par mirtv-garde (reglages.json → limites_familles). */
    public static function noterLimite(int $numero, int $ecrans): void
    {
        $p = getenv('MIRTV_LIMITES_FICHIER') ?: '/opt/mirtv-partage/limites-familles.json';
        try {
            $l = is_readable($p) ? (json_decode((string) file_get_contents($p), true) ?: []) : [];
            $l[(string) $numero] = $ecrans;
            @file_put_contents($p, json_encode($l, JSON_PRETTY_PRINT));
        } catch (\Throwable $e) { /* la garde garde sa limite par défaut */
        }
    }

    /** Suppression des profils des familles résiliées depuis 90 jours. */
    public static function nettoyer(): void
    {
        DB::table(self::TABLE)->where('statut', 'resiliee')->where('resiliee_at', '<', now()->subDays(90))->get()->each(function ($f) {
            try {
                $jf = self::instance()->jf();
                foreach ($jf->famille((int) $f->numero) as $u) {
                    $jf->supprimer($u['Id']);
                }
                DB::table(self::TABLE)->where('id', $f->id)->update(['statut' => 'supprimee', 'profils' => null, 'updated_at' => now()]);
            } catch (Exception $e) {
                Log::warning('MirTV : nettoyage de la famille ' . $f->numero . ' impossible');
            }
        });
    }

    /* ------------------------------------------------------------------ état pour l'appli */

    public static function etat(object $f): array
    {
        $service = Service::find($f->service_id);
        $statut = match (true) {
            !$service => 'inconnu',
            $service->status === 'active' => 'actif',
            $service->status === 'suspended' => 'en_pause',
            in_array($service->status, ['cancelled', 'terminated']) => 'resilie',
            default => 'en_attente',
        };

        return [
            'statut' => $statut,
            'fin' => $service?->expires_at?->toIso8601String(),
            'formule' => $service?->plan?->name,
            'ecrans' => (int) $f->ecrans,
            'famille' => (int) $f->numero,
            'payer' => rtrim(self::instance()->config('url_publique'), '/') . '/payer/' . $f->jeton_paiement,
            'whatsapp' => self::instance()->config('whatsapp'),
        ];
    }

    private static function cors($reponse, Request $request)
    {
        $origine = $request->headers->get('Origin');
        $permise = parse_url(self::instance()->config('url_appli') ?: '', PHP_URL_SCHEME) . '://' . parse_url(self::instance()->config('url_appli') ?: '', PHP_URL_HOST);
        if ($origine && $origine === $permise) {
            $reponse->headers->set('Access-Control-Allow-Origin', $origine);
            $reponse->headers->set('Access-Control-Allow-Headers', 'Content-Type, X-MirTV-Jeton');
            $reponse->headers->set('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
            $reponse->headers->set('Vary', 'Origin');
        }

        return $reponse->header('Cache-Control', 'no-store');
    }

    public function options(Request $request)
    {
        return self::cors(response('', 204), $request);
    }

    public function abonnement(Request $request, string $jeton)
    {
        $f = DB::table(self::TABLE)->where('jeton_paiement', $jeton)->first();
        if (!$f) {
            return self::cors(response()->json(['statut' => 'inconnu'], 404), $request);
        }

        return self::cors(response()->json(self::etat($f)), $request);
    }

    /** État pour un écran connecté : prouve qui il est avec son jeton Jellyfin. */
    public function etatEcran(Request $request)
    {
        $jeton = (string) $request->header('X-MirTV-Jeton', '');
        $moi = $jeton !== '' ? self::instance()->jf()->moi($jeton) : null;
        if (!$moi || !preg_match('/^f(\d+)-/i', $moi['Name'] ?? '', $m)) {
            return self::cors(response()->json(['statut' => 'inconnu'], 404), $request);
        }
        $f = DB::table(self::TABLE)->where('numero', (int) $m[1])->first();
        if (!$f) {
            return self::cors(response()->json(['statut' => 'test'], 200), $request); // familles de test (901, 902)
        }

        return self::cors(response()->json(self::etat($f) + ['jeton' => $f->jeton_paiement]), $request);
    }

    /** Code d'activation → profils de la famille connectés sur cet écran (code à usage unique, 48 h). */
    public function activer(Request $request)
    {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $request->input('code', '')));
        $f = $code !== '' ? DB::table(self::TABLE)->where('code_activation', $code)->where('code_expire_at', '>', now())->first() : null;
        if (!$f || !$f->profils) {
            Log::notice('MirTV : code d\'activation refusé');

            return self::cors(response()->json(['erreur' => 'code'], 404), $request);
        }
        $jf = self::instance()->jf();
        $appareil = (string) $request->input('appareil', 'Ecran MirTV');
        $idAppareil = (string) $request->input('id_appareil', Str::uuid());
        $connectes = [];
        foreach (json_decode(Crypt::decryptString($f->profils), true) as $p) {
            try {
                $connectes[] = $jf->connecter($p['nom'], $p['mdp'], $appareil, $idAppareil);
            } catch (Exception $e) {
                Log::warning('MirTV : connexion d\'un profil impossible pendant l\'activation');
            }
        }
        // Code utilisé : on en prépare un nouveau (à donner pour l'écran suivant), valable 48 h
        DB::table(self::TABLE)->where('id', $f->id)->update(['code_activation' => self::code(), 'code_expire_at' => now()->addHours(48), 'updated_at' => now()]);

        return self::cors(response()->json(['profils' => $connectes, 'jeton' => $f->jeton_paiement, 'famille' => (int) $f->numero]), $request);
    }

    /* ------------------------------------------------------------------ page de paiement */

    public function payer(Request $request, string $jeton)
    {
        $f = DB::table(self::TABLE)->where('jeton_paiement', $jeton)->first();
        abort_unless($f, 404);
        $service = Service::find($f->service_id);
        abort_unless($service, 404);
        $plans = $service->product->plans()->with('prices')->get()->filter(fn ($p) => $p->prices->where('currency_code', $service->currency_code)->isNotEmpty())->values();

        if ($request->isMethod('post')) {
            $plan = $plans->firstWhere('id', (int) $request->input('formule'));
            abort_unless($plan, 422);
            $facture = self::factureAPayer($service, $plan);
            $passerelle = Gateway::where('extension', 'OrangeMoney')->first();
            abort_unless($passerelle, 503);

            return redirect()->away(ExtensionHelper::pay($passerelle, $facture));
        }

        return response(Pages::payer($f, $service, $plans, self::instance()->config('whatsapp'), csrf_token()))->header('Cache-Control', 'no-store');
    }

    /** Facture de la prochaine période pour la formule choisie (reprend la facture en attente si c'est la même). */
    public static function factureAPayer(Service $service, Plan $plan): Invoice
    {
        $prix = (float) $plan->price($service->currency_code)->price;
        $attente = $service->invoices()->where('status', 'pending')->latest()->first();
        if ($attente && (int) $service->plan_id === (int) $plan->id) {
            return $attente;
        }
        if ($attente && !$attente->transactions()->exists()) {
            $attente->update(['status' => 'cancelled']);
        }
        if ((int) $service->plan_id !== (int) $plan->id) {
            $service->update(['plan_id' => $plan->id, 'price' => $prix]);
        }
        $facture = $service->invoices()->make([
            'user_id' => $service->user_id,
            'status' => 'pending',
            'due_at' => $service->expires_at && $service->expires_at->isFuture() ? $service->expires_at : now(),
            'currency_code' => $service->currency_code,
        ]);
        $facture->save();
        $facture->items()->create([
            'reference_id' => $service->id,
            'reference_type' => Service::class,
            'price' => $prix,
            'quantity' => $service->quantity ?: 1,
            'description' => 'MirTV — ' . $plan->name,
        ]);

        return $facture->refresh();
    }
}
