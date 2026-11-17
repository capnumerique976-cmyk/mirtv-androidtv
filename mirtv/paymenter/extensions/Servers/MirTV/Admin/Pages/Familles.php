<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use App\Helpers\ExtensionHelper;
use App\Models\Service;
use Exception;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\TextInput;
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

/** Familles abonnées : état, échéance, actions (pause, réactivation, jours offerts, écrans, code d'activation). */
class Familles extends PageMirTV implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'mirtv/familles';

    protected static ?string $title = 'Familles abonnées';

    protected static ?string $navigationLabel = 'Familles';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-team-line';

    protected static ?int $navigationSort = 6;

    public const STATUTS = [
        'active' => 'Active',
        'pause' => 'En pause (impayé)',
        'creation' => 'En création',
        'resiliee' => 'Arrêtée',
        'supprimee' => 'Supprimée',
    ];

    public function content(Schema $schema): Schema
    {
        $n = Famille::query()->select('statut', DB::raw('count(*) as n'))->groupBy('statut')->pluck('n', 'statut');
        $carte = fn ($titre, $valeur, $couleur) => '<div style="flex:1;min-width:150px;padding:14px 16px;border-radius:12px;background:rgba(127,127,127,.08);border-left:4px solid ' . $couleur . '"><div style="font-size:12px;opacity:.7">' . e($titre) . '</div><div style="font-size:26px;font-weight:700">' . (int) $valeur . '</div></div>';

        return $schema->components([
            $this->retour(),
            Html::make('<div style="display:flex;gap:12px;flex-wrap:wrap">'
                . $carte('Actives', $n['active'] ?? 0, '#22c55e')
                . $carte('En pause (impayé)', $n['pause'] ?? 0, '#f59e0b')
                . $carte('Arrêtées', $n['resiliee'] ?? 0, '#94a3b8')
                . $carte('Total', $n->sum(), '#ff7a00')
                . '</div>'),
            EmbeddedTable::make(),
        ]);
    }

    private static function service(Famille $f): ?Service
    {
        return Service::find($f->service_id);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(Famille::query()->with(['service.user', 'service.plan']))
            ->defaultSort('numero', 'desc')
            ->emptyStateHeading('Aucune famille pour l\'instant')
            ->emptyStateDescription('Une famille est créée automatiquement au premier paiement d\'un client.')
            ->columns([
                TextColumn::make('numero')->label('N°')->sortable()->searchable()->weight('bold'),
                TextColumn::make('client')
                    ->label('Client')
                    ->state(fn (Famille $f) => trim(($f->service?->user?->first_name ?? '') . ' ' . ($f->service?->user?->last_name ?? '')) ?: '—')
                    ->description(fn (Famille $f) => implode(' · ', array_filter([$f->service?->user?->email, ($t = \Paymenter\Extensions\Servers\MirTV\Reglages::telephoneClient($f->service?->user)) !== '' ? \Paymenter\Extensions\Servers\MirTV\Reglages::lisible($t) : null])))
                    ->searchable(query: fn (Builder $q, string $s) => $q->whereHas('service.user', fn ($u) => $u->where('email', 'like', "%{$s}%")->orWhere('first_name', 'like', "%{$s}%")->orWhere('last_name', 'like', "%{$s}%"))),
                TextColumn::make('formule')->label('Formule')->state(fn (Famille $f) => $f->service?->plan?->name ?? '—'),
                TextColumn::make('statut')
                    ->label('État')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::STATUTS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'pause' => 'warning',
                        'creation' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('echeance')
                    ->label('Payé jusqu\'au')
                    ->state(fn (Famille $f) => $f->service?->expires_at)
                    ->date('d/m/Y'),
                TextColumn::make('ecrans')->label('Écrans')->alignCenter(),
                TextColumn::make('created_at')->label('Inscrite le')->date('d/m/Y')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('statut')->label('État')->options(self::STATUTS),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('payer')
                        ->label('Lien de paiement')
                        ->icon('ri-links-line')
                        ->url(fn (Famille $f) => rtrim((string) MirTV::instance()->config('url_publique'), '/') . '/payer/' . DB::table(MirTV::TABLE)->where('id', $f->id)->value('jeton_paiement'), shouldOpenInNewTab: true),
                    Action::make('code')
                        ->label('Code d\'activation')
                        ->icon('ri-key-line')
                        ->modalHeading(fn (Famille $f) => 'Code d\'activation — famille ' . $f->numero)
                        ->modalDescription('À donner à la famille pour connecter un nouvel écran (« J\'ai un code d\'activation » dans l\'appli). Valable 48 h, une seule fois.')
                        ->modalContent(function (Famille $f) {
                            $l = DB::table(MirTV::TABLE)->where('id', $f->id)->first();
                            if (!$l->code_activation || !$l->code_expire_at || now()->greaterThan($l->code_expire_at)) {
                                $code = MirTV::code();
                                DB::table(MirTV::TABLE)->where('id', $f->id)->update(['code_activation' => $code, 'code_expire_at' => now()->addHours(48), 'updated_at' => now()]);
                                $l = DB::table(MirTV::TABLE)->where('id', $f->id)->first();
                            }
                            $fin = \Illuminate\Support\Carbon::parse($l->code_expire_at)->format('d/m/Y à H:i');

                            return new HtmlString('<div style="text-align:center;padding:8px 0"><div style="font-size:40px;font-weight:800;letter-spacing:.12em;font-family:ui-monospace,monospace">' . e(substr($l->code_activation, 0, 4) . '-' . substr($l->code_activation, 4)) . '</div><div style="opacity:.7;margin-top:6px">Valable jusqu\'au ' . e($fin) . '</div></div>');
                        })
                        ->modalSubmitActionLabel('Remplacer par un nouveau code')
                        ->modalCancelActionLabel('Fermer')
                        ->action(function (Famille $f) {
                            $this->autoriserModification();
                            $code = MirTV::code();
                            DB::table(MirTV::TABLE)->where('id', $f->id)->update(['code_activation' => $code, 'code_expire_at' => now()->addHours(48), 'updated_at' => now()]);
                            $this->ok('Nouveau code pour la famille ' . $f->numero . ' : ' . substr($code, 0, 4) . '-' . substr($code, 4));
                        }),
                    Action::make('jours')
                        ->label('Offrir des jours')
                        ->icon('ri-gift-line')
                        ->schema([
                            TextInput::make('jours')->label('Nombre de jours offerts')->numeric()->integer()->minValue(1)->maxValue(366)->default(7)->required(),
                        ])
                        ->action(function (Famille $f, array $data) {
                            $this->autoriserModification();
                            $s = self::service($f);
                            if (!$s) {
                                return;
                            }
                            $base = $s->expires_at && $s->expires_at->isFuture() ? $s->expires_at->copy() : now();
                            $s->expires_at = $base->addDays((int) $data['jours']);
                            if ($s->status === Service::STATUS_SUSPENDED) {
                                $this->reactiver($s);
                            }
                            $s->status = Service::STATUS_ACTIVE;
                            $s->save();
                            $this->ok((int) $data['jours'] . ' jour(s) offert(s) à la famille ' . $f->numero, 'Payé jusqu\'au ' . $s->expires_at->format('d/m/Y') . '.');
                        }),
                    Action::make('ecrans')
                        ->label('Nombre d\'écrans')
                        ->icon('ri-tv-2-line')
                        ->schema([
                            TextInput::make('ecrans')->label('Écrans en même temps')->numeric()->integer()->minValue(1)->maxValue(10)->default(fn (Famille $f) => $f->ecrans)->required(),
                        ])
                        ->action(function (Famille $f, array $data) {
                            $this->autoriserModification();
                            DB::table(MirTV::TABLE)->where('id', $f->id)->update(['ecrans' => (int) $data['ecrans'], 'updated_at' => now()]);
                            MirTV::noterLimite((int) $f->numero, (int) $data['ecrans']);
                            $this->ok('Famille ' . $f->numero . ' : ' . (int) $data['ecrans'] . ' écrans');
                        }),
                    Action::make('pause')
                        ->label('Mettre en pause')
                        ->icon('ri-pause-circle-line')
                        ->color('warning')
                        ->visible(fn (Famille $f) => $f->statut === 'active')
                        ->requiresConfirmation()
                        ->modalDescription('Tous les écrans de la famille s\'arrêtent jusqu\'au prochain paiement (ou jusqu\'à « Réactiver »).')
                        ->action(function (Famille $f) {
                            $this->autoriserModification();
                            $s = self::service($f);
                            try {
                                ExtensionHelper::suspendServer($s);
                                $s->update(['status' => Service::STATUS_SUSPENDED]);
                                $this->ok('Famille ' . $f->numero . ' en pause');
                            } catch (Exception $e) {
                                $this->erreur('Pause impossible', $e->getMessage());
                            }
                        }),
                    Action::make('reactiver')
                        ->label('Réactiver')
                        ->icon('ri-play-circle-line')
                        ->color('success')
                        ->visible(fn (Famille $f) => $f->statut === 'pause')
                        ->requiresConfirmation()
                        ->modalDescription('Les écrans remarchent tout de suite. Si l\'échéance est dépassée, la famille repassera en pause au prochain contrôle : utilisez plutôt « Offrir des jours ».')
                        ->action(function (Famille $f) {
                            $this->autoriserModification();
                            $s = self::service($f);
                            if ($s && $this->reactiver($s)) {
                                $s->update(['status' => Service::STATUS_ACTIVE]);
                                $this->ok('Famille ' . $f->numero . ' réactivée');
                            }
                        }),
                    Action::make('paymenter')
                        ->label('Fiche Paymenter')
                        ->icon('ri-file-list-3-line')
                        ->url(fn (Famille $f) => '/admin/services/services/' . $f->service_id . '/edit'),
                ])->label('Actions')->button()->size('sm'),
            ]);
    }

    private function reactiver(Service $s): bool
    {
        try {
            ExtensionHelper::unsuspendServer($s);

            return true;
        } catch (Exception $e) {
            $this->erreur('Réactivation impossible', $e->getMessage());

            return false;
        }
    }
}
