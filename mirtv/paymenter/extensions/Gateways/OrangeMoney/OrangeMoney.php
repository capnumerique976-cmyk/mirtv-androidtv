<?php

namespace Paymenter\Extensions\Gateways\OrangeMoney;

use App\Attributes\ExtensionMeta;
use App\Classes\Extension\Gateway;
use App\Helpers\ExtensionHelper;
use App\Models\Invoice;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Str;

/**
 * MirTV — paiement Orange Money (Web Payment).
 *
 * Circuit : pay() crée le paiement chez Orange et envoie le client sur la page Orange ;
 * Orange prévient notre serveur (avis) ; on vérifie le notif_token PUIS on redemande le statut à Orange ;
 * seul un statut SUCCESS confirmé enregistre le paiement (une seule fois par transaction).
 * La page de retour n'enregistre jamais rien : elle attend la vérification.
 *
 * Trois modes :
 *  - simulation : aucun appel à Orange, une page de test remplace la page Orange (pour essayer tout le circuit) ;
 *  - sandbox    : API de test d'Orange (monnaie OUV) ;
 *  - production : vrai argent (seulement quand le compte marchand est ouvert et que Ravi l'a décidé).
 *
 * Les clés ne sont jamais en base ni dans le panneau : elles sont lues dans des fichiers
 * (/run/mirtv/orange_client_id, orange_client_secret, orange_merchant_key), copiés au démarrage
 * du conteneur depuis /etc/mirtv/secrets/paiement/ du serveur.
 */
#[ExtensionMeta(
    name: 'Orange Money (MirTV)',
    description: 'Paiement Orange Money Web Payment pour MirTV (simulation, sandbox, production).',
    version: '1.0.0',
    author: 'MirTV',
)]
class OrangeMoney extends Gateway
{
    public const TABLE = 'ext_orange_paiements';

    public function boot()
    {
        require __DIR__ . '/routes.php';
        // Rattrapage des avis perdus : toutes les 30 minutes, paiements en attente de moins de 48 h
        Schedule::call(fn () => (new self)->rattraper())->everyThirtyMinutes()->name('mirtv-orange-rattrapage')->withoutOverlapping();
    }

    public function installed()
    {
        ExtensionHelper::runMigrations('extensions/Gateways/OrangeMoney/database/migrations');
    }

    public function getConfig($values = [])
    {
        return [
            [
                'name' => 'mode',
                'label' => 'Mode',
                'type' => 'select',
                'default' => 'simulation',
                'required' => true,
                'options' => [
                    'simulation' => 'Simulation (aucun argent, page de test)',
                    'sandbox' => 'Sandbox Orange (test, monnaie OUV)',
                    'production' => 'Production (vrai argent)',
                ],
                'description' => 'Commencez en simulation. Passez en sandbox quand les clés de test sont saisies, puis en production.',
            ],
            [
                'name' => 'pays',
                'label' => 'Code pays Orange',
                'type' => 'text',
                'default' => 'mg',
                'required' => true,
                'description' => 'À confirmer par Orange Madagascar (provisoire : mg). En sandbox Orange utilise souvent « dev ».',
            ],
            [
                'name' => 'pays_sandbox',
                'label' => 'Code pays en sandbox',
                'type' => 'text',
                'default' => 'dev',
                'required' => true,
            ],
            [
                'name' => 'url_api',
                'label' => 'Adresse de l\'API Orange',
                'type' => 'text',
                'default' => 'https://api.orange.com',
                'required' => true,
            ],
            [
                'name' => 'monnaie_sandbox',
                'label' => 'Monnaie en sandbox',
                'type' => 'text',
                'default' => 'OUV',
                'required' => true,
            ],
            [
                'name' => 'url_publique',
                'label' => 'Adresse publique du portail',
                'type' => 'text',
                'default' => 'https://compte.mirtv.rahiss.com',
                'required' => true,
                'description' => 'Sert à construire les adresses de retour et d\'avis données à Orange.',
            ],
        ];
    }

    /* ------------------------------------------------------------------ outils */

