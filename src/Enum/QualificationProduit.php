<?php

namespace App\Enum;

/**
 * Qualification d'un prospect sur un produit, portée par le lien
 * `ProspectProduit` (une qualification par couple prospect × produit).
 *
 * Stockée en base sous forme de chaîne dans la colonne `qualifie` (ex-booléen
 * nullable : 1 = Oui, 0 = Hors produit, NULL = Non).
 */
enum QualificationProduit: string
{
    /** Aucune décision prise : état par défaut d'un lien créé. */
    case NON = 'non';

    case OUI = 'oui';

    case HORS_PRODUIT = 'hors_produit';

    /** Le prospect n'a pas pu être joint sur ce produit. */
    case INJOIGNABLE = 'injoignable';

    /**
     * Cycle des statuts au clic : Non → Oui → Hors produit → Injoignable → Non.
     * L'ordre historique (Non → Oui → Hors produit) est conservé ; le nouvel
     * état s'insère en fin de boucle.
     */
    public const CYCLE = [
        self::NON,
        self::OUI,
        self::HORS_PRODUIT,
        self::INJOIGNABLE,
    ];

    /**
     * Statut suivant dans le cycle. Boucle sur le premier après le dernier.
     */
    public function suivant(): self
    {
        $index = array_search($this, self::CYCLE, true);
        if (false === $index) {
            return self::CYCLE[0];
        }

        return self::CYCLE[($index + 1) % \count(self::CYCLE)];
    }

    public function label(): string
    {
        return match ($this) {
            self::OUI => 'Oui',
            self::HORS_PRODUIT => 'Hors produit',
            self::INJOIGNABLE => 'Injoignable',
            self::NON => 'Non',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OUI => 'success',
            self::HORS_PRODUIT => 'danger',
            self::INJOIGNABLE => 'warning',
            self::NON => 'secondary',
        };
    }

    /**
     * Une qualification tranchée est datée et signée ; « Non » ne porte aucune
     * décision (ni date, ni auteur).
     */
    public function estTranchee(): bool
    {
        return self::NON !== $this;
    }

    /**
     * Statuts tranchés (« tout sauf Non »), dans l'ordre du cycle.
     *
     * @return list<self>
     */
    public static function tranchees(): array
    {
        return array_values(array_filter(self::CYCLE, static fn (self $valeur): bool => $valeur->estTranchee()));
    }

    /**
     * Valeur du filtre de liste (`?qualifie=`) → état. `horsproduit` reste la
     * clé historique pour ne pas casser les URL et les liens existants.
     */
    public static function depuisFiltre(string $filtre): ?self
    {
        return match ($filtre) {
            'non' => self::NON,
            'oui' => self::OUI,
            'horsproduit' => self::HORS_PRODUIT,
            'injoignable' => self::INJOIGNABLE,
            default => null,
        };
    }
}
