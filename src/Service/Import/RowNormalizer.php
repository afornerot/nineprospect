<?php

namespace App\Service\Import;

use App\Service\Normalizer\CompanyKey;
use App\Service\Normalizer\EmailNormalizer;
use App\Service\Normalizer\NameSplitter;
use App\Service\Normalizer\PhoneNormalizer;

/**
 * Transforme une ligne brute du tableur (array<string,string>) en ImportRow :
 *  - normalise les en-têtes (insensible casse/accents/espaces) ;
 *  - applique les normalizers (Email, Phone, Name) ;
 *  - calcule la cleEntreprise ;
 *  - collecte les erreurs par champ.
 */
final class RowNormalizer
{
    /**
     * Mapping nom d'en-tête normalisé => nom canonique.
     * Les clés sont stockées en lowercase + sans accents.
     *
     * @var array<string, string>
     */
    public const HEADER_MAP = [
        // Organisation (obligatoire)
        'organisation' => 'organisation',
        'organisme' => 'organisation',
        'entreprise' => 'organisation',
        'societe' => 'organisation',
        'société' => 'organisation',
        'nom de l\'entreprise' => 'organisation',
        'nom de lentreprise' => 'organisation',
        'nom de la societe' => 'organisation',
        'nom de la société' => 'organisation',
        'company' => 'organisation',

        // Nom (obligatoire)
        'nom' => 'nom',
        'name' => 'nom',

        // Prénom (obligatoire)
        'prenom' => 'prenom',
        'prénom' => 'prenom',
        'firstname' => 'prenom',
        'first name' => 'prenom',

        // Courriel (obligatoire)
        'courriel' => 'email',
        'email' => 'email',
        'e-mail' => 'email',
        'adresse e-mail' => 'email',
        'adresse email' => 'email',
        'mail' => 'email',

        // Fonction (facultatif)
        'fonction' => 'fonction',
        'poste' => 'fonction',
        'role' => 'fonction',
        'rôle' => 'fonction',
        'job' => 'fonction',

        // Téléphone (facultatif)
        'telephone' => 'telephone',
        'téléphone' => 'telephone',
        'tel' => 'telephone',
        'tél' => 'telephone',
        'n° de telephone' => 'telephone',
        'n de telephone' => 'telephone',
        'numero de telephone' => 'telephone',
        'phone' => 'telephone',

        // Adresse (facultatif)
        'adresse' => 'adresse',
        'address' => 'adresse',

        // Code postal (facultatif)
        'code postal' => 'codePostal',
        'cp' => 'codePostal',
        'postal code' => 'codePostal',
        'zip' => 'codePostal',

        // Ville (facultatif)
        'ville' => 'ville',
        'city' => 'ville',

        // Site web (facultatif)
        'site web' => 'siteWeb',
        'siteweb' => 'siteWeb',
        'site' => 'siteWeb',
        'site internet' => 'siteWeb',
        'website' => 'siteWeb',
        'url' => 'siteWeb',
    ];

    /**
     * Colonnes canoniques obligatoires (l'analyse rejette le fichier si une manque).
     *
     * @var list<string>
     */
    public const REQUIRED = ['organisation', 'nom', 'prenom', 'email'];

    public function __construct(
        private EmailNormalizer $emails,
        private PhoneNormalizer $telephones,
    ) {
    }

    /**
     * Retourne la liste des colonnes canoniques détectées dans le header.
     *
     * @param list<string> $header
     *
     * @return list<string>
     */
    public function mapHeader(array $header): array
    {
        $mapped = [];
        foreach ($header as $col) {
            $canonique = $this->canon($col);
            if (null !== $canonique && !in_array($canonique, $mapped, true)) {
                $mapped[] = $canonique;
            }
        }

        return $mapped;
    }

    /**
     * @param list<string> $detectedColumns
     *
     * @return list<string> noms canoniques des colonnes obligatoires manquantes
     */
    public function missingRequired(array $detectedColumns): array
    {
        return array_values(array_diff(self::REQUIRED, $detectedColumns));
    }

    /**
     * @param list<string> $header
     *
     * @return list<string> noms canoniques inconnus (présents dans l'en-tête mais pas dans HEADER_MAP)
     */
    public function unknownColumns(array $header): array
    {
        $known = array_values(self::HEADER_MAP);
        $unknown = [];
        foreach ($header as $col) {
            $canonique = $this->canon($col);
            if (null === $canonique && '' !== trim($col)) {
                $unknown[] = $col;
            }
        }

        return array_values(array_unique($unknown));
    }

