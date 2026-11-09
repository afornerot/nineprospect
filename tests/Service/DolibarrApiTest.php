<?php

namespace App\Tests\Service;

use App\Service\DolibarrApi;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests unitaires du client Dolibarr (lecture seule).
 * Réponses HTTP stubbées via MockHttpClient, cache en mémoire.
 */
class DolibarrApiTest extends TestCase
{
    /**
     * @param list<callable|MockResponse> $responses
     */
    private function service(array $responses, string $url = 'https://doli.example/dolibarr', string $key = 'cle-test'): DolibarrApi
    {
        return new DolibarrApi(
            new MockHttpClient($responses),
            new NullLogger(),
            new ArrayAdapter(),
            $url,
            $key,
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload, int $status = 200): MockResponse
    {
        return new MockResponse(json_encode($payload, \JSON_THROW_ON_ERROR), ['http_code' => $status]);
    }

    public function testNonConfigureSansUrlOuCle(): void
    {
        $this->assertFalse($this->service([], url: '', key: '')->isConfigured());
        $this->assertFalse($this->service([], url: 'https://doli.example', key: '')->isConfigured());

        $api = $this->service([], url: '', key: '');
        $r = $api->search('ineo');
        $this->assertSame([], $r['results']);
        $this->assertNotNull($r['error']);
        $this->assertNull($api->findById('1'));
        $this->assertNull($api->cardUrl('1'));
    }

    public function testSearchConstruitUnFiltreAssaini(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url) use (&$captured) {
            $captured = [$method, $url];

            return new MockResponse('[]', ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $api->search("  d'art _ineo%  ");

        $this->assertNotNull($captured);
        $this->assertSame('GET', $captured[0]);
        $this->assertStringContainsString('https://doli.example/dolibarr/api/index.php/thirdparties', $captured[1]);
        // Les caractères cassant le filtre SQL (`'`, `\`) ou élargissant le
        // LIKE (`%`, `_`) sont remplacés par des séparateurs : le motif ne
        // contient plus que les mots saisis.
        parse_str((string) parse_url($captured[1], \PHP_URL_QUERY), $query);
        $this->assertIsString($query['sqlfilters'] ?? null);
        $filtre = (string) $query['sqlfilters'];
        $this->assertStringContainsString("(t.nom:like:'%d%art%ineo%')", $filtre);
        $this->assertStringContainsString("(t.code_client:like:'%d%art%ineo%')", $filtre);
        $this->assertStringContainsString("(t.code_fournisseur:like:'%d%art%ineo%')", $filtre);
    }

    public function testSearchVideNeSollicitePasLApi(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels) {
            ++$appels;

            return new MockResponse('[]', ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $r = $api->search(" ' \\ % _ ");
        $this->assertSame([], $r['results']);
        $this->assertNull($r['error']);
        $this->assertSame(0, $appels, 'Un critère vide ne doit pas émettre de requête.');
    }

    public function testSearch404EstUnResultatVideSansErreur(): void
    {
        $api = $this->service([
            $this->json(['error' => ['code' => 404, 'message' => 'Not Found: No third parties found']], 404),
        ]);

        $r = $api->search('inconnu');
        $this->assertSame([], $r['results']);
        $this->assertNull($r['error'], '404 = aucun résultat, pas une panne.');
    }

    public function testSearchRemonteUneErreurServeur(): void
    {
        $api = $this->service([
            $this->json(['error' => ['code' => 503, 'message' => 'Service Unavailable']], 503),
        ]);

        $r = $api->search('ineo');
        $this->assertSame([], $r['results']);
        $this->assertSame('Service Unavailable', $r['error']);
    }

    public function testFindByIdNormaliseEtMetEnCache(): void
    {
        $appels = 0;
        $client = new MockHttpClient(function () use (&$appels) {
            ++$appels;

            return new MockResponse(json_encode([
                'id' => '373',
                'name' => 'INEO Réseaux Nord Est',
                'name_alias' => 'INEO',
                'email' => 'contact@ineo.example',
                'code_client' => 'CLI001',
                'code_fournisseur' => '',
                'idprof1' => '123456789',
                'address' => '46C Rue Paul Sabatier',
                'zip' => '71530',
                'town' => 'Crissey',
                'country_code' => 'FR',
                'client' => '1',
                'fournisseur' => '3',
            ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $tiers = $api->findById('373');
        $this->assertIsArray($tiers);
        $this->assertSame('373', $tiers['id']);
        $this->assertSame('INEO Réseaux Nord Est', $tiers['nom']);
        $this->assertSame('INEO', $tiers['alias']);
        $this->assertSame('CLI001', $tiers['codeClient']);
        $this->assertNull($tiers['codeFournisseur']);
        $this->assertSame('123456789', $tiers['siren']);
        $this->assertSame('71530', $tiers['codePostal']);
        $this->assertSame(['Client', 'Fournisseur/Sous-traitant'], $tiers['types']);

        // Deuxième appel : servi par le cache, pas de nouvelle requête.
        $this->assertSame($tiers, $api->findById('373'));
        $this->assertSame(1, $appels);
    }

    public function testFindByIdRefuseUnIdentifiantNonNumerique(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels) {
            ++$appels;

            return new MockResponse('{}', ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $this->assertNull($api->findById('12abc'));
        $this->assertNull($api->findById(''));
        $this->assertSame(0, $appels);
    }

    public function testFindByIdIntrouvableRetourneNullSansErreur(): void
    {
        $api = $this->service([
            $this->json(['error' => ['code' => 404, 'message' => 'Not Found: Thirdparty not found']], 404),
        ]);

        $this->assertNull($api->findById('999999'));
    }

    public function testCardUrl(): void
    {
        $api = $this->service([]);
        $this->assertSame(
            'https://doli.example/dolibarr/societe/card.php?socid=373',
            $api->cardUrl('373'),
        );
        $this->assertNull($api->cardUrl(''));
        $this->assertNull($api->cardUrl(null));
    }

    public function testDocumentsExcluentLesBrouillonsEtNormalisent(): void
    {
        $urls = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$urls) {
            $urls[] = $url;
            $type = preg_match('#/invoices#', $url) ? 'invoice' : (preg_match('#/orders#', $url) ? 'order' : 'proposal');

            $row = match ($type) {
                'invoice' => [
                    'id' => '346', 'ref' => 'AC1604-0015', 'type' => '3', 'statut' => '2',
                    'date' => 1461628800, 'total_ht' => '12128.00000000', 'total_ttc' => '14553.60000000',
                    'paye' => '1', 'ref_client' => null,
                ],
                'order' => [
                    'id' => '1', 'ref' => 'CO1709-0001', 'statut' => '3', 'date' => 1505433600,
                    'total_ht' => '12600.00000000', 'total_ttc' => '15120.00000000',
                    'paye' => null, 'ref_client' => 'bc1404000592',
                ],
                default => [
                    'id' => '2', 'ref' => 'PR1110-0002', 'statut' => 4, 'date' => 1318377600,
                    'total_ht' => '3990.00000000', 'total_ttc' => '4772.04000000',
                    'paye' => null, 'ref_client' => '',
                ],
            };

            return new MockResponse(json_encode([$row], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $documents = $api->documentsForThirdparty('2');

        $this->assertNull($documents['error']);
        $this->assertCount(3, $urls);
        foreach ($urls as $url) {
            $query = [];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->assertSame('(t.fk_soc:=:2) and (t.fk_statut:>:0)', $query['sqlfilters'], 'Les brouillons (fk_statut = 0) sont exclus.');
            $this->assertSame('t.rowid', $query['sortfield']);
            $this->assertSame('DESC', $query['sortorder']);
        }

        $devis = $documents['proposals'][0];
        $this->assertSame('Devis', $devis['type']);
        $this->assertNull($devis['typeLabel']);
        $this->assertSame('PR1110-0002', $devis['ref']);
        $this->assertSame('2011-10-12', $devis['date']);
        $this->assertSame('Archivée', $devis['statutLabel']);
        $this->assertSame('dark', $devis['statutCouleur']);
        $this->assertSame(3990.0, $devis['totalHt']);
        $this->assertSame('https://doli.example/dolibarr/comm/propal/card.php?id=2', $devis['url']);
        $this->assertArrayNotHasKey('totalTtc', $devis, 'Le TTC n\'est plus exposé.');
        $this->assertArrayNotHasKey('refClient', $devis, 'La réf. client n\'est plus exposée.');

        $commande = $documents['orders'][0];
        $this->assertSame('Réceptionnée', $commande['statutLabel']);
        $this->assertSame('https://doli.example/dolibarr/commande/card.php?id=1', $commande['url']);

        $facture = $documents['invoices'][0];
        $this->assertSame('Acompte', $facture['typeLabel'], 'Le sous-type de facture est nommé.');
        $this->assertSame('Payée', $facture['statutLabel']);
        $this->assertSame(12128.0, $facture['totalHt']);
        $this->assertSame('https://doli.example/dolibarr/compta/facture/card.php?facid=346', $facture['url']);
    }

    public function testDocumentsSansLiaisonNeSollicitentPasLApi(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels) {
            ++$appels;

            return new MockResponse('[]', ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $documents = $api->documentsForThirdparty('');
        $this->assertSame(['proposals' => [], 'orders' => [], 'invoices' => [], 'error' => null], $documents);
        $this->assertSame(0, $appels);
    }

    public function testUnePannePartielleNeMasquePasLesAutresTypes(): void
    {
        $client = new MockHttpClient(static function (string $method, string $url) {
            if (preg_match('#/orders#', $url)) {
                return new MockResponse(
                    json_encode(['error' => ['code' => 503, 'message' => 'Service Unavailable']], \JSON_THROW_ON_ERROR),
                    ['http_code' => 503],
                );
            }

            return new MockResponse(
                json_encode([['id' => '1', 'ref' => 'X', 'statut' => '1', 'date' => 1505433600]], \JSON_THROW_ON_ERROR),
                ['http_code' => 200],
            );
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $documents = $api->documentsForThirdparty('2');
        $this->assertSame('Service Unavailable', $documents['error']);
        $this->assertCount(1, $documents['proposals']);
        $this->assertSame([], $documents['orders']);
        $this->assertCount(1, $documents['invoices']);
    }

    public function testDocumentsSontMisEnCache(): void
    {
        $appels = 0;
        $client = new MockHttpClient(static function () use (&$appels) {
            ++$appels;

            return new MockResponse('[]', ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $api->documentsForThirdparty('2');
        $api->documentsForThirdparty('2');
        $this->assertSame(3, $appels, 'Un seul aller-retour par type, puis cache.');
    }

    public function testDocumentsSontRecuperesSansLimite(): void
    {
        // Le serveur Dolibarr plafonne chaque page : on simule 120 devis
        // (100 + 20) → la pagination doit tout récupérer, pas les 10 derniers.
        $pagesDemandees = [];
        $client = new MockHttpClient(function (string $method, string $url) use (&$pagesDemandees) {
            if (!str_contains($url, '/proposals')) {
                return new MockResponse('[]', ['http_code' => 200]);
            }

            $query = [];
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $page = (int) ($query['page'] ?? 0);
            $pagesDemandees[] = $page;

            $count = match ($page) {
                0 => 100,
                1 => 20,
                default => 0,
            };
            $rows = [];
            for ($i = 0; $i < $count; ++$i) {
                $id = $page * 100 + $i + 1;
                $rows[] = ['id' => (string) $id, 'ref' => 'PR'.$id, 'statut' => '1', 'date' => 1505433600, 'total_ht' => '10'];
            }

            return new MockResponse(json_encode($rows, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });
        $api = new DolibarrApi($client, new NullLogger(), new ArrayAdapter(), 'https://doli.example/dolibarr', 'cle');

        $documents = $api->documentsForThirdparty('2');

        $this->assertCount(120, $documents['proposals'], 'Tous les devis sont récupérés, pas seulement la première page.');
        $this->assertSame([0, 1], $pagesDemandees, 'La pagination s\'arrête dès qu\'une page est incomplète.');
        $this->assertSame('PR1', $documents['proposals'][0]['ref']);
        $this->assertSame('PR120', $documents['proposals'][119]['ref']);
    }
}
