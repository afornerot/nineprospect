<?php

namespace App\Twig;

use App\Entity\Prospect;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class QualifieExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('qualifie_label', [$this, 'label']),
            new TwigFunction('qualifie_color', [$this, 'color']),
            new TwigFunction('cible_label', [$this, 'cibleLabel']),
            new TwigFunction('cible_color', [$this, 'cibleColor']),
            new TwigFunction('prospect_cible_principale', [$this, 'ciblePrincipale']),
        ];
    }

    public function label(?bool $valeur): string
    {
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

    public function cibleLabel(Prospect $prospect): string
    {
        $pc = $this->ciblePrincipale($prospect);
        if (!$pc || !$pc->getCible()) {
            return '—';
        }

        return $pc->getCible()->getTitre();
    }

    public function cibleColor(Prospect $prospect): string
    {
        $pc = $this->ciblePrincipale($prospect);
        if (!$pc) {
            return 'secondary';
        }

        return $this->color($pc->getQualifie());
    }

    public function ciblePrincipale(Prospect $prospect): ?\App\Entity\ProspectCible
    {
        $principale = null;
        foreach ($prospect->getProspectCibles() as $pc) {
            if (null === $principale) {
                $principale = $pc;
                continue;
            }

            $prioriteActuel = $this->priorite($principale->getQualifie());
            $prioriteNouveau = $this->priorite($pc->getQualifie());

            if ($prioriteNouveau > $prioriteActuel) {
                $principale = $pc;
            }
        }

        return $principale;
    }

    private function priorite(?bool $qualifie): int
    {
        return match ($qualifie) {
            true => 3,
            null => 2,
            false => 1,
        };
    }
}
