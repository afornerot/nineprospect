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
            new TwigFunction('produit_label', [$this, 'produitLabel']),
            new TwigFunction('produit_color', [$this, 'produitColor']),
            new TwigFunction('prospect_produit_principale', [$this, 'produitPrincipale']),
        ];
    }

    public function label(?bool $valeur): string
    {
        return match ($valeur) {
            true => 'Oui',
            false => 'Hors produit',
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

    public function produitLabel(Prospect $prospect): string
    {
        $pc = $this->produitPrincipale($prospect);
        if (!$pc || !$pc->getProduit()) {
            return '—';
        }

        return $pc->getProduit()->getTitre();
    }

    public function produitColor(Prospect $prospect): string
    {
        $pc = $this->produitPrincipale($prospect);
        if (!$pc) {
            return 'secondary';
        }

        return $this->color($pc->getQualifie());
    }

    public function produitPrincipale(Prospect $prospect): ?\App\Entity\ProspectProduit
    {
        $principale = null;
        foreach ($prospect->getProspectProduits() as $pc) {
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
