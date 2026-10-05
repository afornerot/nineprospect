<?php

namespace App\Service\Import;

/**
 * Rapport final post-import (étape "rapport").
 * Compteurs et lignes affectées/ignorées/en erreur.
 */
final class ImportResult
{
    public int $prospectsCreated = 0;
    public int $prospectsLinked = 0;
    public int $prospectsErrored = 0;

    public int $contactsCreated = 0;

    /** Lignes explicitement ignorées par l'utilisateur (skip). */
    public int $rowsSkipped = 0;

    /** Lignes en erreur (format invalide). */
    public int $rowsErrored = 0;

    /** @var list<array{row: int, motif: string}> */
    public array $errors = [];

    /** @var list<int> IDs des prospects créés/rattachés pour les liens retour. */
    public array $affectedProspectIds = [];

    public ?int $cibleId = null;
    public ?string $cibleTitle = null;

    public function resume(): string
    {
        return sprintf(
            'Import terminé : %d prospect(s) créé(s), %d rattaché(s) à un prospect existant, %d contact(s) créé(s), %d ligne(s) ignorée(s), %d en erreur.',
            $this->prospectsCreated,
            $this->prospectsLinked,
            $this->contactsCreated,
            $this->rowsSkipped,
            $this->rowsErrored,
        );
    }
}
