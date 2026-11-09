<?php

namespace App\Tests;

use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use App\Service\DolibarrApi;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Liaison d'un prospect à un tiers Dolibarr : recherche (fragment HTML),
 * écriture de `prospect.id_dolibarr` après revalidation du tiers, retrait,
 * et garde-fous (CSRF, tiers inconnu).
 *
 * Le client Dolibarr est remplacé par un stub HTTP : aucun appel réseau réel.
 */
class DolibarrProspectLinkTest extends WebTestCase
{
    /** @var array<string, mixed> */
    private const TIERS = [
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
        'fournisseur' => '0',
    ];

    public function testRechercheEtLiaisonPuisRetrait(): void
    {
        $client = $this->client($this->dolibarrStub());
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospectId = $this->creerProspect();
        $this->assertNull($this->prospect($prospectId)->getIdDolibarr());

        // Recherche : le fragment contient le tiers renvoyé par Dolibarr.
        $client->request('GET', '/user/prospects/'.$prospectId.'/dolibarr/search?q=ineo', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('INEO Réseaux Nord Est', $html);
        $this->assertStringContainsString('Dolibarr #373', $html);
        $this->assertMatchesRegularExpression('/name="thirdpartyId"\s+value="373"/', $html);

        $csrf = $this->csrf($html);
        if (null === $csrf) {
            $this->fail('Token CSRF absent du fragment de recherche.');
        }

        // Liaison : id_dolibarr persisté.
        $r = $this->postLink($client, $prospectId, '373', $csrf);
        $this->assertTrue($r['ok']);
        $this->assertSame('373', $r['thirdpartyId']);
        $this->assertSame('INEO Réseaux Nord Est', $r['nom']);
        $this->assertSame('https://doli.example/dolibarr/societe/card.php?socid=373', $r['url']);
        $this->assertSame('373', $this->prospect($prospectId)->getIdDolibarr());

        // La fiche affiche le tiers lié, avec son lien Dolibarr, et le pavé
        // des documents commerciaux (brouillons exclus).
        $client->request('GET', '/user/prospects/show/'.$prospectId);
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('societe/card.php?socid=373', $html);
        $this->assertStringContainsString('INEO Réseaux Nord Est', $html);
        $this->assertStringContainsString('Dolibarr', $html);
        $this->assertStringContainsString('PR1110-0002', $html, 'Le devis non brouillon est listé.');
        $this->assertStringContainsString('comm/propal/card.php?id=2', $html);
        $this->assertStringNotContainsString('PR-BROUILLON', $html, 'Les brouillons sont exclus.');
        $this->assertStringContainsString('Montant HT', $html);
        $this->assertStringContainsString('7 624,08 €', $html, 'Le montant HT est formaté en euros.');
        $this->assertStringContainsString('Total HT', $html, 'Chaque table porte son total HT.');
        $this->assertStringNotContainsString('TTC', $html, 'Le TTC a été retiré du pavé.');
        $this->assertStringNotContainsString('réf. client', $html, 'La référence client a été retirée.');

        // Retrait : thirdpartyId vide.
        $r = $this->postLink($client, $prospectId, '', $csrf);
        $this->assertTrue($r['ok']);
        $this->assertNull($r['thirdpartyId']);
        $this->assertNull($this->prospect($prospectId)->getIdDolibarr());

        $this->supprimer($prospectId);
    }

    public function testLiaisonRejetteUnTiersInconnu(): void
    {
        $client = $this->client($this->dolibarrStub());
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospectId = $this->creerProspect();
        $client->request('GET', '/user/prospects/'.$prospectId.'/dolibarr/search?q=ineo');
        $csrf = $this->csrf((string) $client->getResponse()->getContent());
        if (null === $csrf) {
            $this->fail('Token CSRF absent du fragment de recherche.');
        }

        // 999 n'existe pas côté Dolibarr : 422 et rien n'est écrit.
        $r = $this->postLinkRaw($client, $prospectId, '999', $csrf);
        $this->assertSame(422, $r['status']);
        $this->assertFalse($r['body']['ok']);
        $this->assertNull($this->prospect($prospectId)->getIdDolibarr());

        $this->supprimer($prospectId);
    }

    public function testLiaisonRejetteUnCsrfInvalide(): void
    {
        $client = $this->client($this->dolibarrStub());
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospectId = $this->creerProspect();
        $client->request('POST', '/user/prospects/dolibarr/link', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], (string) json_encode([
            'prospectId' => $prospectId,
            'thirdpartyId' => '373',
            'csrf_token' => 'invalide',
        ]));

        $this->assertResponseStatusCodeSame(403);
        $this->assertNull($this->prospect($prospectId)->getIdDolibarr());

        $this->supprimer($prospectId);
    }

