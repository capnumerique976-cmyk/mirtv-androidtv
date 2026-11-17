<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use App\Models\InvoiceTransaction;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Paymenter\Extensions\Servers\MirTV\Reglages;
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

    protected static ?int $navigationSort = 7;

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
            $this->retour(),
            Html::make('<div style="display:flex;gap:12px;flex-wrap:wrap">'
                . $carte('Reçu aujourd\'hui', self::ar((clone $jour)->sum('amount')), (clone $jour)->count() . ' paiement(s)', '#22c55e')
                . $carte('Reçu ce mois-ci', self::ar((clone $mois)->sum('amount')), (clone $mois)->count() . ' paiement(s)', '#ff7a00')
                . $carte('Paiements d\'essai', (string) $tests, 'simulation et sandbox (pas de vrai argent)', '#94a3b8')
                . '</div>'),
            Html::make(self::parMois()),
            EmbeddedTable::make(),
        ]);
    }

    /** Argent réel reçu sur les 6 derniers mois (petit tableau). */
    public static function parMois(): string
    {
        $lignes = '';
        for ($i = 5; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $q = PaiementOrange::query()->where('status', 'SUCCESS')->where('mode', 'production')->whereBetween('updated_at', [$m, $m->copy()->endOfMonth()]);
            $lignes .= '<td style="padding:8px 10px;text-align:center"><div style="font-size:12px;opacity:.7">' . e(ucfirst($m->translatedFormat('M Y'))) . '</div><div style="font-weight:700">' . e(self::ar((clone $q)->sum('amount'))) . '</div><div style="font-size:11px;opacity:.6">' . (clone $q)->count() . ' paiement(s)</div></td>';
        }

        return '<div style="margin-top:4px;overflow-x:auto"><div style="font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;opacity:.6;margin-bottom:6px">Argent réel reçu par mois</div><table style="width:100%;border-collapse:separate;border-spacing:6px 0"><tr>' . $lignes . '</tr></table></div>';
    }

    /** Mois proposés pour l'export (12 derniers). */
    public static function mois(): array
    {
        $l = [];
        for ($i = 0; $i < 12; $i++) {
            $m = now()->startOfMonth()->subMonths($i);
            $l[$m->format('Y-m')] = ucfirst($m->translatedFormat('F Y'));
        }

        return $l;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('exporter')
                ->label('Exporter pour la comptabilité')
                ->icon('ri-file-excel-2-line')
                ->color('success')
                ->schema([
                    Select::make('mois')->label('Mois')->options(self::mois())->default(now()->format('Y-m'))->selectablePlaceholder(false)->required(),
                    Toggle::make('essais')->label('Inclure les paiements d\'essai (simulation, sandbox)')->default(false),
                ])
                ->modalDescription('Un fichier que Excel ouvre directement : une ligne par paiement reçu (date, n° de facture, client, téléphone, montant, n° Orange).')
                ->modalSubmitActionLabel('Télécharger')
                ->action(fn (array $data) => $this->exporter($data['mois'], (bool) ($data['essais'] ?? false))),
            ...parent::getHeaderActions(),
        ];
    }

    public function exporter(string $mois, bool $essais)
    {
        $debut = Carbon::createFromFormat('Y-m-d', $mois . '-01')->startOfDay();
        $fin = $debut->copy()->endOfMonth();
        $transactions = InvoiceTransaction::query()
            ->with(['invoice.user', 'invoice.items', 'gateway'])
            ->where('status', 'succeeded')
            ->whereBetween('created_at', [$debut, $fin])
            ->orderBy('created_at')
            ->get();
        $nom = 'mirtv-paiements-' . $mois . '.csv';

        return response()->streamDownload(function () use ($transactions, $essais) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // Excel reconnaît les accents
            fputcsv($out, ['Date', 'N° de facture', 'Client', 'E-mail', 'Téléphone', 'Détail', 'Montant (Ar)', 'Moyen de paiement', 'N° de transaction', 'Mode'], ';');
            foreach ($transactions as $t) {
                $f = $t->invoice;
                $mode = DB::table('ext_orange_paiements')->where('invoice_id', $f?->id)->where('status', 'SUCCESS')->value('mode') ?? '';
                if (!$essais && in_array($mode, ['simulation', 'sandbox'], true)) {
                    continue;
                }
                fputcsv($out, [
                    $t->created_at->format('d/m/Y H:i'),
                    $f?->number ?: 'proforma ' . $f?->id,
                    $f?->user?->name ?? '',
                    $f?->user?->email ?? '',
                    $f?->user ? Reglages::lisible(Reglages::telephoneClient($f->user)) : '',
                    $f ? $f->items->pluck('description')->implode(' + ') : '',
                    number_format((float) $t->amount, 0, '', ''),
                    $t->gateway?->name ?? '',
                    $t->transaction_id ?? '',
                    ['production' => 'réel', 'sandbox' => 'sandbox', 'simulation' => 'simulation'][$mode] ?? $mode,
                ], ';');
            }
            fclose($out);
        }, $nom, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
