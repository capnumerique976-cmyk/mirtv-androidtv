<?php

namespace Paymenter\Extensions\Servers\MirTV\Admin;

use Filament\Schemas\Components\Callout;

/**
 * Message « Enregistré » affiché dans la page elle-même (en plus de la notification de Filament,
 * qui ne s'affiche pas toujours dans le portail). Après une redirection, le message passe par la session (rediriger()).
 */
trait RetourMirTV
{
    public ?string $retourTitre = null;

    public ?string $retourTexte = null;

    public bool $retourErreur = false;

    public function mountRetourMirTV(): void
    {
        $r = session()->pull('mirtv_retour');
        if (is_array($r)) {
            [$this->retourTitre, $this->retourTexte, $this->retourErreur] = $r + [null, null, false];
        }
    }

    protected function noterRetour(string $titre, ?string $texte, bool $erreur): void
    {
        $this->retourTitre = $titre;
        $this->retourTexte = $texte;
        $this->retourErreur = $erreur;
    }

    /** Recharge la page en gardant le message du dernier enregistrement. */
    protected function rediriger(): void
    {
        if (filled($this->retourTitre)) {
            session()->flash('mirtv_retour', [$this->retourTitre, $this->retourTexte, $this->retourErreur]);
        }
        $this->redirect(static::getUrl());
    }

    /** Bandeau du dernier enregistrement (caché s'il n'y en a pas). */
    protected function retour(): Callout
    {
        return Callout::make(fn () => ($this->retourErreur ? '⚠ ' : '✓ ') . $this->retourTitre . ' — ' . now()->format('H:i'))
            ->description(fn () => $this->retourTexte)
            ->color(fn () => $this->retourErreur ? 'danger' : 'success')
            ->visible(fn () => filled($this->retourTitre));
    }
}
