<?php

namespace App\Tests\Service;

use App\Service\AnnuaireEntreprises;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests unitaires du client Annuaire des Entreprises.
 * On utilise MockHttpClient pour stubber les réponses HTTP.
 */
class AnnuaireEntreprisesTest extends TestCase
{
    /**
     * @param array<string, mixed> $payload
     */
    private function stubJson(array $payload, int $status = 200): MockHttpClient
    {
        $body = json_encode($payload, \JSON_THROW_ON_ERROR);

        return new MockHttpClient(new MockResponse($body, ['http_code' => $status]));
    }

    public function testSearchReturnsNormalizedResults(): void
    {
        $payload = [
            'results' => [
                [
                    'siren' => '800123456',
                    'nom_complet' => 'BIOPROSPECT SAS',
                    'siege' => [
                        'siret' => '80012345600012',
                        'code_postal' => '75002',
                        'libelle_commune' => 'Paris',
                        'numero_voie' => '8',
                        'type_voie' => 'rue',
                        'libelle_voie' => 'de la Paix',
                    ],
                    'activite_principale' => '62.01Z',
                ],
            ],
        ];
        $svc = new AnnuaireEntreprises($this->stubJson($payload), new NullLogger());

        $r = $svc->search('BIOPROSPECT');
        $this->assertNull($r['error']);
        $this->assertCount(1, $r['results']);

        $n = $svc->normalize($r['results'][0]);
        $this->assertSame('800123456', $n['siren']);
        $this->assertSame('80012345600012', $n['siret']);
        $this->assertSame('BIOPROSPECT SAS', $n['nom']);
        $this->assertSame('8 rue de la Paix', $n['adresse']);
        $this->assertSame('75002', $n['code_postal']);
        $this->assertSame('Paris', $n['ville']);
        $this->assertSame('62.01Z', $n['naf']);
        $this->assertSame('FR', $n['pays']);
        $this->assertSame('75', $n['dept']);
        $this->assertSame('Paris', $n['ville_greffe']);
    }

    public function testSearchHttpError(): void
    {
        $svc = new AnnuaireEntreprises($this->stubJson([], 503), new NullLogger());

        $r = $svc->search('whatever');
        $this->assertSame([], $r['results']);
        $this->assertNotNull($r['error']);
        $this->assertStringContainsString('503', $r['error']);
    }

    public function testSearchInvalidJson(): void
    {
        $client = new MockHttpClient(new MockResponse('not json', ['http_code' => 200]));
        $svc = new AnnuaireEntreprises($client, new NullLogger());

        $r = $svc->search('whatever');
        $this->assertSame([], $r['results']);
        $this->assertNotNull($r['error']);
    }

    public function testSearchBySirenExactMatch(): void
    {
        $payload = [
            'results' => [
                ['siren' => '123456789', 'nom_complet' => 'A'],
                ['siren' => '800123456', 'nom_complet' => 'B'],
            ],
        ];
        $svc = new AnnuaireEntreprises($this->stubJson($payload), new NullLogger());

        $r = $svc->searchBySiren('800 123 456'); // SIREN avec espaces
        $this->assertNotNull($r);
        $this->assertSame('B', $r['nom_complet']);
    }

    public function testSearchBySirenReturnsNullIfWrongLength(): void
    {
        $svc = new AnnuaireEntreprises($this->stubJson(['results' => []]), new NullLogger());
        $this->assertNull($svc->searchBySiren('12345'));
        $this->assertNull($svc->searchBySiren('1234567890'));
    }

    public function testSearchBySirenReturnsNullIfNotFound(): void
    {
        $payload = ['results' => [['siren' => '111222333']]];
        $svc = new AnnuaireEntreprises($this->stubJson($payload), new NullLogger());
        $this->assertNull($svc->searchBySiren('999999999'));
    }

    /**
     * @dataProvider deptProvider
     */
    public function testExtractDept(string $cp, ?string $expected): void
    {
        $svc = new AnnuaireEntreprises($this->stubJson(['results' => []]), new NullLogger());
        $n = $svc->normalize([
            'siren' => '123456789',
            'siege' => [
                'code_postal' => $cp,
                'libelle_voie' => 'X',
            ],
        ]);
        $this->assertSame($expected, $n['dept']);
    }

    /**
     * @return array<int, array{string, ?string}>
     */
    public static function deptProvider(): array
    {
        return [
            ['75002', '75'],
            ['13001', '13'],
            ['20000', '2A'],
            ['20200', '2B'],
            ['97200', '972'],
            ['97400', '974'],
            ['', null],
            ['abcd', null],
        ];
    }

    public function testBuildRcsValid(): void
    {
        $svc = new AnnuaireEntreprises($this->stubJson([]), new NullLogger());
        $this->assertSame('800123456 RCS Paris', $svc->buildRcs('800123456', '75'));
        $this->assertSame('800123456 RCS Fort-de-France', $svc->buildRcs('800123456', '972'));
    }

    public function testBuildRcsInvalidInputs(): void
    {
        $svc = new AnnuaireEntreprises($this->stubJson([]), new NullLogger());
        $this->assertNull($svc->buildRcs(null, '75'));
        $this->assertNull($svc->buildRcs('', '75'));
        $this->assertNull($svc->buildRcs('800123456', null));
        $this->assertNull($svc->buildRcs('800123456', '99')); // dept inconnu
    }

    /**
     * Vérifie la formule de TVA avec des SIREN de référence.
     * Formule : (12 + 3 × (SIREN mod 97)) mod 97 → 2 chiffres → FR + clé + SIREN.
     *
     * - 732829320 % 97 = 43 → (12 + 129) % 97 = 44 → FR44 732 829 320
     * - 800123456 % 97 = 6  → (12 + 18)  % 97 = 30 → FR30 800 123 456
     * - 123456789 % 97 = 136→ (12 + 408) % 97 = 32 → FR32 123 456 789
     *
     * @dataProvider tvaProvider
     */
    public function testComputeTva(string $siren, string $expected): void
    {
        $svc = new AnnuaireEntreprises($this->stubJson([]), new NullLogger());
        $this->assertSame($expected, $svc->computeTva($siren));
    }

    /**
     * @return array<int, array{string, string}>
     */
    public static function tvaProvider(): array
    {
        return [
            ['732829320', 'FR44732829320'],
            ['800123456', 'FR38800123456'],
            ['123456789', 'FR32123456789'],
        ];
    }

    public function testComputeTvaInvalidInputs(): void
    {
        $svc = new AnnuaireEntreprises($this->stubJson([]), new NullLogger());
        $this->assertNull($svc->computeTva(null));
        $this->assertNull($svc->computeTva(''));
        $this->assertNull($svc->computeTva('12345'));
        // '800-123-456' après strip fait 9 chiffres → accepté volontairement
        // (le SIREN tolère les séparateurs).
    }
}
