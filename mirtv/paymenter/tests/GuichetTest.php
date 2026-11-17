<?php

namespace Tests\Feature\MirTV;

use App\Helpers\ExtensionHelper;
use App\Models\Gateway;
use App\Models\Server;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Gateways\OrangeMoney\OrangeMoney;
use Paymenter\Extensions\Servers\MirTV\Reglages;
use Tests\TestCase;

/**
 * Guichet MirTV : réglages lus et écrits par le back office (WhatsApp, annonce, clés Orange chiffrées),
 * réglages publics lus par l'appli. Données fictives seulement.
 */
class GuichetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ExtensionHelper::runMigrations('extensions/Servers/MirTV/database/migrations');
        ExtensionHelper::runMigrations('extensions/Gateways/OrangeMoney/database/migrations');
        require base_path('extensions/Servers/MirTV/routes.php');
        app('router')->getRoutes()->refreshNameLookups();
        putenv('MIRTV_SECRETS_DIR=' . sys_get_temp_dir() . '/mirtv-guichet-vide');
        $s = Server::create(['name' => 'MirTV', 'extension' => 'MirTV', 'type' => 'server']);
        foreach (['url_jellyfin' => 'http://jellyfin.test:8096', 'url_publique' => 'https://compte.mirtv.test', 'url_appli' => 'https://mirtv.test/tv/'] as $k => $v) {
            $s->settings()->create(['key' => $k, 'value' => $v]);
        }
        $g = Gateway::create(['name' => 'Orange Money', 'extension' => 'OrangeMoney', 'type' => 'gateway']);
        $g->settings()->create(['key' => 'mode', 'value' => 'simulation']);
        Cache::flush();
    }

    public function test_numero_whatsapp_assemble_et_lisible(): void
    {
        $this->assertSame('261341234567', Reglages::assembler('261', '034 12 345 67'));
        $this->assertSame('', Reglages::assembler('261', '  '));
        $this->assertSame('+261 34 12 345 67', Reglages::lisible('261341234567'));
        $this->assertSame('+262 639 12 34 56', Reglages::lisible('262639123456'));
        $this->assertSame(['262', '639123456'], Reglages::decouper('262639123456'));
    }

    public function test_reglages_publics_pour_l_appli(): void
    {
        Reglages::ecrireMirtv(['whatsapp' => '261341234567', 'whatsapp_message' => 'Bonjour MirTV', 'aide_horaires' => '8 h - 20 h']);
        $r = $this->withHeader('Origin', 'https://mirtv.test')->getJson('/mirtv/reglages');
        $r->assertOk()
            ->assertJsonPath('whatsapp', '261341234567')
            ->assertJsonPath('whatsapp_lisible', '+261 34 12 345 67')
            ->assertJsonPath('whatsapp_lien', 'https://wa.me/261341234567?text=Bonjour%20MirTV')
            ->assertJsonPath('aide_horaires', '8 h - 20 h')
            ->assertJsonPath('annonce', null)
            ->assertHeader('Access-Control-Allow-Origin', 'https://mirtv.test');
        $this->assertStringNotContainsString('orange', strtolower($r->getContent()));
    }

    public function test_annonce_visible_seulement_si_active(): void
    {
        Reglages::ecrireMirtv(['annonce' => 'Travaux ce soir', 'annonce_active' => false]);
        $this->getJson('/mirtv/reglages')->assertJsonPath('annonce', null);
        Reglages::ecrireMirtv(['annonce_active' => true, 'annonce_type' => 'alerte']);
        $this->getJson('/mirtv/reglages')->assertJsonPath('annonce.texte', 'Travaux ce soir')->assertJsonPath('annonce.type', 'alerte');
    }

    public function test_cles_orange_chiffrees_et_lues_par_la_passerelle(): void
    {
        $this->assertNull(OrangeMoney::secret('orange_client_id'));
        $this->assertNull(Reglages::origineCle('orange_client_id'));
        $this->assertFalse(Reglages::ecrireCleOrange('orange_client_id', '   '));
        $this->assertTrue(Reglages::ecrireCleOrange('orange_client_id', 'id-fictif-123'));
        $brut = DB::table('settings')->where('key', 'orange_client_id')->value('value');
        $this->assertNotSame('id-fictif-123', $brut);
        $this->assertStringNotContainsString('id-fictif', $brut);
        $this->assertSame('id-fictif-123', OrangeMoney::secret('orange_client_id'));
        $this->assertSame('back office', Reglages::origineCle('orange_client_id'));
        Reglages::effacerCleOrange('orange_client_id');
        $this->assertNull(OrangeMoney::secret('orange_client_id'));
    }

    public function test_cle_du_fichier_serveur_utilisee_si_rien_dans_le_back_office(): void
    {
        $dossier = sys_get_temp_dir() . '/mirtv-guichet-fichier';
        @mkdir($dossier, 0700, true);
        file_put_contents($dossier . '/orange_merchant_key', 'marchand-fichier');
        putenv('MIRTV_SECRETS_DIR=' . $dossier);
        $this->assertSame('marchand-fichier', OrangeMoney::secret('orange_merchant_key'));
        $this->assertSame('fichier du serveur', Reglages::origineCle('orange_merchant_key'));
        Reglages::ecrireCleOrange('orange_merchant_key', 'marchand-back-office');
        $this->assertSame('marchand-back-office', OrangeMoney::secret('orange_merchant_key'));
        @unlink($dossier . '/orange_merchant_key');
    }

    public function test_telephone_client_et_message_de_relance(): void
    {
        $u = \App\Models\User::factory()->create(['first_name' => 'Hery']);
        $this->assertSame('', Reglages::telephoneClient($u));
        $cp = \App\Models\CustomProperty::create(['key' => 'telephone', 'name' => 'Téléphone', 'type' => 'text', 'model' => \App\Models\User::class, 'non_editable' => false, 'required' => true, 'show_on_invoice' => true]);
        foreach (['034 12 345 67' => '261341234567', '+262 639 12 34 56' => '262639123456', '261 34 12 345 67' => '261341234567', '0033 6 12 34 56 78' => '33612345678'] as $saisi => $attendu) {
            $u->properties()->updateOrCreate(['key' => 'telephone'], ['custom_property_id' => $cp->id, 'name' => 'Téléphone', 'value' => $saisi]);
            $this->assertSame($attendu, Reglages::telephoneClient($u->fresh()), $saisi);
        }
        $this->assertSame('Bonjour Hery, famille 1001 : https://x/payer/abc', Reglages::remplir('Bonjour {prenom}, famille {famille} : {lien}', ['prenom' => 'Hery', 'famille' => 1001, 'lien' => 'https://x/payer/abc']));
    }

    public function test_bloc_vendeur_des_factures(): void
    {
        $bloc = \Paymenter\Extensions\Servers\MirTV\Admin\Pages\Facturation::blocVendeur([
            'facture_nom' => 'RAHISS', 'facture_adresse' => 'Lot 1', 'facture_ville' => 'Antananarivo', 'facture_nif' => '123', 'facture_stat' => '456',
            'facture_rcs' => '', 'facture_telephone' => '+261 34 00 000 00', 'facture_email' => '', 'facture_mention' => 'TVA non applicable',
        ]);
        $this->assertSame("RAHISS\nLot 1\nAntananarivo\nNIF : 123 — STAT : 456\n+261 34 00 000 00\nTVA non applicable", $bloc);
    }

    public function test_pages_du_guichet_reservees_aux_administrateurs(): void
    {
        $this->get('/admin/mirtv')->assertRedirect();
    }
}
