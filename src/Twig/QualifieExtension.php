<?php

namespace App\Twig;

use App\Entity\Prospect;
use App\Enum\QualificationProduit;
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

    public function label(?QualificationProduit $valeur): string
    {
        return ($valeur ?? QualificationProduit::NON)->label();
    }

    public function color(?QualificationProduit $valeur): string
    {
        return ($valeur ?? QualificationProduit::NON)->color();
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
            return QualificationProduit::NON->color();
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

            if ($this->priorite($pc->getQualifie()) > $this->priorite($principale->getQualifie())) {
                $principale = $pc;
            }
        }

        return $principale;
    }

    /**
     * Priorité d'affichage de la qualification principale : un prospect
     * qualifié sur plusieurs produits montre l'état le plus engageant.
     */
    private function priorite(QualificationProduit $qualifie): int
    {
        return match ($qualifie) {
            QualificationProduit::OUI => 4,
            QualificationProduit::NON => 3,
            QualificationProduit::INJOIGNABLE => 2,
            QualificationProduit::HORS_PRODUIT => 1,
        };
    }
}
