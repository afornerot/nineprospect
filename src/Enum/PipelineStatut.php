<?php

namespace App\Enum;

/**
 * Statut d'une étape du pipeline (Visio / Démo / Devis / Signature).
 *
 * Trois états seulement : « À faire », « En cours », « Réalisé ». Les anciennes
 * valeurs détaillées ont été repliées sans perte :
 *   NON_DEMARRE (0), A_QUALIFIER (1)          -> A_FAIRE (0)
 *   RELANCER (2), EN_ATTENTE (3)              -> EN_COURS (1)
 *   OUI (4), NON (5)                          -> REALISE (2)
 *
 * Valeurs smallint pour rester sur la colonne existante `pipeline_valeur.statut`.
 */
final class PipelineStatut
{
    public const A_FAIRE = 0;
    public const EN_COURS = 1;
    public const REALISE = 2;

    /**
     * Cycle des statuts au clic : A_FAIRE → EN_COURS → REALISE → A_FAIRE.
     */
    public const CYCLE = [
        self::A_FAIRE,
        self::EN_COURS,
        self::REALISE,
    ];

    /**
     * Statut suivant dans le cycle. Boucle sur le premier après le dernier.
     */
    public static function suivant(int $actuel): int
    {
        $index = array_search($actuel, self::CYCLE, true);
        if (false === $index) {
            // Valeur hors cycle (anciennes données non migrées) : on repart du début.
            return self::CYCLE[0];
        }

        return self::CYCLE[($index + 1) % \count(self::CYCLE)];
    }

    /**
     * @var array<string, int>
     */
    public const LABELS = [
        'À faire' => self::A_FAIRE,
        'En cours' => self::EN_COURS,
        'Réalisé' => self::REALISE,
    ];

    /**
     * @return array<string, int>
     */
    public static function choices(): array
    {
        return self::LABELS;
    }

    public static function label(int $valeur): string
    {
        $trouve = array_search($valeur, self::LABELS, true);

        return false === $trouve ? 'Inconnu' : $trouve;
    }

    public static function color(int $valeur): string
    {
        return match ($valeur) {
            self::REALISE => 'success',
            self::EN_COURS => 'warning',
            self::A_FAIRE => 'secondary',
            default => 'light',
        };
    }

    /**
     * Convertit l'ancienne valeur détaillée (0 à 5) vers les trois états, pour
     * les données non encore migrées et les imports historiques.
     */
    public static function depuisAncienStatut(int $ancien): int
    {
        return match ($ancien) {
            2, 3 => self::EN_COURS,
            4, 5 => self::REALISE,
            default => self::A_FAIRE,
        };
    }

    /**
     * Convertit une valeur libre de la feuille ("relancer", "OUI", "en attente"...)
     * en statut. Retourne null si la valeur est inconnue (à signaler en anomalie).
     */
    public static function fromRaw(?string $valeur): ?int
    {
        if (null === $valeur) {
            return self::A_FAIRE;
        }

        $nettoye = mb_strtolower(trim($valeur));
        $nettoye = str_replace(['é', 'è', 'ê', 'ë'], 'e', $nettoye);
        $nettoye = str_replace(['à', 'â', 'ä'], 'a', $nettoye);
        $nettoye = str_replace(['ù', 'û', 'ü'], 'u', $nettoye);
        $nettoye = str_replace(['î', 'ï'], 'i', $nettoye);
        $nettoye = str_replace(['ç'], 'c', $nettoye);
        $nettoye = str_replace(['-', '_'], ' ', $nettoye);

        return match ($nettoye) {
            '' => self::A_FAIRE,
            'oui', 'o', 'ok', 'fait', 'faite', 'realise', 'realisee', 'signe', 'gagne', 'non' => self::REALISE,
            'en cours', 'cours', 'encours', 'relancer', 'relance', 'a relancer', 'suivre',
            'en attente', 'attente', 'pending' => self::EN_COURS,
            'a faire', 'faire', 'todo', 'non demarre', 'a qualifier', 'qualifier', 'a qualifie' => self::A_FAIRE,
            default => null,
        };
    }
}
