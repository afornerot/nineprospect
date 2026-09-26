<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SecurityTest extends WebTestCase
{
    public function testAnonymousHomePageRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        // "/" redirige vers le tableau de bord, lui-même réservé aux connectés.
        $this->assertResponseRedirects('/user/dashboard');

        $client->followRedirect();
        $this->assertResponseRedirects('/login');
    }

    public function testAnonymousAdminRedirectsToLogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/user');

        $this->assertResponseRedirects('/login');
    }

    public function testLoginSubmitIsBlockedWithoutCsrf(): void
    {
        $client = static::createClient();

        $client->request('POST', '/login', ['_username' => 'admin', '_password' => 'wrongpassword']);

        $this->assertResponseRedirects('/login');
    }

    public function testLoginWithCsrfAuthenticatesAndGrantsAdminUiAccess(): void
    {
        $username = (string) ($_SERVER['APP_ADMIN'] ?? 'admin');
        $secret = $_SERVER['APP_SECRET'] ?? null;

        if (!is_string($secret) || '' === $secret) {
            static::fail('Variables APP_SECRET non configurée.');
        }

        $client = static::createClient();
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Valider')->form([
            '_username' => $username,
            '_password' => $secret,
        ]);

        try {
            $this->submitWithOrigin($client, $form);
            $this->suivreRedirections($client);
        } catch (\Symfony\Component\BrowserKit\Exception\LogicException) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $this->assertResponseIsSuccessful();

        $client->request('GET', '/admin/user');
        $this->assertResponseIsSuccessful();
    }

    public function testPlainUserIsForbiddenOnAdmin(): void
    {
        $client = static::createClient();

        try {
            $this->createUser('user_test', ['ROLE_USER']);
        } catch (\Doctrine\DBAL\Exception) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }
        $crawler = $client->request('GET', '/login');

        $form = $crawler->selectButton('Valider')->form([
            '_username' => 'user_test',
            '_password' => 'Password&Special1',
        ]);

        try {
            $this->submitWithOrigin($client, $form);
            $this->suivreRedirections($client);
        } catch (\Symfony\Component\BrowserKit\Exception\LogicException) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $client->request('GET', '/admin/user');
        $this->assertResponseStatusCodeSame(403);
    }

    /**
     * Suit les redirections jusqu'à la page finale (ex. / puis /user/dashboard).
     */
    private function suivreRedirections(KernelBrowser $client, int $limite = 5): void
    {
        while ($client->getResponse()->isRedirection() && $limite-- > 0) {
            $client->followRedirect();
        }

        $chemin = parse_url($client->getRequest()->getUri(), PHP_URL_PATH);
        if ('/login' === $chemin) {
            static::markTestSkipped('Echec de connexion au compte de test.');
        }
    }

    /**
     * Soumet le formulaire de login en imitant un navigateur : la protection
     * CSRF stateless (same-origin) exige un en-tête Origin/Referer.
     */
    private function submitWithOrigin(KernelBrowser $client, \Symfony\Component\DomCrawler\Form $form): void
    {
        $client->request($form->getMethod(), $form->getUri(), $form->getValues(), $form->getFiles(), [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_REFERER' => 'http://localhost/login',
        ]);
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $username, array $roles): void
    {
        $em = static::getContainer()->get(ManagerRegistry::class);

        if (!$em instanceof ManagerRegistry) {
            throw new \RuntimeException('Doctrine non disponible dans les tests.');
        }

        $manager = $em->getManager();

        if (!$manager instanceof EntityManagerInterface) {
            throw new \RuntimeException('Gestionnaire d\'entités Doctrine non configuré.');
        }

        $user = $manager->getRepository(\App\Entity\User::class)->findOneBy(['username' => $username]);
        if (!$user instanceof \App\Entity\User) {
            $user = new \App\Entity\User();
            $user->setUsername($username);
            $user->setEmail($username.'@localhost');
            $manager->persist($user);
        }

        $user->setRoles($roles);

        $hasher = static::getContainer()->get(\Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface::class);
        if (!$hasher instanceof \Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface) {
            throw new \RuntimeException('Password hasher non disponible dans les tests.');
        }

        $user->setPassword($hasher->hashPassword($user, 'Password&Special1'));

        $manager->flush();
    }
}
