<?php

namespace App\Tests;

use App\Entity\User;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ProspectionRoutesTest extends WebTestCase
{
    private const ROUTES = [
        '/user/dashboard',
        '/user/prospects',
        '/user/contacts',
        '/user/actions',
        '/user/campagnes',
        '/user/vagues',
        '/user/pipelines',
        '/user/geographie',
        '/user/prospects/verify/1779',
    ];

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        foreach (self::ROUTES as $route) {
            $client->request('GET', $route);

            $this->assertResponseRedirects('/login');
        }
    }

    public function testAuthenticatedUserCanListProspectionPages(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        foreach (self::ROUTES as $route) {
            $client->request('GET', $route);

            $this->assertResponseIsSuccessful();
        }
    }

    public function testProspectShowPageRendersContactsAndActions(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $client->request('GET', '/user/prospects');
        $this->assertResponseIsSuccessful();

        $lien = $client->getCrawler()->filter('#dataTables tbody a[href*="/user/prospects/show/"]');
        if (0 === $lien->count()) {
            static::markTestSkipped('Aucun prospect importé pour ce test.');
        }

        $href = $lien->attr('href');
        if (null === $href) {
            static::markTestSkipped('Lien de fiche prospect introuvable.');
        }

        $client->request('GET', $href);
        $this->assertResponseIsSuccessful();
    }

    public function testAnonymousCannotCreateProspect(): void
    {
        $client = static::createClient();
        $client->request('POST', '/user/prospects/submit', ['prospect' => ['nom' => 'ANONYME']]);

        $this->assertResponseRedirects('/login');
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
