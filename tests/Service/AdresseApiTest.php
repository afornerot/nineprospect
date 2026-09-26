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
}