    /**
     * Transforme une ligne brute en ImportRow.
     *
     * @param array<string, string> $rawRow
     */
    public function normalize(int $rowNumber, array $rawRow): ImportRow
    {
        $data = [];
        foreach ($rawRow as $key => $value) {
            $canonique = $this->canon($key);
            if (null === $canonique) {
                continue;
            }
            $data[$canonique] = (string) $value;
        }

        // Organisation
        $organisation = $this->trimOrNull($data['organisation'] ?? null);

        // Nom / Prénom : split si pas déjà splitté
        $nom = $this->trimOrNull($data['nom'] ?? null);
        $prenom = $this->trimOrNull($data['prenom'] ?? null);
        $split = NameSplitter::split($prenom, $nom);
        $nomSplit = $split['nom'];
        $nomFinal = '' !== $nomSplit ? $nomSplit : null;
        $prenomSplit = $split['prenom'];
        $prenomFinal = null;
        if (null !== $prenomSplit) {
            $prenomTrim = trim($prenomSplit);
            if ('' !== $prenomTrim) {
                $prenomFinal = $prenomTrim;
            }
        }
        $nomComplet = trim(($nomFinal ?? '').' '.($prenomFinal ?? ''));

        // Email
        $email = $this->emails->normalize($data['email'] ?? null);

        // Téléphone
        $telBrut = $this->trimOrNull($data['telephone'] ?? null);
        $tel = null !== $telBrut ? $this->telephones->normalize($telBrut) : null;

        // Adresse / CP / Ville / Site
        $adresse = $this->trimOrNull($data['adresse'] ?? null);
        $cp = $this->trimOrNull($data['codePostal'] ?? null);
        $ville = $this->trimOrNull($data['ville'] ?? null);
        $site = $this->trimOrNull($data['siteWeb'] ?? null);
        $fonction = $this->trimOrNull($data['fonction'] ?? null);

        // cleEntreprise
        if (null !== $organisation && !CompanyKey::estParticulier($organisation)) {
            $cleEntreprise = 'import:'.CompanyKey::for($organisation);
        } else {
            // Particulier : cleEntreprise dérivée du nom de la personne
            $cleEntreprise = 'import:particulier:'.CompanyKey::for($nomComplet ?: ($prenomFinal ?? '').' '.($nomFinal ?? ''));
        }

        // Erreurs par champ
        $errors = [];
        if (null === $organisation || '' === $organisation) {
            $errors['organisation'] = 'Organisation manquante';
        }
        if (null === $nomFinal) {
            if (null === $prenomFinal) {
                $errors['nom'] = 'Nom et prénom manquants';
            }
        }
        if (null === $email) {
            $errors['email'] = 'Email manquant';
        } else {
            $anomalie = $this->emails->anomalie($data['email'] ?? null);
            if (null !== $anomalie) {
                $errors['email'] = $anomalie;
            }
        }
        if (null !== $telBrut && null === $tel) {
            $errors['telephone'] = $this->telephones->anomalie($telBrut) ?? 'téléphone invalide';
        }
        if (null !== $cp && !preg_match('/^\d{5}$/', (string) $cp)) {
            $errors['codePostal'] = 'code postal invalide (attendu 5 chiffres)';
        }

        return new ImportRow(
            rowNumber: $rowNumber,
            organisation: $organisation,
            prenom: $prenomFinal,
            nom: $nomFinal,
            nomComplet: '' !== $nomComplet ? $nomComplet : null,
            email: $email,
            telephone: $tel,
            telephoneBrut: $telBrut,
            fonction: $fonction,
            adresse: $adresse,
            codePostal: $cp,
            ville: $ville,
            siteWeb: $site,
            cleEntreprise: $cleEntreprise,
            errors: $errors,
        );
    }

    /**
     * Normalise un libellé d'en-tête (lowercase, sans accents, espaces simples).
     */
    private function canon(string $label): ?string
    {
        $label = mb_strtolower(trim($label));
        $label = preg_replace('/\s+/', ' ', $label) ?? $label;
        if ('' === $label) {
            return null;
        }

        return self::HEADER_MAP[$label] ?? null;
    }

    private function trimOrNull(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }
        $value = trim($value);

        return '' === $value ? null : $value;
    }
}
