<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use App\Models\Setting;
use Exception;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;
use Paymenter\Extensions\Servers\MirTV\MirTV;
use Paymenter\Extensions\Servers\MirTV\Reglages;

/** Message à tous les écrans, nom du portail, réglages techniques de l'appli (adresses, comptes modèles). */
class Appli extends PageMirTV implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mirtv/appli';

    protected static ?string $title = 'Appli MirTV et message aux écrans';

    protected static ?string $navigationLabel = 'Appli et message';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-tv-line';

    protected static ?int $navigationSort = 7;

    public ?array $data = [];

    public const TYPES = [
        'info' => 'Information (bleu)',
        'alerte' => 'Alerte (orange) — panne, travaux',
        'fete' => 'Bonne nouvelle (vert) — nouvelle chaîne, promotion',
    ];

    public function mount(): void
    {
        $this->form->fill([
            'annonce_active' => (bool) Reglages::mirtv('annonce_active', false),
            'annonce' => Reglages::mirtv('annonce', ''),
            'annonce_type' => Reglages::mirtv('annonce_type', 'info'),
            'company_name' => Setting::whereNull('settingable_type')->where('key', 'company_name')->first()?->value ?? 'MirTV',
            'url_appli' => Reglages::mirtv('url_appli', 'https://mirtv.rahiss.com/tv/'),
            'url_publique' => Reglages::mirtv('url_publique', 'https://compte.mirtv.rahiss.com'),
            'url_jellyfin' => Reglages::mirtv('url_jellyfin', 'http://jellyfin:8096'),
            'modele_adulte' => Reglages::mirtv('modele_adulte', 'f901-parent'),
            'modele_enfant' => Reglages::mirtv('modele_enfant', 'f901-lova'),
            'premier_numero' => (int) Reglages::mirtv('premier_numero', 1001),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Message à tous les écrans')
                ->description('Un bandeau en haut de l\'accueil de l\'appli, sur toutes les télés et tous les téléphones (dans la minute). Exemple : « Travaux ce soir de 23 h à minuit ».')
                ->icon('ri-megaphone-line')
                ->schema([
                    Toggle::make('annonce_active')->label('Afficher le message')->live(),
                    Textarea::make('annonce')->label('Message')->rows(2)->maxLength(160)->helperText('160 caractères au plus, court et clair.')->required(fn (Get $get) => (bool) $get('annonce_active')),
                    Select::make('annonce_type')->label('Couleur')->options(self::TYPES)->selectablePlaceholder(false),
                ]),
            Section::make('Nom du portail des comptes')
                ->description('Le nom affiché en haut du site des comptes et des paiements (compte.mirtv.rahiss.com). Logo, e-mails et autres réglages : bouton « Réglages du portail ».')
                ->icon('ri-store-2-line')
                ->schema([
                    TextInput::make('company_name')->label('Nom')->required()->maxLength(60),
                ]),
            Section::make('Réglages techniques')
                ->description('Déjà réglés à l\'installation. À ne changer qu\'en cas de déménagement du site.')
                ->icon('ri-settings-4-line')
                ->collapsed()
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('url_appli')->label('Adresse de l\'appli MirTV')->url()->required(),
                        TextInput::make('url_publique')->label('Adresse du portail des comptes')->url()->required(),
                        TextInput::make('url_jellyfin')->label('Adresse interne de Jellyfin')->url()->required(),
                        TextInput::make('premier_numero')->label('Premier numéro de famille')->numeric()->integer()->minValue(1)->required(),
                        TextInput::make('modele_adulte')->label('Compte modèle adulte (droits copiés)')->required(),
                        TextInput::make('modele_enfant')->label('Compte modèle enfant (droits copiés)')->required(),
                    ]),
                ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->formulaire([
                Action::make('jellyfin')
                    ->label('Tester la liaison avec Jellyfin')
                    ->icon('ri-plug-line')
                    ->color('gray')
                    ->action(function () {
                        try {
                            $n = count(MirTV::instance()->jf()->utilisateurs());
                            $this->ok('Jellyfin répond', $n . ' comptes trouvés.');
                        } catch (Exception $e) {
                            $this->erreur('Jellyfin ne répond pas', $e->getMessage());
                        }
                    }),
                Action::make('portail')
                    ->label('Réglages du portail (logo, e-mails…)')
                    ->icon('ri-settings-3-line')
                    ->color('gray')
                    ->url('/admin/settings'),
            ]),
        ]);
    }

    public function save(): void
    {
        $this->autoriserModification();
        $d = $this->form->getState();
        Reglages::ecrireMirtv([
            'annonce_active' => (bool) $d['annonce_active'],
            'annonce' => trim((string) ($d['annonce'] ?? '')),
            'annonce_type' => array_key_exists($d['annonce_type'] ?? '', self::TYPES) ? $d['annonce_type'] : 'info',
            'url_appli' => trim((string) $d['url_appli']),
            'url_publique' => rtrim(trim((string) $d['url_publique']), '/'),
            'url_jellyfin' => rtrim(trim((string) $d['url_jellyfin']), '/'),
            'modele_adulte' => trim((string) $d['modele_adulte']),
            'modele_enfant' => trim((string) $d['modele_enfant']),
            'premier_numero' => (int) $d['premier_numero'],
        ]);
        Setting::updateOrCreate(['key' => 'company_name', 'settingable_type' => null, 'settingable_id' => null], ['value' => trim((string) $d['company_name'])]);
        Cache::forget('settings');
        $this->ok('Enregistré', $d['annonce_active'] ? 'Le message apparaît sur les écrans dans la minute.' : null);
    }
}
