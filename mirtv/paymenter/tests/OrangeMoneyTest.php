<?php

namespace Tests\Feature\MirTV;

use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Gateways\OrangeMoney\OrangeMoney;
use Tests\TestCase;

/**
 * Paiement Orange Money de MirTV : simulation, sandbox (faux serveur Orange), sécurité.
 * Aucune vraie donnée, aucun vrai appel à Orange.
 */
class OrangeMoneyTest extends TestCase
{
    use RefreshDatabase;

    private Gateway $passerelle;

    private string $secrets;

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionHelper::runMigrations('extensions/Gateways/OrangeMoney/database/migrations');
        Currency::firstOrCreate(['code' => 'MGA'], ['name' => 'Ariary', 'suffix' => ' Ar', 'format' => '1 000,00']);
        $this->passerelle = Gateway::create(['name' => 'Orange Money', 'extension' => 'OrangeMoney', 'type' => 'gateway']);
        $this->reglages(['mode' => 'simulation']);
        // En vrai, les routes sont chargées au démarrage quand la passerelle existe ; ici on la crée après
        require base_path('extensions/Gateways/OrangeMoney/routes.php');
        app('router')->getRoutes()->refreshNameLookups();
        // Fausses clés de test (jamais de vraies clés dans les tests)
        $this->secrets = sys_get_temp_dir() . '/mirtv-test-secrets';
        @mkdir($this->secrets, 0700, true);
        foreach (['orange_client_id' => 'id-test', 'orange_client_secret' => 'secret-test', 'orange_merchant_key' => 'marchand-test'] as $k => $v) {
            file_put_contents($this->secrets . '/' . $k, $v);
        }
        putenv('MIRTV_SECRETS_DIR=' . $this->secrets);
    }

    private function reglages(array $valeurs): void
    {
        $defaut = ['mode' => 'simulation', 'pays' => 'mg', 'pays_sandbox' => 'dev', 'url_api' => 'https://api.orange.test', 'monnaie_sandbox' => 'OUV', 'url_publique' => 'https://compte.test'];
        $this->passerelle->settings()->delete();
        foreach (array_merge($defaut, $valeurs) as $k => $v) {
            $this->passerelle->settings()->create(['key' => $k, 'value' => $v]);
        }
        $this->passerelle->refresh();
    }

    private function facture(float $montant = 15000): Invoice
    {
        $u = User::factory()->create();
        $f = Invoice::factory()->create(['user_id' => $u->id, 'status' => 'pending', 'currency_code' => 'MGA']);
        $f->items()->create(['description' => 'MirTV — Mois (TEST)', 'quantity' => 1, 'price' => $montant]);

        return $f->fresh();
    }

    private function fauxOrange(string $statut = 'SUCCESS'): void
    {
        Http::fake([
            'api.orange.test/oauth/v3/token' => Http::response(['access_token' => 'jeton-test', 'expires_in' => 3600]),
            'api.orange.test/orange-money-webpay/*/v1/webpayment' => Http::response(['status' => 201, 'pay_token' => 'pt-123', 'payment_url' => 'https://webpay.orange.test/payer/pt-123', 'notif_token' => 'nt-secret'], 201),
            'api.orange.test/orange-money-webpay/*/v1/transactionstatus' => Http::response(['status' => $statut, 'txnid' => 'MP2611170001', 'order_id' => null]),
        ]);
    }

    public function test_simulation_paiement_reussi_une_seule_fois(): void
    {
        $f = $this->facture();
        $url = ExtensionHelper::pay($this->passerelle, $f);
        $this->assertStringContainsString('/extensions/gateways/orangemoney/simulation/MIR-' . $f->id . '-1-', $url);
        $chemin = parse_url($url, PHP_URL_PATH);
        $this->get($chemin)->assertOk()->assertSee('SIMULATION')->assertSee('15 000 Ar');
        $this->post($chemin, ['choix' => 'payer'])->assertRedirect();
        $this->assertSame('paid', $f->fresh()->status);
        $this->assertSame(1, $f->fresh()->transactions()->count());
        // Rejouer l'avis ou la page : jamais deux paiements
        $this->post($chemin, ['choix' => 'payer']);
        $commande = DB::table(OrangeMoney::TABLE)->value('order_id');
        (new OrangeMoney)->verifier($commande);
        $this->assertSame(1, $f->fresh()->transactions()->count());
    }

    public function test_simulation_refus_ne_paie_pas(): void
    {
        $f = $this->facture();
        $chemin = parse_url(ExtensionHelper::pay($this->passerelle, $f), PHP_URL_PATH);
        $this->post($chemin, ['choix' => 'refuser'])->assertRedirect();
        $this->assertSame('pending', $f->fresh()->status);
        $commande = DB::table(OrangeMoney::TABLE)->value('order_id');
        $this->get('/extensions/gateways/orangemoney/retour/' . $commande . '?annule=1')->assertOk()->assertSee("Le paiement n'est pas fait.", false);
    }

    public function test_page_simulation_fermee_hors_simulation(): void
    {
        $f = $this->facture();
        $chemin = parse_url(ExtensionHelper::pay($this->passerelle, $f), PHP_URL_PATH);
        $this->reglages(['mode' => 'sandbox']);
        $this->get($chemin)->assertNotFound();
    }

    public function test_sandbox_avis_puis_verification_aupres_d_orange(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        $this->fauxOrange('SUCCESS');
        $f = $this->facture();
        $url = ExtensionHelper::pay($this->passerelle, $f);
        $this->assertSame('https://webpay.orange.test/payer/pt-123', $url);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/orange-money-webpay/dev/v1/webpayment')
            && $r['currency'] === 'OUV' && $r['amount'] === 15000 && $r['merchant_key'] === 'marchand-test'
            && $r['notif_url'] === 'https://compte.test/extensions/gateways/orangemoney/avis');
        // Avis avec un mauvais jeton : ignoré, réponse 200
        $this->postJson('/extensions/gateways/orangemoney/avis', ['status' => 'SUCCESS', 'notif_token' => 'faux', 'txnid' => 'X'])->assertOk();
        $this->assertSame('pending', $f->fresh()->status);
        // Bon jeton : le statut est redemandé à Orange avant d'enregistrer
        $this->postJson('/extensions/gateways/orangemoney/avis', ['status' => 'SUCCESS', 'notif_token' => 'nt-secret', 'txnid' => 'MP2611170001'])->assertOk();
        Http::assertSent(fn ($r) => str_contains($r->url(), 'transactionstatus') && $r['pay_token'] === 'pt-123');
        $this->assertSame('paid', $f->fresh()->status);
        // Avis en double : toujours un seul paiement
        $this->postJson('/extensions/gateways/orangemoney/avis', ['status' => 'SUCCESS', 'notif_token' => 'nt-secret', 'txnid' => 'MP2611170001'])->assertOk();
        $this->assertSame(1, $f->fresh()->transactions()->count());
    }

    public function test_avis_qui_ment_ne_suffit_pas(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        $this->fauxOrange('FAILED');
        $f = $this->facture();
        ExtensionHelper::pay($this->passerelle, $f);
        $this->postJson('/extensions/gateways/orangemoney/avis', ['status' => 'SUCCESS', 'notif_token' => 'nt-secret', 'txnid' => 'MP1'])->assertOk();
        $this->assertSame('pending', $f->fresh()->status);
    }

    public function test_page_de_retour_ne_valide_jamais_seule(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        $this->fauxOrange('PENDING');
        $f = $this->facture();
        ExtensionHelper::pay($this->passerelle, $f);
        $commande = DB::table(OrangeMoney::TABLE)->value('order_id');
        $this->get('/extensions/gateways/orangemoney/retour/' . $commande)->assertOk()->assertSee('Paiement en cours de vérification');
        $this->assertSame('pending', $f->fresh()->status);
    }

    public function test_rattrapage_d_un_avis_perdu(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        $this->fauxOrange('SUCCESS');
        $f = $this->facture();
        ExtensionHelper::pay($this->passerelle, $f);
        $this->assertSame('pending', $f->fresh()->status); // l'avis n'est jamais arrivé
        $this->assertSame(1, (new OrangeMoney)->rattraper());
        $this->assertSame('paid', $f->fresh()->status);
    }

    public function test_montant_insuffisant_refuse(): void
    {
        $f = $this->facture(15000);
        $chemin = parse_url(ExtensionHelper::pay($this->passerelle, $f), PHP_URL_PATH);
        // La facture augmente après la création du paiement : le montant payé ne suffit plus
        $f->items()->first()->update(['price' => 40000]);
        $this->post($chemin, ['choix' => 'payer']);
        $this->assertSame('pending', $f->fresh()->status);
        $this->assertSame('MONTANT', DB::table(OrangeMoney::TABLE)->value('status'));
    }

    public function test_cles_absentes_message_clair(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        putenv('MIRTV_SECRETS_DIR=/nulle-part');
        $this->expectExceptionMessage('saisir-secret-paiement.sh');
        ExtensionHelper::pay($this->passerelle, $this->facture());
    }

    public function test_aucune_cle_dans_les_journaux(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        $this->fauxOrange('SUCCESS');
        $f = $this->facture();
        ExtensionHelper::pay($this->passerelle, $f);
        $this->postJson('/extensions/gateways/orangemoney/avis', ['status' => 'SUCCESS', 'notif_token' => 'nt-secret', 'txnid' => 'MP2611170001']);
        $journal = @file_get_contents(storage_path('logs/laravel.log')) . implode('', array_map('file_get_contents', glob(storage_path('logs/*.log')) ?: []));
        foreach (['secret-test', 'marchand-test', 'jeton-test'] as $cle) {
            $this->assertStringNotContainsString($cle, $journal);
        }
    }

    public function test_tester_les_cles(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        $this->fauxOrange('SUCCESS');
        $om = new OrangeMoney($this->passerelle->settings->pluck('value', 'key')->toArray());
        $this->assertSame('ok', $om->tester());
    }

    public function test_tester_des_cles_refusees(): void
    {
        $this->reglages(['mode' => 'sandbox']);
        Http::fake(['api.orange.test/oauth/v3/token' => Http::response(['error' => 'invalid_client'], 401)]);
        $om = new OrangeMoney($this->passerelle->settings->pluck('value', 'key')->toArray());
        $this->assertStringContainsString('401', $om->tester());
    }
}
