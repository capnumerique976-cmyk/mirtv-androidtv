<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Illuminate\Support\Facades\Gate;
use Paymenter\Extensions\Servers\MirTV\Admin\Pages\Guichet;

/**
 * Base des pages du guichet MirTV (menu « MirTV » de l'administration Paymenter).
 * Accès : administrateurs qui ont le droit de voir les réglages ; enregistrement : droit de les modifier.
 */
abstract class PageMirTV extends Page
{
    use RetourMirTV;

    protected static string|\UnitEnum|null $navigationGroup = 'MirTV';

    public static function canAccess(): bool
    {
        /** @var User|null $u */
        $u = auth()->user();

        return $u && $u->hasPermission('admin.settings.view');
    }

    protected function autoriserModification(): void
    {
        Gate::authorize('has-permission', 'admin.settings.update');
    }

    protected function getHeaderActions(): array
    {
        return static::class === Guichet::class ? [] : [
            Action::make('guichet')
                ->label('Retour au guichet')
                ->icon('ri-arrow-left-line')
                ->color('gray')
                ->url(Guichet::getUrl()),
        ];
    }

    /** Formulaire de la page (méthode « save ») avec ses boutons en bas. */
    protected function formulaire(array $boutons = []): Form
    {
        return Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')->label('Enregistrer')->submit('save')->keyBindings(['mod+s'])->icon('ri-save-line'),
                    ...$boutons,
                ]),
            ]);
    }

    protected function ok(string $titre, ?string $texte = null): void
    {
        $this->noterRetour($titre, $texte, false);
        Notification::make()->title($titre)->body($texte)->success()->send();
    }

    protected function erreur(string $titre, ?string $texte = null): void
    {
        $this->noterRetour($titre, $texte, true);
        Notification::make()->title($titre)->body($texte)->danger()->persistent()->send();
    }
}
