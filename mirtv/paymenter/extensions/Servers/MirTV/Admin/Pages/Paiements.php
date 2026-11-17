<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use Filament\Actions\Action;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;
use Paymenter\Extensions\Servers\MirTV\Modeles\PaiementOrange;

/** Paiements Orange Money : reçus, en attente, échoués ; totaux du jour et du mois. */
class Paiements extends PageMirTV implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'mirtv/paiements';

    protected static ?string $title = 'Paiements reçus';

    protected static ?string $navigationLabel = 'Paiements';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-money-dollar-circle-line';

    protected static ?int $navigationSort = 6;

    public const STATUTS = [
        'SUCCESS' => 'Payé',
        'INITIATED' => 'Commencé',
        'PENDING' => 'En attente',
        'FAILED' => 'Échoué',
        'EXPIRED' => 'Expiré',
        'MONTANT' => 'Montant incorrect',
    ];

    public static function ar(int|float $n): string
    {
        return number_format((float) $n, 0, ',', ' ') . ' Ar';
    }

    public function content(Schema $schema): Schema
    {
        $reel = PaiementOrange::query()->where('status', 'SUCCESS')->where('mode', 'production');
        $jour = (clone $reel)->where('updated_at', '>=', now()->startOfDay());
        $mois = (clone $reel)->where('updated_at', '>=', now()->startOfMonth());
        $tests = PaiementOrange::query()->where('status', 'SUCCESS')->where('mode', '!=', 'production')->count();
        $carte = fn ($titre, $valeur, $detail, $couleur) => '<div style="flex:1;min-width:170px;padding:14px 16px;border-radius:12px;background:rgba(127,127,127,.08);border-left:4px solid ' . $couleur . '"><div style="font-size:12px;opacity:.7">' . e($titre) . '</div><div style="font-size:24px;font-weight:700">' . e($valeur) . '</div><div style="font-size:12px;opacity:.7">' . e($detail) . '</div></div>';

        return $schema->components([
            Html::make('<div style="display:flex;gap:12px;flex-wrap:wrap">'
                . $carte('Reçu aujourd\'hui', self::ar((clone $jour)->sum('amount')), (clone $jour)->count() . ' paiement(s)', '#22c55e')
                . $carte('Reçu ce mois-ci', self::ar((clone $mois)->sum('amount')), (clone $mois)->count() . ' paiement(s)', '#ff7a00')
                . $carte('Paiements d\'essai', (string) $tests, 'simulation et sandbox (pas de vrai argent)', '#94a3b8')
                . '</div>'),
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(PaiementOrange::query())
            ->defaultSort('id', 'desc')
            ->emptyStateHeading('Aucun paiement pour l\'instant')
            ->columns([
                TextColumn::make('updated_at')->label('Date')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('order_id')->label('Commande')->searchable()->copyable(),
                TextColumn::make('amount')->label('Montant')->formatStateUsing(fn ($state, PaiementOrange $p) => $p->currency === 'MGA' ? self::ar($state) : $state . ' ' . $p->currency)->sortable(),
                TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->formatStateUsing(fn ($state) => self::STATUTS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'SUCCESS' => 'success',
                        'INITIATED', 'PENDING' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('mode')
                    ->label('Mode')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'production' => 'Réel',
                        'sandbox' => 'Sandbox',
                        default => 'Simulation',
                    })
                    ->color(fn ($state) => $state === 'production' ? 'success' : 'gray'),
                TextColumn::make('txnid')->label('N° Orange')->placeholder('—')->copyable()->toggleable(),
                TextColumn::make('invoice_id')->label('Facture')->prefix('n° '),
            ])
            ->filters([
                SelectFilter::make('status')->label('État')->options(self::STATUTS),
                SelectFilter::make('mode')->label('Mode')->options(['production' => 'Réel', 'sandbox' => 'Sandbox', 'simulation' => 'Simulation']),
            ])
            ->recordActions([
                Action::make('facture')
                    ->label('Facture')
                    ->icon('ri-file-text-line')
                    ->url(fn (PaiementOrange $p) => '/admin/invoice/invoices/' . $p->invoice_id),
            ]);
    }
}
