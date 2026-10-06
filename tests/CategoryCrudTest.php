<?php

namespace App\Tests;

use App\Entity\Category;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\CategoryRepository;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CRUD complet d'une Category : create, read, update, delete.
 * Vérifie aussi l'association d'une catégorie à un Prospect via le formulaire.
 */
class CategoryCrudTest extends WebTestCase
{
    public function testCategoryCrudLifecycle(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        // Cleanup éventuel
        $this->nettoyer();

        // 1) CREATE : POST /user/categories/submit
        $uri = '/user/categories/submit';
        $token = $this->csrf($client, $uri);
        $client->request('POST', $uri, [
            'category' => [
                'submit' => '',
                'nom' => 'ZZTEST-collectivite',
                'description' => 'Catégorie de test',
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertTrue($client->getResponse()->isRedirect(), 'Soumission doit rediriger');
        $location = $client->getResponse()->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertMatchesRegularExpression('#/user/categories/update/\d+$#', $location);

        // Vérif que la Category est persistée avec un slug auto-généré
        $this->em()->clear();
        $cat = $this->categories()->findOneBy(['nom' => 'ZZTEST-collectivite']);
        $this->assertInstanceOf(Category::class, $cat, 'Category devrait être persistée');
        $this->assertSame('zztest-collectivite', $cat->getSlug(), 'Slug auto-généré');
        $catId = (int) $cat->getId();

        // 2) READ : GET /user/categories → contient la Category
        $crawler = $client->request('GET', '/user/categories');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('td:contains("ZZTEST-collectivite")')->count());

        // 3) UPDATE : POST /user/categories/update/{id}
        $uri = '/user/categories/update/'.$catId;
        $token = $this->csrf($client, $uri);
        $client->request('POST', $uri, [
            'category' => [
                'submit' => '',
                'nom' => 'ZZTEST-collectivite-renomme',
                'description' => 'Catégorie de test mise à jour',
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertTrue($client->getResponse()->isRedirect(), 'Update doit rediriger');

        $this->em()->clear();
        $cat = $this->categories()->find($catId);
        $this->assertInstanceOf(Category::class, $cat);
        $this->assertSame('ZZTEST-collectivite-renomme', $cat->getNom());
        $this->assertSame('Catégorie de test mise à jour', $cat->getDescription());

        // 4) Association Prospect ↔ Category via le formulaire ProspectType
        $prospect = $this->prospectPourTest();
        if ($prospect instanceof Prospect) {
            $uri = '/user/prospects/update/'.$prospect->getId();
            $catFieldId = 'prospect_categories';
            $crawler = $client->request('GET', $uri);
            $candidates = $crawler->filter('select[name="prospect[categories][]"] option[value="'.$catId.'"]');
            $this->assertGreaterThan(0, $candidates->count(), 'La Category doit apparaître dans le select du formulaire Prospect');

            $token = $crawler->filter('input[name="prospect[_token]"]')->attr('value');
            $this->assertNotNull($token, 'CSRF token du formulaire Prospect introuvable');

            $client->request('POST', $uri, [
                'prospect' => [
                    '_token' => $token,
                    'nom' => $prospect->getNom() ?? '',
                    'contacte' => $prospect->getContacte() ? '1' : '0',
                    'categories' => [$catId],
                ],
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);

            $this->em()->clear();
            $prospectReload = $this->prospects()->find($prospect->getId());
            $this->assertInstanceOf(Prospect::class, $prospectReload);
            $this->assertTrue($prospectReload->getCategories()->contains($cat),
                'La Category doit être associée au Prospect après update');
        }

        // 5) DELETE : POST /user/categories/delete/{id} avec CSRF
        $uri = '/user/categories/delete/'.$catId;
        $token = $this->csrf($client, $uri);
        $client->request('POST', $uri, [
            '_csrf_token' => $token,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->em()->clear();
        $this->assertNull($this->categories()->find($catId), 'Category doit être supprimée');

        // Le Prospect ne doit plus référencer la Category
        if ($prospect instanceof Prospect) {
            $this->em()->clear();
            $prospectReload = $this->prospects()->find($prospect->getId());
            if ($prospectReload instanceof Prospect) {
                $this->assertFalse($prospectReload->getCategories()->contains($cat),
                    'La Category ne doit plus être associée au Prospect après suppression');
            }
        }
    }

    public function testCategorySlugEstGenereDepuisLeNom(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible.');
        }

        $this->nettoyer();

        // Création avec un nom qui doit produire un slug
        $uri = '/user/categories/submit';
        $token = $this->csrf($client, $uri);
        $client->request('POST', $uri, [
            'category' => [
                'submit' => '',
                'nom' => 'Mon Éducation Nationale',
                'description' => '',
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->em()->clear();
        $cat = $this->categories()->findOneBy(['nom' => 'Mon Éducation Nationale']);
        $this->assertInstanceOf(Category::class, $cat);
        $this->assertSame('mon-education-nationale', $cat->getSlug(),
            'Le slug doit retirer les accents et les espaces');
    }

    private function nettoyer(): void
    {
        $em = $this->em();
        $qb = $em->createQueryBuilder();
        $qb->delete(Category::class, 'c')
            ->where($qb->expr()->like('c.nom', $qb->expr()->literal('ZZTEST%')))
            ->getQuery()
            ->execute();
    }

    private function prospectPourTest(): ?Prospect
    {
        $prospects = $this->prospects()->findBy([], ['id' => 'ASC'], 1);
        $prospect = $prospects[0] ?? null;
        if (!$prospect instanceof Prospect) {
            $em = $this->em();
            $prospect = new Prospect();
            $prospect->setNom('ZZTEST-prospect');
            $prospect->setCleEntreprise('test:zztest-'.uniqid());
            $em->persist($prospect);
            $em->flush();
        }

        // S'assurer que le prospect de test n'a pas de catégorie au départ
        if ($prospect instanceof Prospect) {
            foreach ($prospect->getCategories() as $cat) {
                $prospect->removeCategory($cat);
            }
            $this->em()->flush();
        }

        return $prospect;
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

    private function csrf(KernelBrowser $client, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="category[_token]"]');

        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function categories(): CategoryRepository
    {
        $repository = static::getContainer()->get(CategoryRepository::class);
        if (!$repository instanceof CategoryRepository) {
            throw new \LogicException('Repository des catégories indisponible.');
        }

        return $repository;
    }

    private function prospects(): ProspectRepository
    {
        $repository = static::getContainer()->get(ProspectRepository::class);
        if (!$repository instanceof ProspectRepository) {
            throw new \LogicException('Repository des prospects indisponible.');
        }

        return $repository;
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