    private function mode(): string
    {
        return $this->config('mode') ?: 'simulation';
    }

    /** Lit un secret dans un fichier (jamais en base, jamais dans les journaux). */
    public static function secret(string $nom): ?string
    {
        $dossier = getenv('MIRTV_SECRETS_DIR') ?: '/run/mirtv';
        $chemin = rtrim($dossier, '/') . '/' . $nom;
        if (!is_readable($chemin)) {
            return null;
        }
        $v = trim((string) file_get_contents($chemin));

        return $v === '' ? null : $v;
    }

    /** Masque un numéro de téléphone pour les journaux : 03X XX XX X12 */
    public static function masquer(?string $tel): string
    {
        if (!$tel) {
            return '';
        }

        return substr($tel, 0, 3) . str_repeat('X', max(0, strlen($tel) - 5)) . substr($tel, -2);
    }

    private function base(): string
    {
        $pays = $this->mode() === 'sandbox' ? $this->config('pays_sandbox') : $this->config('pays');

        return rtrim($this->config('url_api'), '/') . '/orange-money-webpay/' . $pays . '/v1';
    }

    private function urlPublique(string $chemin): string
    {
        return rtrim($this->config('url_publique') ?: config('settings.app_url', url('/')), '/') . $chemin;
    }

    /** Jeton d'accès OAuth, gardé en cache jusqu'à son expiration. */
    private function jeton(): string
    {
        $id = self::secret('orange_client_id');
        $mdp = self::secret('orange_client_secret');
        if (!$id || !$mdp) {
            throw new Exception('Clés Orange absentes : saisissez-les avec saisir-secret-paiement.sh');
        }

        return Cache::remember('mirtv_orange_jeton_' . $this->mode(), 3000, function () use ($id, $mdp) {
            $r = Http::asForm()->withBasicAuth($id, $mdp)->acceptJson()->timeout(20)
                ->post(rtrim($this->config('url_api'), '/') . '/oauth/v3/token', ['grant_type' => 'client_credentials']);
            if (!$r->successful() || !$r->json('access_token')) {
                Log::warning('Orange Money : jeton refusé (code ' . $r->status() . ')');
                throw new Exception('Orange refuse les clés (code ' . $r->status() . ')');
            }

            return $r->json('access_token');
        });
    }

    private function orange(string $chemin, array $corps): array
    {
        $r = Http::withToken($this->jeton())->acceptJson()->asJson()->timeout(30)->post($this->base() . $chemin, $corps);
        if ($r->status() === 401) {
            Cache::forget('mirtv_orange_jeton_' . $this->mode());
        }
        if (!$r->successful()) {
            Log::warning('Orange Money : appel ' . $chemin . ' refusé (code ' . $r->status() . ')');
            throw new Exception('Orange répond ' . $r->status());
        }

        return $r->json() ?? [];
    }

    /** Vérifie que les clés marchent (demande un jeton à Orange). Renvoie « ok » ou la raison. */
    public function tester(): string
    {
        if ($this->mode() === 'simulation') {
            return 'ok (simulation : Orange n\'est pas appelé)';
        }
        if (!self::secret('orange_merchant_key')) {
            return 'clé marchand absente';
        }
        try {
            Cache::forget('mirtv_orange_jeton_' . $this->mode());
            $this->jeton();

            return 'ok';
        } catch (Exception $e) {
            return $e->getMessage();
        }
    }

    /* ------------------------------------------------------------------ paiement */

