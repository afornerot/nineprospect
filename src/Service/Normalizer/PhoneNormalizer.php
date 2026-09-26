<?php

namespace App\Service\Normalizer;

/**
 * Normalisation des téléphones hétérogènes de la feuille source
 * ("#ERROR!", "06 70 80 75 33", "33699440163", "p:+33673391400", concaténations).
 */
final class PhoneNormalizer
{
    /**
     * Format international. null si le numéro est inexploitable.
     */
    public function normalize(?string $brut): ?string
    {
        $valeur = trim((string) $brut);
        if ('' === $valeur) {
            return null;
        }

        $valeur = preg_replace('/^p:\s*/i', '', $valeur) ?? $valeur;
        $signePlus = str_starts_with($valeur, '+');
        $chiffres = preg_replace('/\D+/', '', $valeur) ?? '';

        if ('' === $chiffres) {
            return null;
        }

        if (strlen($chiffres) > 15) {
            return null;
        }

        $international = match (true) {
            $signePlus => '+'.$chiffres,
            str_starts_with($chiffres, '0033') => '+33'.substr($chiffres, 4),
            11 === strlen($chiffres) && str_starts_with($chiffres, '33') => '+33'.substr($chiffres, 2),
            10 === strlen($chiffres) && str_starts_with($chiffres, '0') => '+33'.substr($chiffres, 1),
            9 === strlen($chiffres) && !str_starts_with($chiffres, '0') => '+33'.$chiffres,
            default => '+'.$chiffres,
        };

        return preg_match('/^\+\d{6,15}$/', $international) ? $international : null;
    }

    /**
     * Motif d'anomalie si le numéro posait problème (null = vide ou correct).
     */
    public function anomalie(?string $brut): ?string
    {
        $valeur = trim((string) $brut);
        if ('' === $valeur) {
            return null;
        }

        if (str_contains($valeur, '#ERROR')) {
            return 'téléphone invalide (#ERROR!)';
        }

        $chiffres = preg_replace('/\D+/', '', preg_replace('/^p:\s*/i', '', $valeur) ?? $valeur) ?? '';

        if ('' === $chiffres) {
            return 'téléphone sans chiffre';
        }

        if (strlen($chiffres) > 15) {
            return 'téléphone concaténé ('.strlen($chiffres).' chiffres)';
        }

        if (strlen($chiffres) < 6) {
            return 'téléphone trop court';
        }

        return null;
    }
}