    // --- Helpers ---

    /**
     * Stub HTTP Dolibarr : une liste pour la recherche, 404 sinon.
     */
    private function dolibarrStub(): DolibarrApi
    {
        $http = new MockHttpClient(static function (string $method, string $url) {
            if (str_contains($url, '/thirdparties/373')) {
                return new MockResponse(json_encode(self::TIERS, \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }
            if (preg_match('#/thirdparties/\d+$#', $url)) {
                return new MockResponse(
                    json_encode(['error' => ['code' => 404, 'message' => 'Not Found: Thirdparty not found']], \JSON_THROW_ON_ERROR),
                    ['http_code' => 404],
                );
            }
            if (str_contains($url, '/proposals')) {
                return new MockResponse(json_encode([
                    [
                        'id' => '2', 'ref' => 'PR1110-0002', 'statut' => 4, 'date' => 1318377600,
                        'total_ht' => '7624.08', 'total_ttc' => '9148.90', 'ref_client' => '',
                    ],
                ], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
            }
            if (str_contains($url, '/orders') || str_contains($url, '/invoices')) {
                return new MockResponse('[]', ['http_code' => 200]);
            }

            return new MockResponse(json_encode([self::TIERS], \JSON_THROW_ON_ERROR), ['http_code' => 200]);
        });

        return new DolibarrApi(
            $http,
            new NullLogger(),
            new ArrayAdapter(),
            'https://doli.example/dolibarr',
            'cle',
        );
    }

    private function client(DolibarrApi $dolibarr): ?KernelBrowser
    {
        try {
            $client = static::createClient();
            $repo = static::getContainer()->get(UserRepository::class);
            $user = $repo instanceof UserRepository ? $repo->findOneBy([]) : null;
        } catch (\Throwable) {
            return null;
        }
        if (!$user instanceof User) {
            return null;
        }

        // `disableReboot()` : sans lui, chaque requête redémarre le kernel et
        // le stub injecté dans le container serait perdu (appel réseau réel).
        $client->disableReboot();
        static::getContainer()->set(DolibarrApi::class, $dolibarr);
        $client->loginUser($user);

        return $client;
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function postLinkRaw(KernelBrowser $client, int $prospectId, string $thirdpartyId, string $csrf): array
    {
        $client->request('POST', '/user/prospects/dolibarr/link', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], (string) json_encode([
            'prospectId' => $prospectId,
            'thirdpartyId' => $thirdpartyId,
            'csrf_token' => $csrf,
        ]));

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);

        return ['status' => $client->getResponse()->getStatusCode(), 'body' => $body];
    }

    /**
     * @return array<string, mixed>
     */
    private function postLink(KernelBrowser $client, int $prospectId, string $thirdpartyId, string $csrf): array
    {
        $r = $this->postLinkRaw($client, $prospectId, $thirdpartyId, $csrf);
        $this->assertSame(200, $r['status']);

        return $r['body'];
    }

    private function csrf(string $html): ?string
    {
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function creerProspect(): int
    {
        $prospect = new Prospect();
        $prospect->setNom('ZZTEST Dolibarr '.uniqid());
        $prospect->setCleEntreprise('test-dolibarr:'.uniqid());
        $em = $this->em();
        $em->persist($prospect);
        $em->flush();

        $id = $prospect->getId();
        $this->assertNotNull($id);

        return (int) $id;
    }

    private function prospect(int $id): Prospect
    {
        $em = $this->em();
        $em->clear();
        $prospect = $this->prospects()->find($id);
        $this->assertInstanceOf(Prospect::class, $prospect);

        return $prospect;
    }

    private function supprimer(int $id): void
    {
        $em = $this->em();
        $em->clear();
        $prospect = $this->prospects()->find($id);
        if ($prospect instanceof Prospect) {
            $em->remove($prospect);
            $em->flush();
        }
    }

    private function prospects(): ProspectRepository
    {
        $repo = static::getContainer()->get(ProspectRepository::class);
        if (!$repo instanceof ProspectRepository) {
            throw new \LogicException('Repository prospects indisponible.');
        }

        return $repo;
    }

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('Entity manager indisponible.');
        }

        return $em;
    }
}
