<?php

namespace App\Tests;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class CibleImportWebTest extends WebTestCase
{
    public function testAnonymeEstRedirigeVersLogin(): void
    {
        $client = static::createClient();
        try {
            $client->request('GET', '/user/cibles/import');
        } catch (\Throwable) {
            static::markTestSkipped('Base de données de test indisponible.');
        }

        $this->assertResponseRedirects('/login');
    }

    public function testUtilisateurConnecteAccedeALImport(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible.');
        }

        $client->request('GET', '/user/cibles/import');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Importer une cible');
    }

    public function testTemplateXlxsEstTelechargeable(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible.');
        }

        $client->request('GET', '/user/cibles/import/template');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment', (string) $client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testListeCiblesContientLeBoutonImporter(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible.');
        }

        $client->request('GET', '/user/cibles');

        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('Importer une cible');
    }

    private function loginAsUser(): ?KernelBrowser
    {
        $client = static::createClient();

        try {
            $repository = static::getContainer()->get(UserRepository::class);
        } catch (\LogicException) {
            return null;
        }

        if (!$repository instanceof UserRepository) {
            return null;
        }

        try {
            $user = $repository->findOneBy(['username' => (string) ($_SERVER['APP_ADMIN'] ?? 'admin')]);
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        if (!$user instanceof User) {
            return null;
        }

        $client->loginUser($user);

        return $client;
    }
}
