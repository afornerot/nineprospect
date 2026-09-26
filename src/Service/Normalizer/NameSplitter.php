<?php

namespace App\Service\Normalizer;

/**
 * Découpage prénom / nom.
 * La feuille source contient deux formes : colonne Prénom remplie (132 lignes)
 * ou nom complet dans la colonne Nom (820 lignes, ex. "Olivier CHIENNO").
 */
final class NameSplitter
{
    /**
     * @return array{prenom: string|null, nom: string, complet: string}
     */
    public static function split(?string $prenom, ?string $nom): array
    {
        $prenom = trim((string) $prenom);
        $nom = trim((string) $nom);

        if ('' === $prenom && '' === $nom) {
            return ['prenom' => null, 'nom' => '', 'complet' => ''];
        }

        if ('' !== $prenom) {
            return ['prenom' => $prenom, 'nom' => $nom, 'complet' => trim($prenom.' '.$nom)];
        }

        $mots = preg_split('/\s+/', $nom) ?: [];
        $mots = array_values(array_filter($mots, static fn (string $mot): bool => '' !== $mot));

        if (count($mots) <= 1) {
            return ['prenom' => null, 'nom' => $nom, 'complet' => $nom];
        }

        $majuscules = array_values(array_filter(
            $mots,
            static fn (string $mot): bool => mb_strlen($mot) > 1 && mb_strtoupper($mot, 'UTF-8') === $mot && 1 === preg_match('/\p{L}/u', $mot),
        ));

        // Un seul nom entièrement en majuscules = le nom de famille ("RENARD Pascal")
        if (1 === count($majuscules)) {
            $nomFamille = $majuscules[0];
            $autres = array_values(array_filter($mots, static fn (string $mot): bool => $mot !== $nomFamille));

            return [
                'prenom' => implode(' ', $autres),
                'nom' => $nomFamille,
                'complet' => $nom,
            ];
        }

        // Forme standard "Prénom Nom"
        return [
            'prenom' => $mots[0],
            'nom' => implode(' ', array_slice($mots, 1)),
            'complet' => $nom,
        ];
    }
}
