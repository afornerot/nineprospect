<?php

namespace App\Tests\Service;

use App\Service\AdresseApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class AdresseApiTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function stubJson(array $payload, int $status = 200): MockHttpClient
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);

        return new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
    }

    public function testGeocodeReturnsCoords(): void
    {
        $payload = [
            'features' => [
                [
                    'geometry' => [
                        'coordinates' => [2.3522, 48.8566], // [lon, lat]
                    ],
                ],
            ],
        ];
        $svc = new AdresseApi($this->stubJson($payload), new NullLogger());

        $r = $svc->geocode('8 rue de la Paix', '75002', 'Paris');
        $this->assertNotNull($r);
        $this->assertSame(48.8566, $r['lat']);
        $this->assertSame(2.3522, $r['lon']);
    }

    public function testGeocodeReturnsNullIfNoFeature(): void
    {
        $svc = new AdresseApi($this->stubJson(['features' => []]), new NullLogger());
        $this->assertNull($svc->geocode('XXX', '99999', 'NOWHERE'));
    }

    public function testGeocodeReturnsNullIfEmptyQuery(): void
    {
        $svc = new AdresseApi($this->stubJson([]), new NullLogger());
        $this->assertNull($svc->geocode('', '', ''));
        $this->assertNull($svc->geocode('   ', '   ', '   '));
    }

    public function testGeocodeReturnsNullOnHttpError(): void
    {
        $svc = new AdresseApi($this->stubJson([], 500), new NullLogger());
        $this->assertNull($svc->geocode('rue', 'cp', 'ville'));
    }

    public function testGeocodeReturnsNullOnAmbiguousResults(): void
    {
        // 2+ résultats AVEC des CP/ville différents → adresse réellement ambiguë
        // (l'utilisateur devra préciser ou saisir manuellement).
        $payload = [
            'features' => [
                [
                    'geometry' => ['coordinates' => [2.3522, 48.8566]],
                    'properties' => ['postcode' => '75002', 'city' => 'Paris'],
                ],
                [
                    'geometry' => ['coordinates' => [2.3533, 48.8577]],
                    'properties' => ['postcode' => '69001', 'city' => 'Lyon'],
                ],
            ],
        ];
        $svc = new AdresseApi($this->stubJson($payload), new NullLogger());
        $this->assertNull($svc->geocode('rue de la Paix', '75002', 'Paris'));
    }

    public function testGeocodeAcceptsSameCpVilleWhenMultipleStreets(): void
    {
        // Cas vécu en prod : 2 rues avec le même nom dans la même ville
        // (ex. "Boulevard de la Marne" à Auxerre). On prend la 1ère (score API).
        $payload = [
            'features' => [
                [
                    'geometry' => ['coordinates' => [3.560991, 47.810933]],
                    'properties' => ['postcode' => '89000', 'city' => 'Auxerre'],
                ],
                [
                    'geometry' => ['coordinates' => [3.562, 47.811]],
                    'properties' => ['postcode' => '89000', 'city' => 'Auxerre'],
                ],
            ],
        ];
        $svc = new AdresseApi($this->stubJson($payload), new NullLogger());
        $r = $svc->geocode('18 Boulevard de la Marne', '89000', 'Auxerre');
        $this->assertNotNull($r);
        $this->assertSame(47.810933, $r['lat']);
        $this->assertSame(3.560991, $r['lon']);
    }

    public function testGeocodeWithStatusSingle(): void
    {
        $payload = [
            'features' => [
                ['geometry' => ['coordinates' => [2.3522, 48.8566]]],
            ],
        ];
        $svc = new AdresseApi($this->stubJson($payload), new NullLogger());
        $r = $svc->geocodeWithStatus('rue de la Paix', '75002', 'Paris');
        $this->assertSame(AdresseApi::RESULT_SINGLE, $r['status']);
        $this->assertNotNull($r['coords']);
        $this->assertSame(48.8566, $r['coords']['lat']);
    }

    public function testGeocodeWithStatusMultiple(): void
    {
        // 2+ résultats AVEC des CP/ville différents → adresse réellement ambiguë.
        $payload = [
            'features' => [
                [
                    'geometry' => ['coordinates' => [2.3522, 48.8566]],
                    'properties' => ['postcode' => '75002', 'city' => 'Paris'],
                ],
                [
                    'geometry' => ['coordinates' => [2.3533, 48.8577]],
                    'properties' => ['postcode' => '69001', 'city' => 'Lyon'],
                ],
            ],
        ];
        $svc = new AdresseApi($this->stubJson($payload), new NullLogger());
        $r = $svc->geocodeWithStatus('rue de la Paix', '75002', 'Paris');
        $this->assertSame(AdresseApi::RESULT_MULTIPLE, $r['status']);
        $this->assertNull($r['coords']);
    }

    public function testGeocodeWithStatusSingleWhenMultipleInSameCity(): void
    {
        // 2 résultats même ville = on prend le 1er → SINGLE (pas MULTIPLE).
        $payload = [
            'features' => [
                [
                    'geometry' => ['coordinates' => [3.560991, 47.810933]],
                    'properties' => ['postcode' => '89000', 'city' => 'Auxerre'],
                ],
                [
                    'geometry' => ['coordinates' => [3.562, 47.811]],
                    'properties' => ['postcode' => '89000', 'city' => 'Auxerre'],
                ],
            ],
        ];
        $svc = new AdresseApi($this->stubJson($payload), new NullLogger());
        $r = $svc->geocodeWithStatus('18 Boulevard de la Marne', '89000', 'Auxerre');
        $this->assertSame(AdresseApi::RESULT_SINGLE, $r['status']);
        $this->assertNotNull($r['coords']);
        $this->assertSame(47.810933, $r['coords']['lat']);
    }

    public function testGeocodeWithStatusNone(): void
    {
        $svc = new AdresseApi($this->stubJson(['features' => []]), new NullLogger());
        $r = $svc->geocodeWithStatus('XXX', '99999', 'NOWHERE');
        $this->assertSame(AdresseApi::RESULT_NONE, $r['status']);
        $this->assertNull($r['coords']);
    }
}
