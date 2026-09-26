<?php

namespace App\Twig;

use App\Enum\PipelineStatut;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Rendu des statuts du pipeline (Visio / Démo / Devis / Signature).
 */
class PipelineExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('pipeline_label', [$this, 'label']),
            new TwigFunction('pipeline_color', [$this, 'color']),
        ];
    }

    public function label(?int $valeur): string
    {
        return null === $valeur ? '—' : PipelineStatut::label($valeur);
    }

    public function color(?int $valeur): string
    {
        return match ($valeur) {
            PipelineStatut::OUI => 'success',
            PipelineStatut::RELANCER => 'warning',
            PipelineStatut::NON => 'danger',
            PipelineStatut::EN_ATTENTE => 'secondary',
            PipelineStatut::A_QUALIFIER => 'info',
            default => 'light',
        };
    }
}
