<?php

namespace App\Service\Normalizer;

/**
 * Normalisation et contrôle des emails de la feuille source.
 * Cas fréquents : minuscules/majuscules, deux emails collés, "#ERROR!".
 */
final class EmailNormalizer
{
    public function normalize(?string $brut): ?string
    {
        $valeur = mb_strtolower(trim((string) $brut));

        return '' === $valeur ? null : $valeur;
    }

    public function estValide(?string $email): bool
    {
        if (null === $email || '' === $email) {
            return false;
        }

        return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
    }

    /**
     * Motif d'anomalie si l'email est présent mais incorrect (null = vide ou OK).
     */
    public function anomalie(?string $brut): ?string
    {
        $valeur = trim((string) $brut);
        if ('' === $valeur) {
            return null;
        }

        if (substr_count($valeur, '@') > 1) {
            return 'email concaténé ('.substr_count($valeur, '@').' @)';
        }

        $normalise = $this->normalize($valeur);

        if (!$this->estValide($normalise)) {
            return 'email invalide';
        }

        return null;
    }
}
