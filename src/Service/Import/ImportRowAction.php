<?php

namespace App\Service\Import;

/**
 * Action que l'utilisateur peut choisir pour une ligne lors du pré-import.
 */
final class ImportRowAction
{
    public const SKIP = 'skip';
    public const IMPORT = 'import';
    public const LINK = 'link';

    public const ALL = [
        self::SKIP => 'Ignorer',
        self::IMPORT => 'Importer',
        self::LINK => 'Modifier',
    ];

    /**
     * @return list<string>
     */
    public static function choices(): array
    {
        return array_keys(self::ALL);
    }

    /**
     * Actions proposées pour une ligne en fonction de son statut.
     *
     * "Ignorer" est TOUJOURS disponible : l'utilisateur doit pouvoir passer
     * outre une ligne individuellement, même valide.
     *
     * - OK : Importer (par défaut) ou Ignorer
     * - Doublon prospect DB : Modifier (par défaut) ou Ignorer
     * - Doublon contact DB : Ignorer uniquement (le contact existe déjà)
     * - Doublon intra : Ignorer uniquement (doublon déjà géré par les autres lignes du groupe)
     * - Erreur : SKIP forcé (aucun choix possible)
     *
     * @return array<string, string>
     */
    public static function choicesForStatus(string $status): array
    {
        return match ($status) {
            ImportRowStatus::OK => [
                self::IMPORT => self::ALL['import'],
                self::SKIP => self::ALL['skip'],
            ],
            ImportRowStatus::DUPLICATE_PROSPECT_DB => [
                self::LINK => self::ALL['link'],
                self::SKIP => self::ALL['skip'],
            ],
            ImportRowStatus::DUPLICATE_CONTACT_DB => [
                self::SKIP => self::ALL['skip'],
            ],
            ImportRowStatus::DUPLICATE_INTRA => [
                self::SKIP => self::ALL['skip'],
            ],
            ImportRowStatus::ERROR => [],
            default => [],
        };
    }
}
