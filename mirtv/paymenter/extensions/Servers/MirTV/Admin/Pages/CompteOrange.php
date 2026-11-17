<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Gateways\OrangeMoney\OrangeMoney;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;
use Paymenter\Extensions\Servers\MirTV\Reglages;

/**
 * Compte marchand Orange Money : mode (simulation, sandbox, production), clés, test des clés.
 * Les clés sont chiffrées en base, jamais réaffichées (champ vide = clé inchangée).
 */
class CompteOrange extends PageMirTV implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mirtv/orange-money';

    protected static ?string $title = 'Compte marchand Orange Money';

    protected static ?string $navigationLabel = 'Orange Money';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-bank-card-line';

    protected static ?int $navigationSort = 2;

    public ?array $data = [];

    public const MODES = [
        'simulation' => 'Simulation — aucun argent, une page de test remplace Orange',
        'sandbox' => 'Sandbox Orange — test chez Orange avec les clés de test (monnaie OUV, pas de vrai argent)',
        'production' => 'Production — VRAIS paiements des clients',
    ];

    public function mount(): void
    {
        $this->form->fill([
            'mode' => Reglages::orange('mode', 'simulation'),
            'pays' => Reglages::orange('pays', 'mg'),
            'pays_sandbox' => Reglages::orange('pays_sandbox', 'dev'),
            'url_api' => Reglages::orange('url_api', 'https://api.orange.com'),
            'monnaie_sandbox' => Reglages::orange('monnaie_sandbox', 'OUV'),
            'url_publique' => Reglages::orange('url_publique', 'https://compte.mirtv.rahiss.com'),
            'confirmation' => '',
        ]);
    }

    private static function etatCle(string $nom): string
    {
        $o = Reglages::origineCle($nom);

        return $o ? '✓ Enregistrée (' . $o . '). Laissez vide pour la garder.' : 'Pas encore saisie.';
    }

    public function form(Schema $schema): Schema
    {
        $mode = Reglages::orange('mode', 'simulation');
        $base = rtrim((string) Reglages::orange('url_publique', 'https://compte.mirtv.rahiss.com'), '/');

        return $schema->components([
            Callout::make(match ($mode) {
                'production' => 'Mode actuel : PRODUCTION — les clients paient pour de vrai.',
                'sandbox' => 'Mode actuel : SANDBOX — tests chez Orange, pas de vrai argent.',
                default => 'Mode actuel : SIMULATION — aucun argent, une page de test remplace Orange.',
            })
                ->description($mode === 'production' ? 'Pour arrêter les vrais paiements, repassez en simulation.' : 'Les vrais paiements ne commencent que lorsque vous choisissez « Production » et écrivez PRODUCTION pour confirmer.')
                ->color($mode === 'production' ? 'danger' : ($mode === 'sandbox' ? 'warning' : 'info'))
                ->icon($mode === 'production' ? 'ri-alarm-warning-line' : 'ri-information-line'),

            Section::make('1. Les clés données par Orange')
                ->description('Orange les donne quand le compte marchand est ouvert (portail Orange Developer : application « Orange Money Webpay », et votre Merchant Key). Collez-les ici : elles sont chiffrées et ne s\'affichent plus jamais.')
                ->icon('ri-key-2-line')
                ->schema([
                    TextInput::make('orange_client_id')->label(Reglages::CLES_ORANGE['orange_client_id'])->password()->revealable(false)->autocomplete('off')->helperText(self::etatCle('orange_client_id')),
                    TextInput::make('orange_client_secret')->label(Reglages::CLES_ORANGE['orange_client_secret'])->password()->revealable(false)->autocomplete('off')->helperText(self::etatCle('orange_client_secret')),
                    TextInput::make('orange_merchant_key')->label(Reglages::CLES_ORANGE['orange_merchant_key'])->password()->revealable(false)->autocomplete('off')->helperText(self::etatCle('orange_merchant_key')),
                ]),

            Section::make('2. Le mode de paiement')
                ->icon('ri-toggle-line')
                ->schema([
                    Radio::make('mode')->label('Mode')->options(self::MODES)->required()->live(),
                    TextInput::make('confirmation')
                        ->label('Écrivez PRODUCTION pour confirmer les vrais paiements')
                        ->visible(fn (Get $get) => $get('mode') === 'production' && Reglages::orange('mode') !== 'production')
                        ->autocomplete('off'),
                ]),

            Section::make('3. Adresses à donner à Orange')
                ->description('Orange demande souvent ces adresses lors de l\'ouverture du compte (elles sont aussi envoyées automatiquement à chaque paiement).')
                ->icon('ri-links-line')
                ->collapsible()
                ->schema([
                    Text::make('Adresse des avis de paiement (notif_url) : ' . $base . '/extensions/gateways/orangemoney/avis')->copyable(),
                    Text::make('Adresse de retour du client (return_url) : ' . $base . '/extensions/gateways/orangemoney/retour/…')->copyable(),
                    Text::make('Adresse en cas d\'abandon (cancel_url) : ' . $base . '/extensions/gateways/orangemoney/retour/…?annule=1')->copyable(),
                ]),

            Section::make('Réglages techniques')
                ->description('À ne changer que si Orange le demande.')
                ->icon('ri-settings-4-line')
                ->collapsed()
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('pays')->label('Code pays Orange (production)')->helperText('À confirmer par Orange Madagascar (provisoire : mg).')->required()->maxLength(10),
                        TextInput::make('pays_sandbox')->label('Code pays en sandbox')->required()->maxLength(10),
                        TextInput::make('url_api')->label('Adresse de l\'API Orange')->url()->required(),
                        TextInput::make('monnaie_sandbox')->label('Monnaie en sandbox')->required()->maxLength(8),
                        TextInput::make('url_publique')->label('Adresse publique du portail')->url()->required(),
                    ]),
                ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->retour(),
            $this->formulaire([
                Action::make('tester')
                    ->label('Tester les clés')
                    ->icon('ri-plug-line')
                    ->color('gray')
                    ->action(fn () => $this->tester()),
                Action::make('effacer')
                    ->label('Effacer les clés du back office')
                    ->icon('ri-delete-bin-line')
                    ->color('danger')
                    ->outlined()
                    ->requiresConfirmation()
                    ->modalDescription('Les 3 clés saisies ici seront effacées. Les paiements repassent en simulation.')
                    ->action(function () {
                        $this->autoriserModification();
                        foreach (array_keys(Reglages::CLES_ORANGE) as $nom) {
                            Reglages::effacerCleOrange($nom);
                        }
                        Reglages::ecrireOrange(['mode' => 'simulation']);
                        $this->ok('Clés effacées', 'Les paiements sont en simulation.');
                        $this->rediriger();
                    }),
                Action::make('orange')
                    ->label('Portail Orange Developer')
                    ->icon('ri-external-link-line')
                    ->color('gray')
                    ->url('https://developer.orange.com/apis/om-webpay', shouldOpenInNewTab: true),
            ]),
        ]);
    }

    public function save(): void
    {
        $this->autoriserModification();
        $d = $this->form->getState();
        $avant = Reglages::orange('mode', 'simulation');
        $mode = in_array($d['mode'] ?? '', array_keys(self::MODES), true) ? $d['mode'] : 'simulation';

        // Clés : seulement celles qui ont été remplies
        $nouvelles = 0;
        foreach (array_keys(Reglages::CLES_ORANGE) as $nom) {
            if (Reglages::ecrireCleOrange($nom, $d[$nom] ?? null)) {
                $nouvelles++;
            }
        }

        $alertes = [];
        if ($mode !== 'simulation') {
            $manque = array_filter(array_keys(Reglages::CLES_ORANGE), fn ($n) => !Reglages::origineCle($n));
            if ($manque) {
                $mode = $avant === 'production' ? 'production' : 'simulation';
                $alertes[] = 'Mode non changé : il manque des clés Orange (' . implode(', ', array_map(fn ($n) => Reglages::CLES_ORANGE[$n], $manque)) . ').';
            }
        }
        if ($mode === 'production' && $avant !== 'production' && trim((string) ($d['confirmation'] ?? '')) !== 'PRODUCTION') {
            $mode = $avant;
            $alertes[] = 'Production non activée : écrivez PRODUCTION (en majuscules) dans la case de confirmation.';
        }

        Reglages::ecrireOrange([
            'mode' => $mode,
            'pays' => trim((string) $d['pays']),
            'pays_sandbox' => trim((string) $d['pays_sandbox']),
            'url_api' => rtrim(trim((string) $d['url_api']), '/'),
            'monnaie_sandbox' => trim((string) $d['monnaie_sandbox']),
            'url_publique' => rtrim(trim((string) $d['url_publique']), '/'),
        ]);

        $resume = 'Mode : ' . strtoupper($mode) . ($nouvelles ? ' — ' . $nouvelles . ' clé(s) enregistrée(s).' : '.');
        $alertes ? $this->erreur('Enregistré, avec une remarque', implode(' ', $alertes) . ' ' . $resume) : $this->ok('Enregistré', $resume);
        $this->rediriger();
    }

    public function tester(): void
    {
        $g = Reglages::passerelle();
        if (!$g) {
            $this->erreur('Orange Money n\'est pas installé');

            return;
        }
        $r = (new OrangeMoney($g->settings()->get()->pluck('value', 'key')->toArray()))->tester();
        str_starts_with($r, 'ok') ? $this->ok('Test des clés : réussi', $r) : $this->erreur('Test des clés : échec', $r);
    }
}
