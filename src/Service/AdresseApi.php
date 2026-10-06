<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Géocodage via l'API Adresse du gouvernement français.
 *
 * Endpoint : https://api-adresse.data.gouv.fr/search
 * Utilisé après une mise à jour d'adresse pour recalculer lat/lon.
 *
 * Pas de clé API, throttling raisonnable (~50 req/s/IP).
 */
class AdresseApi
{
    private const API_URL = 'https://api-adresse.data.gouv.fr/search';

    /**
     * Code retour : un seul résultat, géocodage réussi.
     */
    public const RESULT_SINGLE = 'single';

    /**
     * Code retour : aucun résultat trouvé.
     */
    public const RESULT_NONE = 'none';

    /**
     * Code retour : plusieurs résultats possibles (géocodage ambigu, on n'assigne PAS).
     */
    public const RESULT_MULTIPLE = 'multiple';

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Géocode une adresse. Si l'API renvoie 0 résultat → null.
     * Si elle renvoie 1 résultat → ['lat' => float, 'lon' => float].
     * Si elle renvoie 2+ résultats → null (géocodage ambigu : on n'assigne pas de coordonnées).
     *
     * @return array{lat: float, lon: float}|null
     */
    public function geocode(string $adresse, string $codePostal, string $ville): ?array
    {
        $query = trim($adresse.' '.$codePostal.' '.$ville);
        if ('' === $query) {
            return null;
        }

        try {
            $response = $this->http->request('GET', self::API_URL, [
                'query' => [
                    'q' => $query,
                    'postcode' => $codePostal,
                    // On demande explicitement 2 résultats pour pouvoir détecter
                    // une ambiguïté : si l'API en renvoie 2+, on considère que
                    // l'adresse n'est pas localisable de manière certaine.
                    'limit' => 2,
                ],
                'timeout' => 5,
            ]);
            if (200 !== $response->getStatusCode()) {
                $this->logger->warning('API Adresse: HTTP {status} pour "{query}"', [
                    'status' => $response->getStatusCode(),
                    'query' => $query,
                ]);

                return null;
            }

            $data = $response->toArray(false);
            $features = $data['features'] ?? [];
            if (!\is_array($features) || [] === $features) {
                return null;
            }

            // Si plusieurs résultats : vérifier s'ils sont tous dans le bon CP + ville.
            // Si oui, c'est juste une ambiguïté de rue (ex. "Boulevard de la Marne"
            // existe à 2 endroits) → on prend le premier (score API le plus haut).
            // Si non (CP ou ville différents), l'adresse est réellement ambiguë :
            // on ne sait pas laquelle choisir → null.
            if (\count($features) > 1) {
                $allMatchCpVille = true;
                $hasCodePostal = '' !== $codePostal;
                $hasVille = '' !== $ville;
                foreach ($features as $f) {
                    $props = $f['properties'] ?? [];
                    if ($hasCodePostal && ($props['postcode'] ?? null) !== $codePostal) {
                        $allMatchCpVille = false;
                        break;
                    }
                    if ($hasVille) {
                        $city = (string) ($props['city'] ?? '');
                        if (mb_strtolower($city) !== mb_strtolower($ville)) {
                            $allMatchCpVille = false;
                            break;
                        }
                    }
                }
                if (!$allMatchCpVille) {
                    $this->logger->info('API Adresse: {count} résultats ambigus (CP/ville différents) pour "{query}"', [
                        'count' => \count($features),
                        'query' => $query,
                    ]);

                    return null;
                }
                // Tous les résultats ont le bon CP/ville → on prend le 1er
                $this->logger->info('API Adresse: {count} résultats dans le même CP/ville pour "{query}", prise du 1er', [
                    'count' => \count($features),
                    'query' => $query,
                ]);
            }

            $coords = $features[0]['geometry']['coordinates'] ?? null;
            if (!\is_array($coords) || \count($coords) < 2) {
                return null;
            }

            return [
                'lon' => (float) $coords[0],
                'lat' => (float) $coords[1],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('API Adresse: erreur réseau pour "{query}": {err}', [
                'query' => $query,
                'err' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Variante explicite : retourne un résultat enrichi avec le nombre de matches.
     * Utile quand l'appelant veut distinguer "0 résultat" de "plusieurs résultats".
     *
     * @return array{status: self::RESULT_*, coords: array{lat: float, lon: float}|null}
     */
    public function geocodeWithStatus(string $adresse, string $codePostal, string $ville): array
    {
        $query = trim($adresse.' '.$codePostal.' '.$ville);
        if ('' === $query) {
            return ['status' => self::RESULT_NONE, 'coords' => null];
        }

        try {
            $response = $this->http->request('GET', self::API_URL, [
                'query' => [
                    'q' => $query,
                    'postcode' => $codePostal,
                    'limit' => 2,
                ],
                'timeout' => 5,
            ]);
            if (200 !== $response->getStatusCode()) {
                return ['status' => self::RESULT_NONE, 'coords' => null];
            }

            $data = $response->toArray(false);
            $features = $data['features'] ?? [];
            if (!\is_array($features) || [] === $features) {
                return ['status' => self::RESULT_NONE, 'coords' => null];
            }

            if (\count($features) > 1) {
                // Tous les résultats ont le bon CP/ville → on accepte (rue ambiguë
                // dans la même ville, on prend le 1er). Sinon → résultat ambigu.
                $allMatchCpVille = true;
                $hasCodePostal = '' !== $codePostal;
                $hasVille = '' !== $ville;
                foreach ($features as $f) {
                    $props = $f['properties'] ?? [];
                    if ($hasCodePostal && ($props['postcode'] ?? null) !== $codePostal) {
                        $allMatchCpVille = false;
                        break;
                    }
                    if ($hasVille) {
                        $city = (string) ($props['city'] ?? '');
                        if (mb_strtolower($city) !== mb_strtolower($ville)) {
                            $allMatchCpVille = false;
                            break;
                        }
                    }
                }
                if (!$allMatchCpVille) {
                    return ['status' => self::RESULT_MULTIPLE, 'coords' => null];
                }
            }

            $coords = $features[0]['geometry']['coordinates'] ?? null;
            if (!\is_array($coords) || \count($coords) < 2) {
                return ['status' => self::RESULT_NONE, 'coords' => null];
            }

            return [
                'status' => self::RESULT_SINGLE,
                'coords' => [
                    'lon' => (float) $coords[0],
                    'lat' => (float) $coords[1],
                ],
            ];
        } catch (\Throwable $e) {
            $this->logger->error('API Adresse: erreur réseau pour "{query}": {err}', [
                'query' => $query,
                'err' => $e->getMessage(),
            ]);

            return ['status' => self::RESULT_NONE, 'coords' => null];
        }
    }
}
