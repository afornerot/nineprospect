<?php

namespace App\Service\Normalizer;

/**
 * Clé de regroupement d'un libellé libre : minuscules, sans accents ni
 * ponctuation. Deux libellés qui ne diffèrent que par la casse, les accents
 * ou les espaces produisent la même clé (« Appel », « appel », «  APPEL »
 * tombent ensemble).
 *
 * Utilisée pour regrouper les entreprises d'un import (`CompanyKey`) et les
 * étapes de pipeline qui portent le même nom dans des pipelines différents.
 */
final class LabelKey
{
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
}
