<?php

namespace App\Service\Import;

/**
 * Une ligne analysée : ImportRow + statut + action par défaut.
 * C'est ce que le contrôleur passe au template de pré-import et
 * ce que l'utilisateur peut modifier (action par ligne).
 */
final class AnalyzedImportRow
{
    public function __construct(
        public readonly ImportRow $row,
        public readonly string $status,
        public readonly ?int $existingProspectId = null,
        public readonly ?int $existingContactId = null,
        /** Action par défaut proposée (ImportRowAction::*) ou vide si aucune action possible. */
        public readonly string $defaultAction = ImportRowAction::IMPORT,
    ) {
    }

    public function isOk(): bool
    {
        return ImportRowStatus::OK === $this->status;
    }

    public function isError(): bool
    {
        return ImportRowStatus::ERROR === $this->status;
    }

    public function isDuplicate(): bool
    {
        return in_array($this->status, [
            ImportRowStatus::DUPLICATE_PROSPECT_DB,
            ImportRowStatus::DUPLICATE_CONTACT_DB,
            ImportRowStatus::DUPLICATE_INTRA,
        ], true);
    }

    /**
     * Actions que l'utilisateur peut choisir pour cette ligne (filtrées par statut).
     *
     * @return array<string, string>
     */
    public function availableActions(): array
    {
        return ImportRowAction::choicesForStatus($this->status);
    }

    public function hasActionChoice(): bool
    {
        return [] !== $this->availableActions();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            ImportRowStatus::OK => 'OK',
            ImportRowStatus::ERROR => 'Erreur',
            ImportRowStatus::DUPLICATE_PROSPECT_DB => 'Doublon prospect',
            ImportRowStatus::DUPLICATE_CONTACT_DB => 'Doublon contact',
            ImportRowStatus::DUPLICATE_INTRA => 'Doublon interne',
            default => $this->status,
        };
    }

    public function statusColor(): string
    {
        return match ($this->status) {
            ImportRowStatus::OK => 'success',
            ImportRowStatus::ERROR => 'danger',
            ImportRowStatus::DUPLICATE_PROSPECT_DB, ImportRowStatus::DUPLICATE_CONTACT_DB, ImportRowStatus::DUPLICATE_INTRA => 'warning',
            default => 'secondary',
        };
    }
}
