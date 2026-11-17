<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin\Pages;

use App\Models\Plan;
use App\Models\Product;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Paymenter\Extensions\Servers\MirTV\Admin\PageMirTV;

/** Formules et prix de l'abonnement « MirTV Famille », écrans et profils enfants par famille. */
class Formules extends PageMirTV implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mirtv/formules';

    protected static ?string $title = 'Formules et prix';

    protected static string|\BackedEnum|null $navigationIcon = 'ri-price-tag-3-line';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    public static function produit(): ?Product
    {
        return Product::where('slug', 'mirtv-famille')->first();
    }

    /** « 1 semaine », « 3 mois »… */
    public static function duree(Plan $p): string
    {
        $n = (int) $p->billing_period;
        $u = match ($p->billing_unit) {
            'day' => $n > 1 ? 'jours' : 'jour',
            'week' => $n > 1 ? 'semaines' : 'semaine',
            'month' => 'mois',
            'year' => $n > 1 ? 'ans' : 'an',
            default => (string) $p->billing_unit,
        };

        return $n . ' ' . $u;
    }

    public function mount(): void
    {
        $p = self::produit();
        $donnees = ['plans' => []];
        if ($p) {
            $donnees['nom_produit'] = $p->name;
            $donnees['ecrans'] = (int) ($p->settings()->where('key', 'ecrans')->first()?->value ?? 2);
            $donnees['profils_enfants'] = (int) ($p->settings()->where('key', 'profils_enfants')->first()?->value ?? 2);
            foreach ($p->plans()->orderBy('sort')->get() as $plan) {
                $donnees['plans'][$plan->id] = [
                    'name' => $plan->name,
                    'price' => (int) round((float) ($plan->price('MGA')?->price ?? 0)),
                ];
            }
        }
        $this->form->fill($donnees);
    }

    public function form(Schema $schema): Schema
    {
        $p = self::produit();
        if (!$p) {
            return $schema->components([
                Callout::make('Le produit « MirTV Famille » n\'existe pas encore')->description('Lancez installer-paymenter.sh sur le serveur.')->danger(),
            ])->statePath('data');
        }
        $test = $p->plans()->where('name', 'like', '%(TEST)%')->exists();
        $plans = [];
        foreach ($p->plans()->orderBy('sort')->get() as $plan) {
            $plans[] = Section::make('Durée : ' . self::duree($plan))
                ->compact()
                ->schema([
                    Grid::make(['default' => 1, 'md' => 2])->schema([
                        TextInput::make("plans.{$plan->id}.name")->label('Nom affiché aux clients')->required()->maxLength(60),
                        TextInput::make("plans.{$plan->id}.price")->label('Prix')->numeric()->integer()->minValue(0)->maxValue(10000000)->suffix('Ar')->required(),
                    ]),
                ]);
        }

        return $schema->components([
            Callout::make($test ? 'Les formules portent encore la mention « (TEST) »' : 'Formules prêtes')
                ->description($test ? 'Mettez les vrais noms et les vrais prix avant d\'ouvrir aux clients (par exemple « Semaine » à 5 000 Ar).' : 'Un changement de prix vaut pour les prochaines factures ; les factures déjà envoyées ne changent pas.')
                ->color($test ? 'warning' : 'success')
                ->icon($test ? 'ri-error-warning-line' : 'ri-checkbox-circle-line'),
            Section::make('Les formules')
                ->description('Le prix est en ariary, sans centimes. La durée ne se change pas ici (voir « Réglages avancés »).')
                ->icon('ri-price-tag-3-line')
                ->schema($plans),
            Section::make('Ce que comprend un abonnement')
                ->icon('ri-team-line')
                ->schema([
                    Grid::make(['default' => 1, 'md' => 3])->schema([
                        TextInput::make('nom_produit')->label('Nom de l\'abonnement')->required()->maxLength(60),
                        TextInput::make('ecrans')->label('Écrans en même temps')->numeric()->integer()->minValue(1)->maxValue(10)->required()->helperText('Pour les nouvelles familles (une famille existante se change dans « Familles »).'),
                        TextInput::make('profils_enfants')->label('Profils enfants créés')->numeric()->integer()->minValue(0)->maxValue(5)->required()->helperText('Pour les nouvelles familles.'),
                    ]),
                ]),
        ])->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        $p = self::produit();

        return $schema->components([
            $this->retour(),
            $this->formulaire($p ? [
                Action::make('avance')
                    ->label('Réglages avancés (ajouter une formule, durées)')
                    ->icon('ri-settings-4-line')
                    ->color('gray')
                    ->url('/admin/products/' . $p->id . '/edit'),
            ] : []),
        ]);
    }

    public function save(): void
    {
        $this->autoriserModification();
        $p = self::produit();
        if (!$p) {
            return;
        }
        $d = $this->form->getState();
        foreach ($d['plans'] ?? [] as $id => $v) {
            $plan = $p->plans()->whereKey($id)->first();
            if (!$plan) {
                continue;
            }
            $plan->update(['name' => trim((string) $v['name'])]);
            $plan->prices()->updateOrCreate(['currency_code' => 'MGA'], ['price' => (int) $v['price']]);
        }
        $p->update(['name' => trim((string) $d['nom_produit'])]);
        $p->settings()->updateOrCreate(['key' => 'ecrans'], ['value' => (int) $d['ecrans'], 'type' => 'integer']);
        $p->settings()->updateOrCreate(['key' => 'profils_enfants'], ['value' => (int) $d['profils_enfants'], 'type' => 'integer']);
        $this->ok('Formules enregistrées');
        $this->rediriger();
    }
}
