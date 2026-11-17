<?php

namespace Tests\Feature\MirTV;

use App\Helpers\ExtensionHelper;
use App\Models\Currency;
use App\Models\Gateway;
use App\Models\Plan;
use App\Models\Price;
use App\Models\Product;
use App\Models\Server;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Paymenter\Extensions\Servers\MirTV\MirTV;
use Tests\TestCase;

/**
 * Comptes MirTV : création de la famille dans Jellyfin (faux Jellyfin), pause, réactivation,
 * page de paiement, état pour l'appli, code d'activation. Données fictives seulement.
 */
class MirTVComptesTest extends TestCase
{
    use RefreshDatabase;

    private array $crees = [];

    private array $politiques = [];

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionHelper::runMigrations('extensions/Servers/MirTV/database/migrations');
        ExtensionHelper::runMigrations('extensions/Gateways/OrangeMoney/database/migrations');
        require base_path('extensions/Servers/MirTV/routes.php');
        require base_path('extensions/Gateways/OrangeMoney/routes.php');
        app('router')->getRoutes()->refreshNameLookups();
        $dossier = sys_get_temp_dir() . '/mirtv-test-secrets';
        @mkdir($dossier, 0700, true);
        file_put_contents($dossier . '/jellyfin_api_key', 'cle-jellyfin-test');
        putenv('MIRTV_SECRETS_DIR=' . $dossier);
        putenv('MIRTV_LIMITES_FICHIER=' . sys_get_temp_dir() . '/mirtv-limites-test.json');
        Currency::firstOrCreate(['code' => 'MGA'], ['name' => 'Ariary', 'suffix' => ' Ar', 'format' => '1 000,00']);
        $this->fauxJellyfin();
    }

    /** Faux Jellyfin : deux comptes modèles, création de profils, politiques, sessions, connexion. */
    private function fauxJellyfin(): void
    {
        $this->crees = [];
        $this->politiques = [
            'u-adulte' => ['IsDisabled' => false, 'EnableAllFolders' => false, 'EnabledFolders' => ['films', 'enfants'], 'AccessSchedules' => []],
            'u-enfant' => ['IsDisabled' => false, 'EnableAllFolders' => false, 'EnabledFolders' => ['enfants'], 'AccessSchedules' => [['DayOfWeek' => 'Everyday', 'StartHour' => 7, 'EndHour' => 19.5, 'UserId' => 'u-enfant']]],
        ];
        Http::fake(function ($req) {
            $chemin = parse_url($req->url(), PHP_URL_PATH);
            $m = $req->method();
            if ($chemin === '/Users' && $m === 'GET') {
                $l = [['Id' => 'u-adulte', 'Name' => 'f901-parent'], ['Id' => 'u-enfant', 'Name' => 'f901-lova']];
                foreach ($this->crees as $id => $nom) {
                    $l[] = ['Id' => $id, 'Name' => $nom];
                }

                return Http::response($l);
            }
            if ($chemin === '/Users/New') {
                $id = 'u-' . count($this->crees);
                $this->crees[$id] = $req['Name'];
                $this->politiques[$id] = [];

                return Http::response(['Id' => $id, 'Name' => $req['Name']]);
            }
            if (preg_match('#^/Users/([^/]+)/Policy$#', $chemin, $x)) {
                $this->politiques[$x[1]] = $req->data();

                return Http::response(null, 204);
            }
            if ($chemin === '/Users/AuthenticateByName') {
                return Http::response(['AccessToken' => 'jeton-' . str_replace('-', '', explode('-', $req['Username'])[0]) . '-' . $req['Username'], 'User' => ['Id' => 'x', 'Name' => $req['Username']]]);
            }
            if ($chemin === '/Users/Me') {
                return str_contains($req->header('Authorization')[0] ?? '', 'jeton-f1001') ? Http::response(['Id' => 'u-0', 'Name' => 'f1001-parent']) : Http::response(null, 401);
            }
            if (preg_match('#^/Users/([^/]+)$#', $chemin, $x)) {
                return Http::response(['Id' => $x[1], 'Policy' => $this->politiques[$x[1]] ?? []]);
            }
            if ($chemin === '/Sessions') {
                return Http::response([]);
            }

            return Http::response(['inattendu' => $chemin], 500);
        });
    }

    private function serviceMirTV(): Service
    {
        $serveur = Server::create(['name' => 'MirTV', 'extension' => 'MirTV', 'type' => 'server']);
        foreach (['url_jellyfin' => 'http://jellyfin.test', 'url_publique' => 'https://compte.test', 'url_appli' => 'https://mirtv.test/tv/', 'modele_adulte' => 'f901-parent', 'modele_enfant' => 'f901-lova', 'premier_numero' => 1001, 'whatsapp' => '261340000000'] as $k => $v) {
            $serveur->settings()->create(['key' => $k, 'value' => $v]);
        }
        $p = Product::factory()->create(['name' => 'MirTV Famille', 'server_id' => $serveur->id]);
        $p->settings()->create(['key' => 'ecrans', 'value' => 2]);
        $p->settings()->create(['key' => 'profils_enfants', 'value' => 2]);
        $prix = ['Semaine (TEST)' => [1, 'week', 5000], 'Mois (TEST)' => [1, 'month', 15000]];
        $plans = [];
        foreach ($prix as $nom => [$n, $unite, $montant]) {
            $plan = $p->plans()->create(['name' => $nom, 'billing_period' => $n, 'billing_unit' => $unite, 'type' => 'recurring', 'sort' => count($plans)]);
            Price::create(['plan_id' => $plan->id, 'price' => $montant, 'currency_code' => 'MGA']);
            $plans[] = $plan;
        }
        $u = User::factory()->create();

        return Service::factory()->create(['user_id' => $u->id, 'product_id' => $p->id, 'plan_id' => $plans[1]->id, 'price' => 15000, 'currency_code' => 'MGA', 'status' => 'active', 'expires_at' => now()->addDays(20)]);
    }

    public function test_creation_de_la_famille(): void
    {
        $s = $this->serviceMirTV();
        ExtensionHelper::createServer($s);
        $f = MirTV::famille($s);
        $this->assertSame(1001, (int) $f->numero);
        $this->assertSame(['f1001-parent', 'f1001-enfant1', 'f1001-enfant2'], array_values($this->crees));
        // Droits copiés : l'enfant a la plage horaire, avec son propre identifiant
        $idEnfant = array_search('f1001-enfant1', $this->crees);
        $this->assertSame(['enfants'], $this->politiques[$idEnfant]['EnabledFolders']);
        $this->assertSame($idEnfant, $this->politiques[$idEnfant]['AccessSchedules'][0]['UserId']);
        $this->assertFalse($this->politiques[$idEnfant]['IsAdministrator']);
        // Mots de passe jamais en clair dans la base
        $this->assertStringNotContainsString('f1001-parent', $f->profils);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{8}$/', $f->code_activation);
        $this->assertSame(32, strlen($f->jeton_paiement));
        // Commande relancée : pas de seconde famille
        ExtensionHelper::createServer($s);
        $this->assertSame(1, DB::table(MirTV::TABLE)->count());
    }

    public function test_pause_puis_reactivation(): void
    {
        $s = $this->serviceMirTV();
        ExtensionHelper::createServer($s);
        ExtensionHelper::suspendServer($s);
        foreach (array_keys($this->crees) as $id) {
            $this->assertTrue($this->politiques[$id]['IsDisabled']);
        }
        $this->assertTrue($this->politiques['u-adulte']['IsDisabled'] === false); // famille de test 901 jamais touchée
        ExtensionHelper::unsuspendServer($s);
        foreach (array_keys($this->crees) as $id) {
            $this->assertFalse($this->politiques[$id]['IsDisabled']);
        }
    }

    public function test_page_de_paiement_et_choix_de_formule(): void
    {
        $s = $this->serviceMirTV();
        ExtensionHelper::createServer($s);
        $passerelle = Gateway::create(['name' => 'Orange Money', 'extension' => 'OrangeMoney', 'type' => 'gateway']);
        foreach (['mode' => 'simulation', 'pays' => 'mg', 'pays_sandbox' => 'dev', 'url_api' => 'https://api.orange.test', 'monnaie_sandbox' => 'OUV', 'url_publique' => 'https://compte.test'] as $k => $v) {
            $passerelle->settings()->create(['key' => $k, 'value' => $v]);
        }
        $f = MirTV::famille($s);
        $page = $this->get('/payer/' . $f->jeton_paiement)->assertOk();
        $page->assertSee('Famille n° 1001')->assertSee('Semaine (TEST)')->assertSee('15 000 Ar')->assertSee('Payer avec Orange Money');
        $page->assertDontSee($s->user->email);
        $semaine = $s->product->plans()->where('name', 'Semaine (TEST)')->first();
        $r = $this->post('/payer/' . $f->jeton_paiement, ['formule' => $semaine->id]);
        $r->assertRedirect();
        $this->assertStringContainsString('/extensions/gateways/orangemoney/simulation/', $r->headers->get('Location'));
        $this->assertSame((int) $semaine->id, (int) $s->fresh()->plan_id);
        $facture = $s->invoices()->where('status', 'pending')->first();
        $this->assertEquals(5000, $facture->remaining);
        // Paiement simulé → facture payée
        $this->post(parse_url($r->headers->get('Location'), PHP_URL_PATH), ['choix' => 'payer']);
        $this->assertSame('paid', $facture->fresh()->status);
        // Mauvais jeton : rien
        $this->get('/payer/' . str_repeat('0', 32))->assertNotFound();
    }

    public function test_etat_pour_l_appli(): void
    {
        $s = $this->serviceMirTV();
        ExtensionHelper::createServer($s);
        $f = MirTV::famille($s);
        $this->getJson('/abonnement/' . $f->jeton_paiement, ['Origin' => 'https://mirtv.test'])
            ->assertOk()->assertJson(['statut' => 'actif', 'ecrans' => 2, 'famille' => 1001, 'formule' => 'Mois (TEST)'])
            ->assertHeader('Access-Control-Allow-Origin', 'https://mirtv.test');
        $s->update(['status' => 'suspended']);
        $this->getJson('/abonnement/' . $f->jeton_paiement)->assertJson(['statut' => 'en_pause']);
        // Un écran connecté retrouve le lien de paiement de sa famille
        $this->getJson('/mirtv/etat', ['X-MirTV-Jeton' => 'jeton-f1001'])->assertOk()->assertJson(['famille' => 1001, 'jeton' => $f->jeton_paiement]);
        $this->getJson('/mirtv/etat', ['X-MirTV-Jeton' => 'faux'])->assertNotFound();
    }

    public function test_code_d_activation_a_usage_unique(): void
    {
        $s = $this->serviceMirTV();
        ExtensionHelper::createServer($s);
        $f = MirTV::famille($s);
        $r = $this->postJson('/mirtv/activer', ['code' => strtolower(substr($f->code_activation, 0, 4)) . '-' . substr($f->code_activation, 4), 'appareil' => 'Télé du salon'])->assertOk();
        $this->assertCount(3, $r->json('profils'));
        $this->assertSame('f1001-parent', $r->json('profils.0.nom'));
        $this->assertSame($f->jeton_paiement, $r->json('jeton'));
        // Le même code ne marche plus
        $this->postJson('/mirtv/activer', ['code' => $f->code_activation])->assertNotFound();
        // Code expiré refusé
        $nouveau = MirTV::famille($s)->code_activation;
        DB::table(MirTV::TABLE)->update(['code_expire_at' => now()->subMinute()]);
        $this->postJson('/mirtv/activer', ['code' => $nouveau])->assertNotFound();
    }

    public function test_resiliation_puis_suppression_apres_90_jours(): void
    {
        $s = $this->serviceMirTV();
        ExtensionHelper::createServer($s);
        ExtensionHelper::terminateServer($s);
        $this->assertSame('resiliee', MirTV::famille($s)->statut);
        MirTV::nettoyer();
        Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');
        DB::table(MirTV::TABLE)->update(['resiliee_at' => now()->subDays(91)]);
        MirTV::nettoyer();
        Http::assertSent(fn ($r) => $r->method() === 'DELETE');
        $this->assertSame('supprimee', MirTV::famille($s)->statut);
    }

    public function test_actions_du_compte_client(): void
    {
        $s = $this->serviceMirTV();
        ExtensionHelper::createServer($s);
        DB::table(MirTV::TABLE)->update(['code_expire_at' => now()->subMinute()]);
        $actions = ExtensionHelper::getActions($s);
        $this->assertSame('n° 1001', $actions[0]['text']);
        $this->assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{4}-[A-HJ-NP-Z2-9]{4} /', $actions[1]['text']);
        $this->assertTrue(now()->lessThan(MirTV::famille($s)->code_expire_at)); // nouveau code valable
        $this->assertStringContainsString('/payer/', $actions[2]['url']);
    }
}
