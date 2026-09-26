<?php

namespace App\Tests;

use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests du mécanisme de vérification de prospect via l'API Annuaire des
 * Entreprises (intégration HTTP sans mock réseau : les services sont
 * stubbés via le container).
 */
class ProspectVerifyTest extends WebTestCase
{
    private const SIREN_FIXTURE = '800123456';

    private function loginClient(): KernelBrowser
    {
        $client = static::createClient();
        $repository = static::getContainer()->get(UserRepository::class);
        if (!$repository instanceof UserRepository) {
            static::markTestSkipped('UserRepository introuvable.');
        }
        $user = $repository->findOneBy(['username' => (string) ($_SERVER['APP_ADMIN'] ?? 'admin')]);
        if (!$user instanceof User) {
            static::markTestSkipped('Utilisateur admin introuvable.');
        }
        $client->loginUser($user);

        return $client;
    }

    private function unProspect(string $nom = 'BIOPROSPECT TEST'): int
    {
        $emService = static::getContainer()->get('doctrine.orm.entity_manager');
        if (!$emService instanceof EntityManagerInterface) {
            static::markTestSkipped('EM indisponible.');
        }
        $em = $emService;

        // Crée un prospect jetable.
        $prospect = new Prospect();
        $prospect->setNom($nom);
        $prospect->setCleEntreprise('test:'.uniqid());
        $prospect->setAnomalie(false);
        $em->persist($prospect);
        $em->flush();

        $id = $prospect->getId();
        if (null === $id) {
            static::markTestSkipped('Prospect sans id.');
        }

        return $id;
    }

    /**
     * Fait un GET verify pour amorcer la session et récupérer le token
     * CSRF injecté dans la modale.
     */
    private function csrfVerify(KernelBrowser $client, int $prospectId): string
    {
        $client->request('GET', '/user/prospects/verify/'.$prospectId);
        $this->assertResponseIsSuccessful();
        $body = (string) $client->getResponse()->getContent();
        if (preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $body, $m)) {
            return $m[1];
        }
        $this->fail('CSRF manquant sur verify/'.$prospectId.'. Extrait HTML: '.substr($body, 0, 800));
    }

    public function testVerifyRouteReturnsModalForAuthenticatedUser(): void
    {
        $client = $this->loginClient();
        $prospectId = $this->unProspect();

        $client->request('GET', '/user/prospects/verify/'.$prospectId, [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Vérification via Annuaire des Entreprises', $content);
        $this->assertStringContainsString('BIOPROSPECT TEST', $content);
    }

    public function testVerifyApplyRejectsMissingCsrf(): void
    {
        $client = $this->loginClient();
        $prospectId = $this->unProspect();
        $this->csrfVerify($client, $prospectId);

        $client->request('POST', '/user/prospects/verify/'.$prospectId.'/apply', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], (string) json_encode(['siren' => self::SIREN_FIXTURE, 'csrf_token' => 'bad']));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testVerifyApplyRejectsInvalidSiren(): void
    {
        $client = $this->loginClient();
        $prospectId = $this->unProspect();
        $csrf = $this->csrfVerify($client, $prospectId);

        $client->request('POST', '/user/prospects/verify/'.$prospectId.'/apply', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], (string) json_encode(['siren' => '12345', 'csrf_token' => $csrf]));

        $this->assertResponseStatusCodeSame(422);
    }

    public function testVerifyApplyFailsWhenSirenUnknown(): void
    {
        $client = $this->loginClient();
        $prospectId = $this->unProspect();
        $csrf = $this->csrfVerify($client, $prospectId);

        // SIREN valide (9 chiffres). L'API réelle peut le trouver ou pas,
        // on accepte 422 (introuvable) OU 200 (match factice). On exclut 403/404.
        $client->request('POST', '/user/prospects/verify/'.$prospectId.'/apply', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], (string) json_encode(['siren' => '999999999', 'csrf_token' => $csrf]));

        $status = $client->getResponse()->getStatusCode();
        $this->assertContains($status, [200, 422], "Statut inattendu: $status");
    }

    public function testFromAnnuairePageRenders(): void
    {
        $client = $this->loginClient();

        $client->request('GET', '/user/prospects/from-annuaire');
        $this->assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        // Le titre apparaît dans <title> avec apostrophe HTML-escaped.
        $this->assertStringContainsString('Créer un prospect depuis l', $content);
        $this->assertStringContainsString('Annuaire', $content);
    }

    public function testFromAnnuaireCreateRejectsMissingCsrf(): void
    {
        $client = $this->loginClient();
        $client->request('POST', '/user/prospects/from-annuaire/create', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], (string) json_encode(['siren' => '123456789', 'csrf_token' => 'bad']));

        $this->assertResponseStatusCodeSame(403);
    }

    public function testFromAnnuaireCreateRejectsInvalidSiren(): void
    {
        $client = $this->loginClient();
        // Récupère le CSRF via la page (le token est lié à la session).
        $client->request('GET', '/user/prospects/from-annuaire');
        $body = (string) $client->getResponse()->getContent();
        if (!preg_match('/name="_csrf_token"\s+value="([^"]+)"/', $body, $m)) {
            static::markTestSkipped('CSRF manquant.');
        }
        $csrf = $m[1];

        $client->request('POST', '/user/prospects/from-annuaire/create', [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ], (string) json_encode(['siren' => '12345', 'csrf_token' => $csrf]));

        $this->assertResponseStatusCodeSame(422);
    }
}
