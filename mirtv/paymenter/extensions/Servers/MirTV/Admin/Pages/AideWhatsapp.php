<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;
use Paymenter\Extensions\Servers\MirTV\Reglages;

/** Numéro WhatsApp de l'aide et informations de contact (affichés dans l'appli et sur la page de paiement). */
class AideWhatsapp extends PageMirTV implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mirtv/aide-whatsapp';

    protected static ?string $title = 'Numéro WhatsApp et aide';

    protected static ?string $navigationLabel = 'WhatsApp et aide';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-whatsapp-line';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public function mount(): void
    {
        [$ind, $local] = Reglages::decouper(Reglages::whatsapp());
        $this->form->fill([
            'indicatif' => $ind,
            'numero' => $local === '' ? '' : trim(substr(Reglages::lisible($ind . $local), strlen('+' . $ind))),
            'whatsapp_message' => Reglages::mirtv('whatsapp_message', 'Bonjour MirTV, j\'ai besoin d\'aide.'),
            'aide_horaires' => Reglages::mirtv('aide_horaires', ''),
            'aide_langues' => Reglages::mirtv('aide_langues', 'français, malgache'),
            'email_aide' => Reglages::mirtv('email_aide', ''),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Numéro WhatsApp de l\'aide')
                ->description('Ce numéro apparaît dans l\'appli MirTV (écran « Aide » et écran « abonnement en pause ») et sur la page de paiement. Les clients cliquent dessus pour vous écrire.')
                ->icon('ri-whatsapp-line')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        Select::make('indicatif')
                            ->label('Pays')
                            ->options(Reglages::INDICATIFS)
                            ->default('261')
                            ->selectablePlaceholder(false)
                            ->live(),
                        TextInput::make('numero')
                            ->label('Numéro WhatsApp')
                            ->placeholder('34 12 345 67')
                            ->helperText('Sans l\'indicatif ni le 0 du début. Laissez vide pour ne pas afficher d\'aide WhatsApp.')
                            ->tel()
                            ->maxLength(20)
                            ->regex('/^[0-9 .\-]*$/')
                            ->validationMessages(['regex' => 'Seulement des chiffres (les espaces sont acceptés).'])
                            ->live(onBlur: true)
                            ->columnSpan(['md' => 2]),
                    ]),
                    Text::make(function (Get $get) {
                        $n = Reglages::assembler((string) $get('indicatif'), (string) $get('numero'));

                        return $n === '' ? 'Aucun numéro : le bouton WhatsApp est caché dans l\'appli.' : 'Les clients verront : ' . Reglages::lisible($n) . '  (lien https://wa.me/' . $n . ')';
                    })->color('gray'),
                    Textarea::make('whatsapp_message')
                        ->label('Message déjà écrit quand le client ouvre WhatsApp')
                        ->helperText('Le client peut le modifier avant d\'envoyer. Laissez vide pour une conversation vierge.')
                        ->rows(2)
                        ->maxLength(300),
                ]),
            Section::make('Autres informations d\'aide')
                ->description('Affichées sous le numéro dans l\'écran « Aide » de l\'appli.')
                ->icon('ri-customer-service-2-line')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make('aide_horaires')->label('Horaires de l\'aide')->placeholder('Tous les jours de 8 h à 20 h')->maxLength(100),
                        TextInput::make('aide_langues')->label('Langues parlées')->placeholder('français, malgache')->maxLength(100),
                        TextInput::make('email_aide')->label('E-mail de l\'aide (facultatif)')->email()->maxLength(150),
                    ]),
                ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->formulaire([
                Action::make('tester')
                    ->label('Essayer le lien WhatsApp')
                    ->icon('ri-external-link-line')
                    ->color('success')
                    ->visible(fn () => Reglages::whatsapp() !== '')
                    ->url(fn () => Reglages::lienWhatsapp(), shouldOpenInNewTab: true),
            ]),
        ]);
    }

    public function save(): void
    {
        $this->autoriserModification();
        $d = $this->form->getState();
        $numero = Reglages::assembler((string) ($d['indicatif'] ?? '261'), (string) ($d['numero'] ?? ''));
        [, $local] = Reglages::decouper($numero);
        if ($numero !== '' && (strlen($local) < 6 || strlen($local) > 12)) {
            $this->erreur('Numéro incomplet', 'Vérifiez le numéro : ' . Reglages::lisible($numero));

            return;
        }
        Reglages::ecrireMirtv([
            'whatsapp' => $numero,
            'whatsapp_message' => trim((string) ($d['whatsapp_message'] ?? '')),
            'aide_horaires' => trim((string) ($d['aide_horaires'] ?? '')),
            'aide_langues' => trim((string) ($d['aide_langues'] ?? '')),
            'email_aide' => trim((string) ($d['email_aide'] ?? '')),
        ]);
        $this->ok('Enregistré', $numero === '' ? 'Aucun numéro WhatsApp : le bouton est caché.' : 'Numéro WhatsApp : ' . Reglages::lisible($numero) . '. L\'appli le prend en compte dans la minute.');
    }
}
