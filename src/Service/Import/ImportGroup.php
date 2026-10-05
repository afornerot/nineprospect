<?php

namespace App\Service\Import;

/**
 * Regroupement de plusieurs AnalyzedImportRow partageant la même cleEntreprise.
 * Un ImportGroup = un Prospect en devenir (créé ou rattaché).
 */
final class ImportGroup
{
    /** @var list<AnalyzedImportRow> */
    private array $rows = [];

    /** Libellé affiché (organisation brute de la 1re ligne). */
    private ?string $libelle = null;

    /** cleEntreprise normalisée. */
    private string $cleEntreprise;

    /**
     * ID d'un Prospect existant si un des rows pointe sur un prospect déjà en base
     * (les rows d'un même groupe pointent normalement sur le même prospect).
     */
    private ?int $existingProspectId = null;

    public function __construct(string $cleEntreprise)
    {
        $this->cleEntreprise = $cleEntreprise;
    }

    public function addRow(AnalyzedImportRow $row): void
    {
        if (null === $this->libelle && null !== $row->row->organisation) {
            $this->libelle = $row->row->organisation;
        }
        if (null !== $row->existingProspectId && null === $this->existingProspectId) {
            $this->existingProspectId = $row->existingProspectId;
        }
        $this->rows[] = $row;
    }

    public function getCleEntreprise(): string
    {
        return $this->cleEntreprise;
    }

    public function getLibelle(): string
    {
        return $this->libelle ?? '(sans organisation)';
    }

    public function getExistingProspectId(): ?int
    {
        return $this->existingProspectId;
    }

    public function isExisting(): bool
    {
        return null !== $this->existingProspectId;
    }

    /** @return list<AnalyzedImportRow> */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function count(): int
    {
        return count($this->rows);
    }
}
