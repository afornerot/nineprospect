<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Rendu des statuts de qualification d'un lead (Hors cible / Oui / Non).
 */
class QualifieExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('qualifie_label', [$this, 'label']),
            new TwigFunction('qualifie_color', [$this, 'color']),
        ];
    }

    public function label(?bool $valeur): string
    {
        // Sémantique du bool pour la qualification d'un lead :
        //   null  → « Non »       (état initial, sans décision)
        //   true  → « Oui »       (lead qualifié)
        //   false → « Hors cible » (refusé par scoring)
        return match ($valeur) {
            true => 'Oui',
            false => 'Hors cible',
            default => 'Non',
        };
    }

    public function color(?bool $valeur): string
    {
        return match ($valeur) {
            true => 'success',
            false => 'danger',
            default => 'secondary',
        };
    }
}
