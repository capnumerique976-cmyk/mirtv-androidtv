<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use App\Models\Invoice;
use App\Models\Setting;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;
use Paymenter\Extensions\Servers\MirTV\Reglages;

/**
 * Facturation : qui facture (nom, adresse, NIF, STAT…), numérotation des factures, factures numérotées
 * seulement une fois payées. Le bloc « vendeur » des factures PDF est construit à partir de ces champs.
 */
class Facturation extends PageMirTV implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mirtv/facturation';

    protected static ?string $title = 'Facturation';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-file-text-line';

    protected static ?int $navigationSort = 5;

    public ?array $data = [];

    /** Champs « vendeur » imprimés sur les factures, dans cet ordre. */
    public const CHAMPS = [
        'facture_nom' => ['Nom de l\'entreprise (comme sur les papiers officiels)', 'RAHISS'],
        'facture_adresse' => ['Adresse', ''],
        'facture_ville' => ['Ville et pays', ''],
        'facture_nif' => ['NIF', ''],
        'facture_stat' => ['STAT', ''],
        'facture_rcs' => ['RCS (facultatif)', ''],
        'facture_telephone' => ['Téléphone', ''],
        'facture_email' => ['E-mail', ''],
        'facture_mention' => ['Mention en bas du bloc (facultatif)', ''],
    ];

    private static function reglage(string $cle, $defaut = null)
    {
        $v = Setting::whereNull('settingable_type')->where('key', $cle)->first()?->value;

        return ($v === null || $v === '') ? $defaut : $v;
    }

    private static function ecrireReglage(string $cle, $valeur, string $type = 'string'): void
    {
        Setting::updateOrCreate(['key' => $cle, 'settingable_type' => null, 'settingable_id' => null], ['value' => $valeur, 'type' => $type]);
    }

    /** Texte du bloc vendeur à partir des champs. */
    public static function blocVendeur(array $d): string
    {
        $l = [];
        foreach (['facture_nom', 'facture_adresse', 'facture_ville'] as $k) {
            if (filled($d[$k] ?? null)) {
                $l[] = trim($d[$k]);
            }
        }
        $ids = array_filter([
            filled($d['facture_nif'] ?? null) ? 'NIF : ' . trim($d['facture_nif']) : null,
            filled($d['facture_stat'] ?? null) ? 'STAT : ' . trim($d['facture_stat']) : null,
            filled($d['facture_rcs'] ?? null) ? 'RCS : ' . trim($d['facture_rcs']) : null,
        ]);
        if ($ids) {
            $l[] = implode(' — ', $ids);
        }
        $contact = array_filter([trim((string) ($d['facture_telephone'] ?? '')), trim((string) ($d['facture_email'] ?? ''))]);
        if ($contact) {
            $l[] = implode(' — ', $contact);
        }
        if (filled($d['facture_mention'] ?? null)) {
            $l[] = trim($d['facture_mention']);
        }

        return implode("\n", $l);
    }

    public static function infosCompletes(): bool
    {
        return filled(Reglages::mirtv('facture_nif')) && filled(Reglages::mirtv('facture_stat')) && filled(Reglages::mirtv('facture_adresse'));
    }

    public function mount(): void
    {
        $d = [];
        foreach (self::CHAMPS as $k => [, $defaut]) {
            $d[$k] = Reglages::mirtv($k, $defaut);
        }
        $d['invoice_number_format'] = self::reglage('invoice_number_format', 'MIRTV-{year}-{number}');
        $d['invoice_number_padding'] = (int) self::reglage('invoice_number_padding', 4);
        $d['invoice_number'] = (int) self::reglage('invoice_number', 1);
        $d['invoice_proforma'] = (bool) self::reglage('invoice_proforma', true);
        $this->form->fill($d);
    }

    public function form(Schema $schema): Schema
    {
        $champs = [];
        foreach (self::CHAMPS as $k => [$libelle]) {
            $c = TextInput::make($k)->label($libelle)->maxLength(150)->live(onBlur: true);
            if ($k === 'facture_email') {
                $c->email();
            }
            if ($k === 'facture_mention') {
                $c->placeholder('Ex. : TVA non applicable')->columnSpanFull();
            }
            $champs[] = $c;
        }

        return $schema->components([
            Section::make('Qui facture ?')
                ->description('Ces informations sont imprimées sur chaque facture (bloc en haut à droite). Remplissez-les comme sur vos papiers officiels.')
                ->icon('ri-building-line')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema($champs),
                    Text::make(fn (Get $get) => 'Aperçu sur la facture : ' . str_replace("\n", ' · ', self::blocVendeur([
                        'facture_nom' => $get('facture_nom'), 'facture_adresse' => $get('facture_adresse'), 'facture_ville' => $get('facture_ville'),
                        'facture_nif' => $get('facture_nif'), 'facture_stat' => $get('facture_stat'), 'facture_rcs' => $get('facture_rcs'),
                        'facture_telephone' => $get('facture_telephone'), 'facture_email' => $get('facture_email'), 'facture_mention' => $get('facture_mention'),
                    ])))->color('gray'),
                ]),
            Section::make('Numéro des factures')
                ->icon('ri-hashtag')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        TextInput::make('invoice_number_format')
                            ->label('Modèle du numéro')
                            ->helperText('{year} = année, {month} = mois, {number} = numéro (obligatoire).')
                            ->required()
                            ->regex('/\{number\}/')
                            ->validationMessages(['regex' => 'Le modèle doit contenir {number}.'])
                            ->live(onBlur: true),
                        TextInput::make('invoice_number_padding')->label('Chiffres du numéro')->numeric()->integer()->minValue(1)->maxValue(8)->required()->live(onBlur: true),
                        TextInput::make('invoice_number')->label('Prochain numéro')->numeric()->integer()->minValue(1)->required()->helperText('Ne le changez que pour continuer une numérotation existante.')->live(onBlur: true),
                    ]),
                    Text::make(function (Get $get) {
                        $n = str_pad((string) (int) $get('invoice_number'), max(1, (int) $get('invoice_number_padding')), '0', STR_PAD_LEFT);

                        return 'Prochaine facture : ' . str_replace(['{year}', '{month}', '{day}', '{number}'], [now()->format('Y'), now()->format('m'), now()->format('d'), $n], (string) $get('invoice_number_format'));
                    })->color('gray'),
                    Toggle::make('invoice_proforma')
                        ->label('Ne numéroter que les factures payées (conseillé)')
                        ->helperText('Une facture de renouvellement jamais payée garde le titre « facture proforma » sans numéro : les numéros se suivent sans trou.'),
                ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        $derniere = Invoice::query()->latest('id')->first();

        return $schema->components([
            $this->retour(),
            $this->formulaire(array_filter([
                $derniere ? Action::make('voir')
                    ->label('Voir la dernière facture')
                    ->icon('ri-file-pdf-2-line')
                    ->color('gray')
                    ->url('/admin/invoice/invoices/' . $derniere->id) : null,
                Action::make('logo')
                    ->label('Logo et e-mails (réglages du portail)')
                    ->icon('ri-image-line')
                    ->color('gray')
                    ->url('/admin/settings'),
            ])),
        ]);
    }

    public function save(): void
    {
        $this->autoriserModification();
        $d = $this->form->getState();
        $parts = [];
        foreach (array_keys(self::CHAMPS) as $k) {
            $parts[$k] = trim((string) ($d[$k] ?? ''));
        }
        Reglages::ecrireMirtv($parts);
        self::ecrireReglage('bill_to_text', self::blocVendeur($parts));
        self::ecrireReglage('invoice_number_format', trim((string) $d['invoice_number_format']));
        self::ecrireReglage('invoice_number_padding', (int) $d['invoice_number_padding'], 'integer');
        self::ecrireReglage('invoice_number', (int) $d['invoice_number'], 'integer');
        self::ecrireReglage('invoice_proforma', (bool) $d['invoice_proforma'], 'boolean');
        Cache::forget('settings');
        $this->ok('Facturation enregistrée', 'Les prochaines factures porteront ces informations.');
    }
}
