<?php

namespace App\Service\Import;

/**
 * Résultat complet de l'analyse d'un fichier (étape "pré-import").
 * Sérialisé en session entre les écrans de pré-import et d'execute.
 */
final class ImportPreview
{
    /** @var list<string> Colonnes canoniques trouvées dans l'en-tête du fichier. */
    public array $columnsDetected = [];

    /** @var list<string> Colonnes canoniques obligatoires manquantes. */
    public array $columnsMissing = [];

    /** @var list<string> Colonnes inconnues (non bloquant, juste un warning). */
    public array $columnsUnknown = [];

    /** Erreur fatale (header invalide, fichier illisible). */
    public ?string $fatalError = null;

    /** @var list<ImportGroup> */
    public array $groups = [];

    public function __construct(
        public readonly string $filename,
        public readonly string $mode,
        public readonly ?int $ciblePrincipaleId,
        public readonly ?string $ciblePrincipaleTitle,
        /** @var list<int> */
        public readonly array $ciblesSupplementairesIds = [],
        public readonly ?int $campagneId = null,
        /** @var list<int> */
        public readonly array $categoriesSupplementairesIds = [],
    ) {
    }

    /** @return list<AnalyzedImportRow> */
    public function allRows(): array
    {
        $out = [];
        foreach ($this->groups as $g) {
            foreach ($g->getRows() as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    public function totalRows(): int
    {
        return count($this->allRows());
    }

    public function errorRows(): int
    {
        $n = 0;
        foreach ($this->allRows() as $row) {
            if ($row->isError()) {
                ++$n;
            }
        }

        return $n;
    }

    public function duplicateRows(): int
    {
        $n = 0;
        foreach ($this->allRows() as $row) {
            if ($row->isDuplicate()) {
                ++$n;
            }
        }

        return $n;
    }

    public function okRows(): int
    {
        $n = 0;
        foreach ($this->allRows() as $row) {
            if ($row->isOk()) {
                ++$n;
            }
        }

        return $n;
    }

    public function isFatal(): bool
    {
        return null !== $this->fatalError || [] !== $this->columnsMissing;
    }
}
