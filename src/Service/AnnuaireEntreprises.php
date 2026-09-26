<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP pour l'API Annuaire des Entreprises (data.gouv.fr).
 *
 * Endpoint : https://recherche-entreprises.api.gouv.fr/search
 * Pas de clé API requise, throttling implicite ~30 req/min/IP.
 *
 * Champs normalisés retournés (cf. {@see normalize()}):
 *   - siren, siret, nom, adresse, code_postal, ville, naf, pays, lat, lon
 *
 * Champs calculés localement (pas dans la réponse API):
 *   - rcsRm via {@see buildRcs()}
 *   - numTva via {@see computeTva()}
 *
 * Tous les points d'entrée publics sont safe face à une API indisponible :
 * ils renvoient une structure `['results' => [], 'error' => '...']`
 * plutôt que de lever une exception.
 */
class AnnuaireEntreprises
{
    private const API_URL = 'https://recherche-entreprises.api.gouv.fr/search';
    private const PER_PAGE = 10;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Recherche full-text. Le query doit être pré-construit (nom + CP).
     *
     * @return array{results: array<int, array<string, mixed>>, error: ?string}
     */
    public function search(string $query, ?string $codePostal = null): array
    {
        $params = ['q' => $query, 'per_page' => self::PER_PAGE];
        if (null !== $codePostal && '' !== $codePostal) {
            $params['code_postal'] = $codePostal;
        }

        try {
            $response = $this->http->request('GET', self::API_URL, [
                'query' => $params,
                'timeout' => 5,
            ]);
            $status = $response->getStatusCode();
            if (200 !== $status) {
                $this->logger->warning('Annuaire Entreprises: HTTP {status} pour "{query}"', [
                    'status' => $status,
                    'query' => $query,
                ]);

                return ['results' => [], 'error' => sprintf('HTTP %d', $status)];
            }

            $data = $response->toArray(false);
            $results = $data['results'] ?? [];
            if (!\is_array($results)) {
                return ['results' => [], 'error' => null];
            }

            return ['results' => array_values($results), 'error' => null];
        } catch (\Throwable $e) {
            $this->logger->error('Annuaire Entreprises: erreur réseau pour "{query}": {err}', [
                'query' => $query,
                'err' => $e->getMessage(),
            ]);

            return ['results' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * Recherche par SIREN exact (le query est le SIREN, on filtre ensuite).
     * Retourne le premier résultat dont `siren` correspond exactement,
     * ou null si aucun.
     *
     * @return array<string, mixed>|null
     */
    public function searchBySiren(string $siren): ?array
    {
        $siren = preg_replace('/\D+/', '', $siren) ?? '';
        if (9 !== \strlen($siren)) {
            return null;
        }
        $response = $this->search($siren);
        foreach ($response['results'] as $row) {
            $rowSiren = preg_replace('/\D+/', '', (string) ($row['siren'] ?? '')) ?? '';
            if ($rowSiren === $siren) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Normalise une réponse brute de l'API en tableau plat utilisable par
     * l'entité Prospect.
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *   siren: ?string, siret: ?string, nom: ?string,
     *   adresse: ?string, code_postal: ?string, ville: ?string,
     *   naf: ?string, pays: string,
     *   lat: ?float, lon: ?float,
     *   dept: ?string, ville_greffe: ?string
     * }
     */
    public function normalize(array $row): array
    {
        $siren = isset($row['siren']) ? preg_replace('/\D+/', '', (string) $row['siren']) : null;

        $siege = \is_array($row['siege'] ?? null) ? $row['siege'] : [];
        $siret = $siege['siret'] ?? null;
        $cp = $siege['code_postal'] ?? null;
        $ville = $siege['libelle_commune'] ?? null;

        // Adresse :拼接 des composants.
        $adresse = $this->buildAdresse(
            $siege['numero_voie'] ?? null,
            $siege['type_voie'] ?? null,
            $siege['libelle_voie'] ?? null,
        );

        // NAF/APE.
        $naf = $row['activite_principale'] ?? null;

        // Coordonnées GPS (souvent absentes de l'API). Si absentes, on
        // tombera sur un re-géocodage via AdresseApi.
        $lat = null;
        $lon = null;
        $coords = $siege['coordinates'] ?? null;
        if (\is_array($coords) && \count($coords) >= 2) {
            // L'API renvoie [lon, lat].
            $lon = isset($coords[0]) ? (float) $coords[0] : null;
            $lat = isset($coords[1]) ? (float) $coords[1] : null;
        }

        // Code département : 2A, 2B ou 2 chiffres métro, 3 chiffres DOM.
        $dept = $this->extractDept($cp);
        $villeGreffe = null !== $dept ? self::DEPT_TO_GREFFE[$dept] ?? null : null;

        return [
            'siren' => $siren,
            'siret' => null !== $siret ? (string) $siret : null,
            'nom' => isset($row['nom_complet']) ? (string) $row['nom_complet'] : (isset($row['nom']) ? (string) $row['nom'] : null),
            'adresse' => $adresse,
            'code_postal' => null !== $cp ? (string) $cp : null,
            'ville' => null !== $ville ? (string) $ville : null,
            'naf' => null !== $naf ? (string) $naf : null,
            'pays' => 'FR',
            'lat' => $lat,
            'lon' => $lon,
            'dept' => $dept,
            'ville_greffe' => $villeGreffe,
        ];
    }

    /**
     * Construit la valeur du champ `rcsRm` à partir du SIREN et du code
     * département (greffe du tribunal de commerce).
     *
     * Format : `<SIREN> RCS <VILLE_GREFFE>`.
     * Retourne null si le SIREN ou le département ne permettent pas le calcul.
     */
    public function buildRcs(?string $siren, ?string $deptCode): ?string
    {
        if (null === $siren || '' === $siren) {
            return null;
        }
        if (null === $deptCode || '' === $deptCode) {
            return null;
        }
        $villeGreffe = self::DEPT_TO_GREFFE[$deptCode] ?? null;
        if (null === $villeGreffe) {
            return null;
        }

        return $siren.' RCS '.$villeGreffe;
    }

    /**
     * Calcul de la TVA intracommunautaire française à partir du SIREN.
     *
     * Formule : `FR` + `((12 + 3 × (SIREN mod 97)) mod 97)` formaté sur 2 chiffres.
     *
     * @see https://www.service-public.fr/professionnels-entreprises/vosdroits/F23570
     */
    public function computeTva(?string $siren): ?string
    {
        if (null === $siren) {
            return null;
        }
        $clean = preg_replace('/\D+/', '', $siren) ?? '';
        if (9 !== \strlen($clean)) {
            return null;
        }
        $sirenInt = (int) $clean;
        $cle = (12 + 3 * ($sirenInt % 97)) % 97;

        // Format français : FR + 2 chiffres de clé + SIREN (13 caractères).
        return 'FR'.str_pad((string) $cle, 2, '0', \STR_PAD_LEFT).$clean;
    }

    /**
     * Concatène les composants d'adresse en une seule chaîne.
     */
    private function buildAdresse(?string $numero, ?string $type, ?string $libelle): ?string
    {
        $parts = array_filter([$numero, $type, $libelle], static fn ($v) => null !== $v && '' !== trim((string) $v));
        if ([] === $parts) {
            return null;
        }

        return trim(implode(' ', $parts));
    }

    /**
     * Extrait le code département d'un code postal français.
     * - Métropole : 2 chiffres.
     * - Corse : 2A si CP ∈ [20000, 20199], 2B si CP ∈ [20200, 20299].
     * - DOM : 3 chiffres (97x / 98x).
     *
     * Retourne null si le code postal est vide / invalide.
     */
    private function extractDept(?string $cp): ?string
    {
        if (null === $cp || '' === $cp) {
            return null;
        }
        // Corse : distinguer 2A / 2B par la plage de codes postaux (20000-20199 vs 20200-20299).
        if (preg_match('/^(20[0-1]\d{2})/', $cp)) {
            return '2A';
        }
        if (preg_match('/^(202\d{2})/', $cp)) {
            return '2B';
        }
        if (preg_match('/^(97[0-9]|98[0-9])/', $cp, $m)) {
            return $m[1];
        }
        if (preg_match('/^(\d{2})/', $cp, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Table de correspondance département → ville du greffe du tribunal de
     * commerce. Source : annuaire des greffes (CCI / Tribunal de commerce).
     *
     * Les départements non référencés (TOM, étranger) retournent null au
     * calcul du RCS.
     */
    private const DEPT_TO_GREFFE = [
        // Métropole
        '01' => 'Bourg-en-Bresse', '02' => 'Saint-Quentin', '03' => 'Cusset',
        '04' => 'Manosque', '05' => 'Gap', '06' => 'Nice',
        '07' => 'Privas', '08' => 'Charleville-Mézières', '09' => 'Foix',
        '10' => 'Troyes', '11' => 'Carcassonne', '12' => 'Rodez',
        '13' => 'Marseille', '14' => 'Caen', '15' => 'Aurillac',
        '16' => 'Angoulême', '17' => 'Saintes', '18' => 'Bourges',
        '19' => 'Brive-la-Gaillarde', '2A' => 'Ajaccio', '2B' => 'Bastia',
        '21' => 'Dijon', '22' => 'Saint-Brieuc', '23' => 'Guéret',
        '24' => 'Bergerac', '25' => 'Besançon', '26' => 'Romans-sur-Isère',
        '27' => 'Évreux', '28' => 'Chartres', '29' => 'Quimper',
        '30' => 'Nîmes', '31' => 'Toulouse', '32' => 'Auch',
        '33' => 'Bordeaux', '34' => 'Montpellier', '35' => 'Rennes',
        '36' => 'Châteauroux', '37' => 'Tours', '38' => 'Grenoble',
        '39' => 'Lons-le-Saunier', '40' => 'Mont-de-Marsan', '41' => 'Blois',
        '42' => 'Saint-Étienne', '43' => 'Le Puy-en-Velay', '44' => 'Nantes',
        '45' => 'Orléans', '46' => 'Cahors', '47' => 'Agen',
        '48' => 'Mende', '49' => 'Angers', '50' => 'Coutances',
        '51' => 'Reims', '52' => 'Chaumont', '53' => 'Laval',
        '54' => 'Nancy', '55' => 'Bar-le-Duc', '56' => 'Vannes',
        '57' => 'Metz', '58' => 'Nevers', '59' => 'Lille',
        '60' => 'Beauvais', '61' => 'Alençon', '62' => 'Arras',
        '63' => 'Clermont-Ferrand', '64' => 'Pau', '65' => 'Tarbes',
        '66' => 'Perpignan', '67' => 'Strasbourg', '68' => 'Colmar',
        '69' => 'Lyon', '70' => 'Vesoul', '71' => 'Chalon-sur-Saône',
        '72' => 'Le Mans', '73' => 'Chambéry', '74' => 'Annecy',
        '75' => 'Paris', '76' => 'Rouen', '77' => 'Melun',
        '78' => 'Versailles', '79' => 'Niort', '80' => 'Amiens',
        '81' => 'Albi', '82' => 'Montauban', '83' => 'Draguignan',
        '84' => 'Avignon', '85' => 'La Roche-sur-Yon', '86' => 'Poitiers',
        '87' => 'Limoges', '88' => 'Épinal', '89' => 'Auxerre',
        '90' => 'Belfort', '91' => 'Évry', '92' => 'Nanterre',
        '93' => 'Bobigny', '94' => 'Créteil', '95' => 'Pontoise',
        // DOM
        '971' => 'Basse-Terre', '972' => 'Fort-de-France',
        '973' => 'Cayenne', '974' => 'Saint-Denis',
        '976' => 'Mamoudzou',
    ];
}
