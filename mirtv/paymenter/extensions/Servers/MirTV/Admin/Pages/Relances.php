<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\HtmlString;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;
use Paymenter\Extensions\Servers\MirTV\MirTV;
use Paymenter\Extensions\Servers\MirTV\Modeles\Famille;
use Paymenter\Extensions\Servers\MirTV\Reglages;

/**
 * Relances WhatsApp : familles dont l'abonnement se termine bientôt ou est en pause.
 * Un clic ouvre WhatsApp avec le message déjà écrit (prénom, date, lien de paiement de la famille).
 */
class Relances extends PageMirTV implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'mirtv/relances';

    protected static ?string $title = 'Relances WhatsApp';

    protected static ?string $navigationLabel = 'Relances';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-notification-3-line';

    protected static ?int $navigationSort = 8;

    /** Familles à relancer : fin dans $jours jours au plus, ou en pause. */
    public static function aRelancer(int $jours = 3): Builder
    {
        return Famille::query()
            ->whereIn('statut', ['active', 'pause'])
            ->orderBy(\App\Models\Service::select('expires_at')->whereColumn('services.id', 'ext_mirtv_familles.service_id')->limit(1))
            ->whereIn('service_id', self::servicesAEcheance($jours));
    }

    /** Services en pause ou qui se terminent dans $jours jours au plus (sous-requête). */
    private static function servicesAEcheance(int $jours)
    {
        return \App\Models\Service::query()->select('id')->where(fn ($q) => $q->where('status', 'suspended')->orWhere('expires_at', '<=', now()->addDays($jours)->endOfDay()));
    }

    public static function message(Famille $f): string
    {
        $s = $f->service;
        $pause = $f->statut === 'pause';
        $modele = (string) Reglages::mirtv($pause ? 'relance_message_pause' : 'relance_message', $pause ? Reglages::RELANCE_PAUSE : Reglages::RELANCE);
        $prix = $s?->plan?->price($s->currency_code ?: 'MGA')?->price;

        return Reglages::remplir($modele, [
            'prenom' => $s?->user?->first_name ?: 'Madame, Monsieur',
            'famille' => $f->numero,
            'date' => $s?->expires_at ? $s->expires_at->translatedFormat('j F') : '—',
            'formule' => $s?->plan?->name ?? '',
            'prix' => $prix !== null ? number_format((float) $prix, 0, ',', ' ') . ' Ar' : '',
            'lien' => rtrim((string) MirTV::instance()->config('url_publique'), '/') . '/payer/' . DB::table(MirTV::TABLE)->where('id', $f->id)->value('jeton_paiement'),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $n3 = self::aRelancer(3)->count();
        $sansTel = self::aRelancer(7)->with('service.user')->get()->filter(fn ($f) => Reglages::telephoneClient($f->service?->user) === '')->count();

        return $schema->components([
            $this->retour(),
            Html::make('<div style="padding:12px 16px;border-radius:12px;background:rgba(255,122,0,.08);border:1px solid rgba(255,122,0,.25);font-size:14px">'
                . '<b>' . $n3 . '</b> famille(s) à relancer (fin dans 3 jours ou en pause). '
                . 'Cliquez sur « WhatsApp » : la conversation s\'ouvre avec le message déjà écrit et le lien de paiement de la famille ; il ne reste qu\'à appuyer sur « Envoyer ».'
                . ($sansTel ? '<br><span style="color:#ef4444">' . $sansTel . ' famille(s) sans numéro de téléphone : ajoutez-le dans leur fiche client (« Clients »).</span>' : '')
                . '</div>'),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('messages')
                ->label('Modifier les messages')
                ->icon('ri-edit-line')
                ->color('gray')
                ->fillForm(fn () => [
                    'relance_message' => Reglages::mirtv('relance_message', Reglages::RELANCE),
                    'relance_message_pause' => Reglages::mirtv('relance_message_pause', Reglages::RELANCE_PAUSE),
                ])
                ->schema([
                    Textarea::make('relance_message')->label('Fin d\'abonnement proche')->rows(4)->required()->maxLength(600),
                    Textarea::make('relance_message_pause')->label('Abonnement en pause')->rows(4)->required()->maxLength(600),
                ])
                ->modalDescription(new HtmlString('Mots remplacés automatiquement : <b>{prenom}</b>, <b>{famille}</b>, <b>{date}</b>, <b>{formule}</b>, <b>{prix}</b>, <b>{lien}</b> (lien de paiement de la famille).'))
                ->action(function (array $data) {
                    $this->autoriserModification();
                    Reglages::ecrireMirtv(['relance_message' => trim($data['relance_message']), 'relance_message_pause' => trim($data['relance_message_pause'])]);
                    $this->ok('Messages de relance enregistrés');
                }),
            ...parent::getHeaderActions(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(self::aRelancer(7)->with(['service.user', 'service.plan']))
            ->emptyStateHeading('Personne à relancer')
            ->emptyStateDescription('Aucun abonnement ne se termine dans les 7 jours et aucune famille n\'est en pause.')
            ->filters([
                SelectFilter::make('statut')->label('État')->options(['active' => 'Fin dans 7 jours', 'pause' => 'En pause']),
            ])
            ->columns([
                TextColumn::make('numero')->label('N°')->weight('bold'),
                TextColumn::make('client')
                    ->label('Client')
                    ->state(fn (Famille $f) => trim(($f->service?->user?->first_name ?? '') . ' ' . ($f->service?->user?->last_name ?? '')) ?: '—')
                    ->description(fn (Famille $f) => ($t = Reglages::telephoneClient($f->service?->user)) !== '' ? Reglages::lisible($t) : 'pas de numéro'),
                TextColumn::make('formule')
                    ->label('Formule')
                    ->state(fn (Famille $f) => $f->service?->plan?->name ?? '—')
                    ->description(fn (Famille $f) => ($p = $f->service?->plan?->price($f->service->currency_code ?: 'MGA')?->price) !== null ? number_format((float) $p, 0, ',', ' ') . ' Ar' : null),
                TextColumn::make('fin')
                    ->label('Fin')
                    ->state(fn (Famille $f) => $f->service?->expires_at?->format('d/m/Y') ?? '—')
                    ->description(function (Famille $f) {
                        $e = $f->service?->expires_at;
                        if (!$e) {
                            return null;
                        }
                        $j = (int) now()->startOfDay()->diffInDays($e->copy()->startOfDay(), false);

                        return $j > 1 ? 'dans ' . $j . ' jours' : ($j === 1 ? 'demain' : ($j === 0 ? 'aujourd\'hui' : 'dépassée de ' . abs($j) . ' j'));
                    }),
                TextColumn::make('statut')
                    ->label('État')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Familles::STATUTS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'active' ? 'success' : 'warning'),
                TextColumn::make('relance_at')->label('Relancée')->since()->placeholder('jamais'),
            ])
            ->recordActions([
                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon('ri-whatsapp-line')
                    ->color('success')
                    ->button()
                    ->disabled(fn (Famille $f) => Reglages::telephoneClient($f->service?->user) === '')
                    ->tooltip(fn (Famille $f) => Reglages::telephoneClient($f->service?->user) === '' ? 'Pas de numéro : ajoutez-le dans la fiche du client' : 'Ouvre WhatsApp avec le message prêt')
                    ->action(function (Famille $f) {
                        $tel = Reglages::telephoneClient($f->service?->user);
                        if ($tel === '') {
                            return;
                        }
                        DB::table(MirTV::TABLE)->where('id', $f->id)->update(['relance_at' => now()]);
                        $lien = 'https://wa.me/' . $tel . '?text=' . rawurlencode(self::message($f));
                        $this->js('window.open(' . json_encode($lien) . ', "_blank")');
                    }),
                Action::make('texte')
                    ->label('Voir le message')
                    ->icon('ri-chat-1-line')
                    ->color('gray')
                    ->modalHeading(fn (Famille $f) => 'Message pour la famille ' . $f->numero)
                    ->modalDescription('À copier pour un SMS ou un autre téléphone.')
                    ->modalContent(fn (Famille $f) => new HtmlString('<div style="white-space:pre-wrap;padding:12px;border-radius:10px;background:rgba(127,127,127,.1);user-select:all">' . e(self::message($f)) . '</div>'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer'),
            ]);
    }
}
