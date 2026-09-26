<?php

namespace App\Enum;

/**
 * Statut d'une étape du pipeline (Visio / Démo / Devis / Signature).
 * Valeurs 0 à 5 pour rester sur un colonne smallint, comme les statuts du Cron.
 */
final class PipelineStatut
{
    public const NON_DEMARRE = 0;
    public const A_QUALIFIER = 1;
    public const RELANCER = 2;
    public const EN_ATTENTE = 3;
    public const OUI = 4;
    public const NON = 5;

    /**
     * Cycle des statuts au clic : NON_DEMARRE → A_QUALIFIER → RELANCER →
     * EN_ATTENTE → OUI → NON → NON_DEMARRE (boucle).
     */
    public const CYCLE = [
        self::NON_DEMARRE,
        self::A_QUALIFIER,
        self::RELANCER,
        self::EN_ATTENTE,
        self::OUI,
        self::NON,
    ];

    /**
     * Statut suivant dans le cycle. Boucle sur le premier après le dernier.
     */
    public static function suivant(int $actuel): int
    {
        $idx = array_search($actuel, self::CYCLE, true);
        if (false === $idx) {
            // Valeur hors cycle : on démarre au début.
            return self::CYCLE[0];
        }

        return self::CYCLE[($idx + 1) % count(self::CYCLE)];
    }

    /**
     * @var array<string, int>
     */
    public const LABELS = [
        'Non démarré' => self::NON_DEMARRE,
        'À qualifier' => self::A_QUALIFIER,
        'Relancer' => self::RELANCER,
        'En attente' => self::EN_ATTENTE,
        'Oui' => self::OUI,
        'Non' => self::NON,
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

    /**
     * Convertit une valeur libre de la feuille ("relancer", "OUI", "en attente"...)
     * en statut. Retourne null si la valeur est inconnue (à signaler en anomalie).
     */
    public static function fromRaw(?string $valeur): ?int
    {
        if (null === $valeur) {
            return self::NON_DEMARRE;
        }

        $nettoye = mb_strtolower(trim($valeur));
        $nettoye = str_replace(['é', 'è', 'ê', 'ë'], 'e', $nettoye);
        $nettoye = str_replace(['à', 'â', 'ä'], 'a', $nettoye);
        $nettoye = str_replace(['ù', 'û', 'ü'], 'u', $nettoye);
        $nettoye = str_replace(['î', 'ï'], 'i', $nettoye);
        $nettoye = str_replace(['ç'], 'c', $nettoye);
        $nettoye = str_replace(['-', '_'], ' ', $nettoye);

        return match ($nettoye) {
            '' => self::NON_DEMARRE,
            'oui', 'o', 'ok', 'fait', 'faite', 'realise', 'signe', 'gagne' => self::OUI,
            'non', 'n', 'refuse', 'perdu' => self::NON,
            'relancer', 'relance', 'a relancer', 'suivre' => self::RELANCER,
            'en attente', 'attente', 'pending' => self::EN_ATTENTE,
            'a qualifier', 'qualifier', 'a qualifie', 'todo' => self::A_QUALIFIER,
            default => null,
        };
    }
}
