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

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Géocode une adresse et retourne les coordonnées du premier résultat
     * le plus pertinent. Si l'API ne trouve rien ou échoue, retourne null.
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
                    'limit' => 1,
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
}
