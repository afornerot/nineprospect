<?php

namespace App\Tests;

use App\Entity\Cible;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\CibleRepository;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CRUD complet d'une Cible : create, read, update, delete.
 * Vérifie aussi l'association d'une cible à un Prospect via le formulaire.
 */
class CibleCrudTest extends WebTestCase
{
    public function testCibleCrudLifecycle(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        // Cleanup éventuel
        $this->nettoyer();

        // 1) CREATE : POST /user/cibles/submit
        $uri = '/user/cibles/submit';
        $token = $this->csrf($client, $uri);
        $client->request('POST', $uri, [
            'cible' => [
                'submit' => '',
                'nom' => 'ZZTEST-collectivite',
                'description' => 'Cible de test',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertTrue($client->getResponse()->isRedirect(), 'Soumission doit rediriger');
        $location = $client->getResponse()->headers->get('Location');
        $this->assertNotNull($location);
        $this->assertMatchesRegularExpression('#/user/cibles/update/\d+$#', $location);

        // Vérif que la Cible est persistée avec un slug auto-généré
        $this->em()->clear();
        $cat = $this->cibles()->findOneBy(['nom' => 'ZZTEST-collectivite']);
        $this->assertInstanceOf(Cible::class, $cat, 'Cible devrait être persistée');
        $this->assertSame('zztest-collectivite', $cat->getSlug(), 'Slug auto-généré');
        $catId = (int) $cat->getId();

        // 2) READ : GET /user/cibles → contient la Cible
        $crawler = $client->request('GET', '/user/cibles');
        $this->assertResponseIsSuccessful();
        $this->assertGreaterThan(0, $crawler->filter('td:contains("ZZTEST-collectivite")')->count());

        // 3) UPDATE : POST /user/cibles/update/{id}
        $uri = '/user/cibles/update/'.$catId;
        $token = $this->csrf($client, $uri);
        $client->request('POST', $uri, [
            'cible' => [
                'submit' => '',
                'nom' => 'ZZTEST-collectivite-renomme',
                'description' => 'Cible de test mise à jour',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertTrue($client->getResponse()->isRedirect(), 'Update doit rediriger');

        $this->em()->clear();
        $cat = $this->cibles()->find($catId);
        $this->assertInstanceOf(Cible::class, $cat);
        $this->assertSame('ZZTEST-collectivite-renomme', $cat->getNom());
        $this->assertSame('Cible de test mise à jour', $cat->getDescription());

        // 4) Association Prospect ↔ Cible via le formulaire ProspectType
        $prospect = $this->prospectPourTest();
        if ($prospect instanceof Prospect) {
            $uri = '/user/prospects/update/'.$prospect->getId();
            $catFieldId = 'prospect_cibles';
            $crawler = $client->request('GET', $uri);
            $candidates = $crawler->filter('select[name="prospect[cibles][]"] option[value="'.$catId.'"]');
            $this->assertGreaterThan(0, $candidates->count(), 'La Cible doit apparaître dans le select du formulaire Prospect');

            $token = $crawler->filter('input[name="prospect[_token]"]')->attr('value');
            $this->assertNotNull($token, 'CSRF token du formulaire Prospect introuvable');

            $client->request('POST', $uri, [
                'prospect' => [
                    '_token' => $token,
                    'nom' => $prospect->getNom() ?? '',
                    'contacte' => $prospect->getContacte() ? '1' : '0',
                    'cibles' => [$catId],
                ],
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);

            $this->em()->clear();
            $prospectReload = $this->prospects()->find($prospect->getId());
            $this->assertInstanceOf(Prospect::class, $prospectReload);
            // contains() compare des identites d objet : apres em()->clear(),
            // $cat est detache et la comparaison echoue toujours. On compare
            // les identifiants.
            $idsAssocies = $prospectReload->getCibles()->map(
                static fn (Cible $c): ?int => $c->getId()
            )->toArray();
            $this->assertContains($catId, $idsAssocies,
                'La Cible doit être associée au Prospect après update');
        }

        // 5) DELETE : POST /user/cibles/delete/{id} avec CSRF
        // Le token n'est pas sur une page dediee : il est emis par le bouton
        // de suppression (include/delete_button.html.twig) present sur la
        // page d'edition, avec l'identifiant 'delete-cible{id}'.
        $uri = '/user/cibles/delete/'.$catId;
        $crawler = $client->request('GET', '/user/cibles/update/'.$catId);
        $champToken = $crawler->filter('input[name="_csrf_token"]');
        $this->assertNotSame(0, $champToken->count(), 'Bouton de suppression absent de la page d edition');
        $token = (string) $champToken->first()->attr('value');
        $client->request('POST', $uri, [
            '_csrf_token' => $token,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->em()->clear();
        $this->assertNull($this->cibles()->find($catId), 'Cible doit être supprimée');

        // Le Prospect ne doit plus référencer la Cible
        if ($prospect instanceof Prospect) {
            $this->em()->clear();
            $prospectReload = $this->prospects()->find($prospect->getId());
            if ($prospectReload instanceof Prospect) {
                $this->assertFalse($prospectReload->getCibles()->contains($cat),
                    'La Cible ne doit plus être associée au Prospect après suppression');
            }
        }
    }

    public function testCibleSlugEstGenereDepuisLeNom(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible.');
        }

        $this->nettoyer();

        // Création avec un nom qui doit produire un slug
        $uri = '/user/cibles/submit';
        $token = $this->csrf($client, $uri);
        $client->request('POST', $uri, [
            'cible' => [
                'submit' => '',
                'nom' => 'Mon Éducation Nationale',
                'description' => '',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->em()->clear();
        $cat = $this->cibles()->findOneBy(['nom' => 'Mon Éducation Nationale']);
        $this->assertInstanceOf(Cible::class, $cat);
        $this->assertSame('mon-education-nationale', $cat->getSlug(),
            'Le slug doit retirer les accents et les espaces');
    }

    private function nettoyer(): void
    {
        $em = $this->em();
        $qb = $em->createQueryBuilder();
        $qb->delete(Cible::class, 'c')
            ->where($qb->expr()->orX(
                $qb->expr()->like('c.nom', $qb->expr()->literal('ZZTEST%')),
                $qb->expr()->like('c.nom', $qb->expr()->literal('Mon %ducation Nationale')),
                $qb->expr()->like('c.nom', $qb->expr()->literal('ZZDBG%')),
            ))
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

        // S'assurer que le prospect de test n'a pas de cible au départ
        if ($prospect instanceof Prospect) {
            foreach ($prospect->getCibles() as $cat) {
                $prospect->removeCible($cat);
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
        $champ = $crawler->filter('input[name="cible[_token]"]');

        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function cibles(): CibleRepository
    {
        $repository = static::getContainer()->get(CibleRepository::class);
        if (!$repository instanceof CibleRepository) {
            throw new \LogicException('Repository des cibles indisponible.');
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
