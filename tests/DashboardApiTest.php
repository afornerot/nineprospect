<?php

namespace App\Tests;

use App\Enum\PipelineStatut;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * API REST des chiffres du tableau de bord (`/v1/api/dashboard`).
 *
 * L'authentification est portée par `X-API-SECRET` (valeur de `APP_SECRET`,
 * via `ApiKeyAuthenticator` et le rôle `ROLE_MCP`) : sans en-tête valide, la
 * réponse est un 401 JSON, jamais une redirection vers le login.
 */
class DashboardApiTest extends WebTestCase
{
    private const HEADER = 'X-API-SECRET';

    public function testSansEnteteRetourne401Json(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/api/dashboard');

        $this->assertResponseStatusCodeSame(401);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertSame('Unauthorized', $body['error']);
    }

    public function testSecretInvalideRetourne401(): void
    {
        $client = static::createClient();
        $client->request('GET', '/v1/api/dashboard', [], [], [self::HEADER => 'mauvais-secret']);

        $this->assertResponseStatusCodeSame(401);
    }

    public function testSecretValideExposeLesChiffresDuTableauDeBord(): void
    {
        $client = $this->clientAvecSecret();

        $client->request('GET', '/v1/api/dashboard');
        $this->assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);

        // KPI : mêmes clés que les pavés de l'écran.
        $this->assertSame(
            ['prospects', 'contacts', 'actionsEnRetard', 'actionsAujourdhui', 'actionsAVenir', 'nonContacte', 'nonQualifie'],
            array_keys($body['kpi']),
        );
        foreach ($body['kpi'] as $cle => $valeur) {
            $this->assertIsInt($valeur, sprintf('KPI %s doit être un entier.', $cle));
        }

        // Filtres appliqués (pipeline par défaut si non demandé).
        $this->assertArrayHasKey('filtres', $body);
        $this->assertArrayHasKey('pipeline', $body['filtres']);

        // Graphe pipeline : une série par statut, alignée sur les étapes.
        $this->assertIsArray($body['pipeline']['etapes']);
        $this->assertSame(PipelineStatut::LABELS, $body['pipeline']['statuts']);
        $this->assertCount(\count(PipelineStatut::LABELS), $body['pipeline']['series']);
        foreach ($body['pipeline']['series'] as $serie) {
            $this->assertCount(\count($body['pipeline']['etapes']), $serie['data'], 'Chaque série est alignée sur les étapes.');
        }

        // Séries mensuelles : mois triés et longueur de série = nombre de mois.
        foreach (['contactes', 'qualifications', 'etapes', 'actionsRealisees'] as $section) {
            $this->assertArrayHasKey('mois', $body[$section]);
            $this->assertArrayHasKey('series', $body[$section]);
            $mois = $body[$section]['mois'];
            $this->assertSame($mois, array_values(array_unique($mois)));
            $tries = $mois;
            sort($tries);
            $this->assertSame($tries, $mois, sprintf('Les mois de %s sont triés.', $section));
            foreach ($body[$section]['series'] as $serie) {
                $this->assertCount(\count($mois), $serie['data'], sprintf('Série de %s alignée sur les mois.', $section));
            }
        }

        // Les listes détaillées d'actions ne sont pas exposées.
        $this->assertArrayNotHasKey('retard', $body);
        $this->assertArrayNotHasKey('aujourdhui', $body);
        $this->assertArrayNotHasKey('avenir', $body);
    }

    public function testFiltreUtilisateurRestreintLesKpi(): void
    {
        $client = $this->clientAvecSecret();

        $client->request('GET', '/v1/api/dashboard');
        $tous = json_decode((string) $client->getResponse()->getContent(), true);

        $client->request('GET', '/v1/api/dashboard?utilisateur=1');
        $this->assertResponseIsSuccessful();
        $filtre = json_decode((string) $client->getResponse()->getContent(), true);

        $this->assertSame(1, $filtre['filtres']['utilisateur']);
        $this->assertLessThanOrEqual($tous['kpi']['prospects'], $filtre['kpi']['prospects']);
        $this->assertLessThanOrEqual($tous['kpi']['actionsEnRetard'], $filtre['kpi']['actionsEnRetard']);
    }

    public function testLesListesDeFiltresSontExposees(): void
    {
        $client = $this->clientAvecSecret();

        $client->request('GET', '/v1/api/dashboard/filtres');
        $this->assertResponseIsSuccessful();

        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        foreach (['utilisateurs', 'pipelines', 'sprints', 'produits', 'cibles'] as $cle) {
            $this->assertArrayHasKey($cle, $body);
            $this->assertIsArray($body[$cle]);
        }
        $this->assertNotEmpty($body['utilisateurs']);
        $this->assertArrayHasKey('id', $body['utilisateurs'][0]);
        $this->assertArrayHasKey('username', $body['utilisateurs'][0]);
    }

    /**
     * Client avec le secret applicatif réel (celui du `.env.test` en test).
     */
    private function clientAvecSecret(): KernelBrowser
    {
        $secret = $_SERVER['APP_SECRET'] ?? null;
        if (!is_string($secret) || '' === trim($secret)) {
            static::markTestSkipped('APP_SECRET non configuré.');
        }

        $client = static::createClient();
        $client->setServerParameter('HTTP_'.self::HEADER, $secret);

        return $client;
    }
}
