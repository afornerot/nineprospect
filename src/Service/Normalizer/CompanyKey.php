<?php

namespace App\Service\Normalizer;

/**
 * Clé de groupement d'une entreprise : normalisée (sans accents ni
 * ponctuation) pour que "SARL Granet Roger & Fils" et "sarl granet roger et fils"
 * tombent sur le même prospect.
 */
final class CompanyKey
{
    /**
     * Libellés qui désignent un particulier : chaque ligne devient alors un
     * prospect à part entière (pas de groupement).
     *
     * @var list<string>
     */
    private const PARTICULIERS = [
        'particulier',
        'a son compte',
        'independant',
        'retraite',
        'artisan',
        'auto entrepreneur',
        'autoentrepreneur',
        'salarie',
        'sans societe',
        'non renseigne',
    ];

    public static function for(string $libelle): string
    {
        $valeur = mb_strtolower(trim($libelle));
        if ('' === $valeur) {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $sansAccents = \Normalizer::normalize($valeur, \Normalizer::FORM_KD);
            if (is_string($sansAccents)) {
                $valeur = $sansAccents;
                $valeur = preg_replace('/\p{Mn}/u', '', $valeur) ?? $valeur;
            }
        }

        $valeur = mb_strtolower($valeur);
        $valeur = preg_replace('/[^a-z0-9]+/', ' ', $valeur) ?? $valeur;

        return trim(preg_replace('/\s+/', ' ', $valeur) ?? $valeur);
    }

    /**
     * true si le libellé d'entreprise désigne une personne physique.
     */
    public static function estParticulier(string $libelle): bool
    {
        return in_array(self::for($libelle), self::PARTICULIERS, true);
    }
}
