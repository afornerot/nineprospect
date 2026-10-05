<?php

namespace App\Service\Import;

/**
 * Une ligne brute du tableur, après normalisation et détection d'erreurs.
 * Le regroupement par organisation est calculé par {@see ImportAnalyzer}.
 *
 * Une ligne représente **un Contact** (pas un Prospect) ; le Prospect est
 * constitué du regroupement de plusieurs lignes sur la même cleEntreprise.
 */
final class ImportRow
{
    public function __construct(
        /** Numéro de ligne 1-based dans le tableur (en-tête = 1). */
        public readonly int $rowNumber,
        /** Libellé d'entreprise brut (Organisation). */
        public readonly ?string $organisation,
        /** Prénom du contact. */
        public readonly ?string $prenom,
        /** Nom du contact (peut être null si juste le prénom). */
        public readonly ?string $nom,
        /** Nom complet reconstitué (prenom + nom). */
        public readonly ?string $nomComplet,
        /** Email normalisé (lowercase + trim). */
        public readonly ?string $email,
        /** Téléphone formaté E.164 ou null si non normalisable. */
        public readonly ?string $telephone,
        /** Téléphone brut (pour Contact::telephoneBrut). */
        public readonly ?string $telephoneBrut,
        public readonly ?string $fonction,
        public readonly ?string $adresse,
        public readonly ?string $codePostal,
        public readonly ?string $ville,
        public readonly ?string $siteWeb,
        /** Clé normalisée de l'organisation (pour regroupement). */
        public readonly string $cleEntreprise,
        /** @var array<string, string> */
        public readonly array $errors = [],
        /** true si la ligne ne peut pas être importée du tout. */
        public readonly bool $headerValid = true,
    ) {
    }

    public function hasErrors(): bool
    {
        return [] !== $this->errors;
    }

    public function firstError(): ?string
    {
        return $this->errors[array_key_first($this->errors)] ?? null;
    }
}