    /**
     * Crée le paiement et renvoie l'adresse où envoyer le client.
     */
    public function pay(Invoice $invoice, $total)
    {
        $montant = (int) round($total); // l'ariary n'a pas de centimes
        $essai = DB::table(self::TABLE)->where('invoice_id', $invoice->id)->count() + 1;
        $commande = 'MIR-' . $invoice->id . '-' . $essai . '-' . Str::lower(Str::random(4));
        $mode = $this->mode();
        $monnaie = $mode === 'sandbox' ? $this->config('monnaie_sandbox') : ($invoice->currency_code ?: 'MGA');

        $ligne = [
            'invoice_id' => $invoice->id,
            'order_id' => $commande,
            'amount' => $montant,
            'currency' => $monnaie,
            'mode' => $mode,
            'status' => 'INITIATED',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($mode === 'simulation') {
            $ligne['pay_token'] = Str::random(40);
            $ligne['notif_token'] = Str::random(40);
            DB::table(self::TABLE)->insert($ligne);

            return route('extensions.gateways.orangemoney.simulation', ['commande' => $commande]);
        }

        $merchant = self::secret('orange_merchant_key');
        if (!$merchant) {
            throw new Exception('Clé marchand Orange absente : saisissez orange_merchant_key avec saisir-secret-paiement.sh');
        }
        $reponse = $this->orange('/webpayment', [
            'merchant_key' => $merchant,
            'currency' => $monnaie,
            'order_id' => $commande,
            'amount' => $montant,
            'return_url' => $this->urlPublique('/extensions/gateways/orangemoney/retour/' . $commande),
            'cancel_url' => $this->urlPublique('/extensions/gateways/orangemoney/retour/' . $commande . '?annule=1'),
            'notif_url' => $this->urlPublique('/extensions/gateways/orangemoney/avis'),
            'lang' => 'fr',
            'reference' => 'MirTV',
        ]);
        if (empty($reponse['payment_url']) || empty($reponse['pay_token']) || empty($reponse['notif_token'])) {
            throw new Exception('Réponse Orange incomplète');
        }
        $ligne['pay_token'] = $reponse['pay_token'];
        $ligne['notif_token'] = $reponse['notif_token'];
        DB::table(self::TABLE)->insert($ligne);

        return $reponse['payment_url'];
    }

    /**
     * Avis d'Orange (notif_url). Répond toujours 200 pour ne rien révéler.
     * Le paiement n'est enregistré qu'après la vérification du statut auprès d'Orange.
     */
    public function avis(Request $request)
    {
        if (!$request->isJson() || strlen($request->getContent()) > 4096) {
            Log::notice('Orange Money : avis refusé (format)');

            return response()->json(['ok' => true]);
        }
        $jeton = (string) $request->json('notif_token', '');
        $ligne = $jeton !== '' ? DB::table(self::TABLE)->where('notif_token', $jeton)->first() : null;
        if (!$ligne) {
            Log::notice('Orange Money : avis inconnu ignoré');

            return response()->json(['ok' => true]);
        }
        try {
            (new self)->verifier($ligne->order_id);
        } catch (Exception $e) {
            Log::warning('Orange Money : vérification après avis impossible pour ' . $ligne->order_id . ' : ' . $e->getMessage());
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Redemande le statut réel à Orange et enregistre le paiement si SUCCESS.
     * Renvoie le statut connu (SUCCESS, FAILED, EXPIRED, PENDING, INITIATED).
     */
    public function verifier(string $commande): string
    {
        $ligne = DB::table(self::TABLE)->where('order_id', $commande)->first();
        if (!$ligne) {
            return 'INCONNU';
        }
        if ($ligne->status === 'SUCCESS') {
            return 'SUCCESS'; // déjà enregistré : jamais deux fois
        }

        if ($ligne->mode === 'simulation') {
            $statut = $ligne->sim_status ?: 'INITIATED';
            $txn = $statut === 'SUCCESS' ? 'SIM-' . $commande : null;
        } else {
            $r = $this->orange('/transactionstatus', [
                'order_id' => $ligne->order_id,
                'amount' => (int) $ligne->amount,
                'pay_token' => $ligne->pay_token,
            ]);
            $statut = strtoupper((string) ($r['status'] ?? 'PENDING'));
            $txn = $r['txnid'] ?? null;
            if (($r['order_id'] ?? $ligne->order_id) !== $ligne->order_id) {
                Log::warning('Orange Money : statut reçu pour une autre commande, ignoré');

                return $ligne->status;
            }
        }

        if ($statut === 'SUCCESS') {
            $this->enregistrer($ligne, $txn ?: $ligne->order_id);
        } else {
            DB::table(self::TABLE)->where('id', $ligne->id)->update(['status' => $statut, 'updated_at' => now()]);
        }

        return $statut;
    }

    private function enregistrer(object $ligne, string $txn): void
    {
        DB::transaction(function () use ($ligne, $txn) {
            $frais = DB::table(self::TABLE)->where('id', $ligne->id)->lockForUpdate()->first();
            if ($frais->status === 'SUCCESS') {
                return;
            }
            if (DB::table(self::TABLE)->where('txnid', $txn)->where('id', '!=', $ligne->id)->exists()) {
                Log::warning('Orange Money : transaction déjà utilisée, ignorée');

                return;
            }
            $facture = Invoice::find($ligne->invoice_id);
            if (!$facture) {
                return;
            }
            // Le montant payé doit couvrir ce qui reste dû sur la facture (en ariary entiers)
            if ((int) $frais->amount < (int) round($facture->remaining)) {
                Log::warning('Orange Money : montant insuffisant pour la facture ' . $facture->id . ', paiement non enregistré');
                DB::table(self::TABLE)->where('id', $ligne->id)->update(['status' => 'MONTANT', 'txnid' => $txn, 'updated_at' => now()]);

                return;
            }
            DB::table(self::TABLE)->where('id', $ligne->id)->update(['status' => 'SUCCESS', 'txnid' => $txn, 'verified_at' => now(), 'updated_at' => now()]);
            ExtensionHelper::addPayment($facture->id, 'OrangeMoney', (int) $frais->amount, transactionId: 'OM-' . $txn);
        });
    }

    /** Rattrapage : redemande le statut des paiements en attente de moins de 48 h. */
    public function rattraper(): int
    {
        $n = 0;
        DB::table(self::TABLE)->whereIn('status', ['INITIATED', 'PENDING'])->where('created_at', '>', now()->subHours(48))
            ->orderBy('id')->get()->each(function ($l) use (&$n) {
                try {
                    if ((new self)->verifier($l->order_id) === 'SUCCESS') {
                        $n++;
                    }
                } catch (Exception $e) {
                    Log::notice('Orange Money : rattrapage impossible pour ' . $l->order_id);
                }
            });

        return $n;
    }

    /* ------------------------------------------------------------------ pages */

    /** Page de retour : n'enregistre rien, attend la vérification et se met à jour seule. */
    public function retour(Request $request, string $commande)
    {
        $ligne = DB::table(self::TABLE)->where('order_id', $commande)->first();
        abort_unless($ligne, 404);
        $statut = $ligne->status;
        if (!in_array($statut, ['SUCCESS', 'FAILED', 'EXPIRED'])) {
            try {
                $statut = (new self)->verifier($commande);
            } catch (Exception $e) {
                $statut = 'PENDING';
            }
        }
        if ($request->wantsJson()) {
            return response()->json(['statut' => $statut]);
        }
        $facture = route('invoices.show', $ligne->invoice_id);

        return response(Pages::retour($statut, $request->boolean('annule'), $facture, $request->url()))
            ->header('Cache-Control', 'no-store');
    }

    /** Page qui remplace Orange en mode simulation (jamais disponible dans les autres modes). */
    public function simulation(Request $request, string $commande)
    {
        abort_unless((new self)->mode() === 'simulation', 404);
        $ligne = DB::table(self::TABLE)->where('order_id', $commande)->where('mode', 'simulation')->first();
        abort_unless($ligne, 404);
        if ($request->isMethod('post')) {
            $choix = $request->input('choix') === 'payer' ? 'SUCCESS' : 'FAILED';
            DB::table(self::TABLE)->where('id', $ligne->id)->update(['sim_status' => $choix, 'updated_at' => now()]);
            // Comme Orange : un avis arrive au serveur, qui vérifie ensuite le statut
            (new self)->verifier($commande);

            return redirect('/extensions/gateways/orangemoney/retour/' . $commande . ($choix === 'FAILED' ? '?annule=1' : ''));
        }

        return response(Pages::simulation($ligne, csrf_token()))->header('Cache-Control', 'no-store');
    }
}
