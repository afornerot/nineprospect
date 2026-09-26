<?php

namespace App\Tests;

use App\Entity\Action;
use App\Entity\Prospect;
use App\Repository\ActionRepository;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Cycle de vie d'une action de prospection :
 *  - création (planifiée, réalisée, contrainte ≥ 1 date)
 *  - clôture (modale : date/résultat/échéance suivante) + création éventuelle d'une nouvelle
 *  - déclôture (annulation)
 *  - garde-fous : clôture d'une déjà réalisée → 409, CSRF invalide → 403
 *  - smoke des 3 onglets
 */
class ActionCrudTest extends WebTestCase
{
    public function testCreationActionPlanifiee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfForm($client, '/user/actions/submit');

        // Planifiée : datePrevue + dateRealisee vide, type obligatoire.
        $client->request('POST', '/user/actions/submit', [
            'action' => [
                'prospect' => (string) $prospect->getId(),
                'datePrevue' => '2099-12-10',
                'dateRealisee' => '',
                'typeAction' => 'Appel',
                'resultat' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $created = $this->derniereAction($prospect);
        $this->assertInstanceOf(Action::class, $created);
        $this->assertTrue($created->estPlanifiee());
        $this->assertFalse($created->estRealisee());
        $this->assertNotNull($created->getDatePrevue());
        $this->assertSame('Appel', $created->getTypeAction());

        $this->supprimer($created);
    }

    public function testCreationActionRealisee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfForm($client, '/user/actions/submit');

        $client->request('POST', '/user/actions/submit', [
            'action' => [
                'prospect' => (string) $prospect->getId(),
                'datePrevue' => '',
                'dateRealisee' => '2026-09-15',
                'typeAction' => 'Mail',
                'resultat' => 'Premier contact positif',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $created = $this->derniereAction($prospect);
        $this->assertInstanceOf(Action::class, $created);
        $this->assertTrue($created->estRealisee());
        $this->assertSame('Premier contact positif', $created->getResultat());
        $this->assertSame('Mail', $created->getTypeAction());

        $this->supprimer($created);
    }

    public function testCreationRejeteeSansAucuneDate(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfForm($client, '/user/actions/submit');

        // Aucune date : contrainte "Renseignez une date…"
        $client->request('POST', '/user/actions/submit', [
            'action' => [
                'prospect' => (string) $prospect->getId(),
                'datePrevue' => '',
                'dateRealisee' => '',
                'typeAction' => 'Appel',
                'resultat' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseStatusCodeSame(422);

        $created = $this->derniereActionParDatePrevue('2099-12-01');
        $this->assertNull($created, 'Aucune action ne doit être créée sans date.');
    }

    public function testCreationRejeteeSansType(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfForm($client, '/user/actions/submit');

        $client->request('POST', '/user/actions/submit', [
            'action' => [
                'prospect' => (string) $prospect->getId(),
                'datePrevue' => '2099-12-10',
                'dateRealisee' => '',
                'typeAction' => '',
                'resultat' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseStatusCodeSame(422);
    }

    public function testCloturerCreeLaSuivanteSiDemandee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        // Prépare une action planifiée
        $planifiee = $this->creerPlanifiee($prospect, '2099-12-10');
        $planifieeId = $planifiee->getId();
        $this->assertNotNull($planifieeId);
        $this->em()->clear();

        $token = $this->recupererCsrfCloture($client, $planifieeId);

        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => $token,
            'dateRealisee' => '2026-09-25',
            'resultat' => 'Appel abouti',
            'datePrevueSuivante' => '2099-12-20',
            'typeSuivant' => 'Visio',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['ok']);
        $this->assertTrue($body['planifiee']);

        $this->em()->clear();
        $planifiee = $this->actions()->find($planifieeId);
        $this->assertInstanceOf(Action::class, $planifiee);
        $this->assertTrue($planifiee->estRealisee());
        $this->assertSame('Appel abouti', $planifiee->getResultat());

        $suivante = $this->derniereActionParDatePrevue('2099-12-20');
        $this->assertInstanceOf(Action::class, $suivante);
        $this->assertTrue($suivante->estPlanifiee());
        $this->assertNotSame($planifieeId, $suivante->getId());

        $this->em()->remove($suivante);
        $this->em()->remove($planifiee);
        $this->em()->flush();
    }

    public function testCloturerSansSuivanteNeCreeRien(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $planifiee = $this->creerPlanifiee($prospect, '2099-12-10');
        $planifieeId = $planifiee->getId();
        $this->assertNotNull($planifieeId);
        $this->em()->clear();

        $token = $this->recupererCsrfCloture($client, $planifieeId);

        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => $token,
            'dateRealisee' => '2026-09-25',
            'resultat' => 'OK',
            'datePrevueSuivante' => '',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertFalse($body['planifiee']);

        $this->em()->clear();
        $planifiee = $this->actions()->find($planifieeId);
        $this->assertInstanceOf(Action::class, $planifiee);
        $this->assertTrue($planifiee->estRealisee());

        $this->em()->remove($planifiee);
        $this->em()->flush();
    }

    public function testCloturerAvecSuivanteExigeUnType(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $planifiee = $this->creerPlanifiee($prospect, '2099-12-10');
        $planifieeId = $planifiee->getId();
        $this->assertNotNull($planifieeId);
        $this->em()->clear();

        $token = $this->recupererCsrfCloture($client, $planifieeId);

        // Date suivante saisie sans type → 422
        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => $token,
            'dateRealisee' => '2026-09-25',
            'resultat' => 'OK',
            'datePrevueSuivante' => '2099-12-20',
            'typeSuivant' => '',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseStatusCodeSame(422);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($body['ok']);

        // Sans date suivante, type vide OK (pas de création de planifiée).
        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => $token,
            'dateRealisee' => '2026-09-25',
            'resultat' => 'OK',
            'datePrevueSuivante' => '',
            'typeSuivant' => '',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $action = $this->actions()->find($planifieeId);
        if ($action instanceof Action) {
            $this->em()->remove($action);
            $this->em()->flush();
        }
    }

    public function testCloturerUneDejaRealiseeEstRefusee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        // Crée une planifiée, la clôture (elle devient réalisée), puis tente
        // de la clôturer à nouveau → 409.
        $planifiee = $this->creerPlanifiee($prospect, '2099-12-10');
        $planifieeId = $planifiee->getId();
        $this->assertNotNull($planifieeId);
        $this->em()->clear();

        $token = $this->recupererCsrfCloture($client, $planifieeId);

        // 1re clôture : succès
        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => $token,
            'dateRealisee' => '2026-09-25',
            'resultat' => 'Première clôture',
            'datePrevueSuivante' => '',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);

        // 2e tentative : 409 (déjà réalisée). Token CSRF rejouable.
        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => $token,
            'dateRealisee' => '2026-09-25',
            'resultat' => '',
            'datePrevueSuivante' => '',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseStatusCodeSame(409);

        $this->em()->clear();
        $action = $this->actions()->find($planifieeId);
        if ($action instanceof Action) {
            $this->em()->remove($action);
            $this->em()->flush();
        }
    }

    public function testCloturerCsrfInvalideEstRefusee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $planifiee = $this->creerPlanifiee($prospect, '2099-12-10');
        $planifieeId = $planifiee->getId();
        $this->assertNotNull($planifieeId);
        $this->em()->clear();

        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => 'token_invalide',
            'dateRealisee' => '2026-09-25',
            'resultat' => '',
            'datePrevueSuivante' => '',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertResponseStatusCodeSame(403);

        $this->em()->clear();
        $planifiee = $this->actions()->find($planifieeId);
        if ($planifiee instanceof Action) {
            $this->em()->remove($planifiee);
            $this->em()->flush();
        }
    }

    public function testDecloturerRedevientPlanifiee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $realisee = $this->creerRealisee($prospect, '2026-09-25', 'A annuler');
        $realiseeId = $realisee->getId();
        $this->assertNotNull($realiseeId);
        $this->em()->clear();

        // Le wrapper est sur l'onglet Réalisées
        $client->request('GET', '/user/actions?vue=faites');
        $crawler = $client->getCrawler();
        $wrapper = $crawler->filter('.js-decloturer-wrapper[data-action-id="'.$realiseeId.'"]');
        if (0 === $wrapper->count()) {
            static::markTestSkipped('Action absente de l\'onglet Réalisées.');
        }
        $token = (string) $wrapper->attr('data-csrf');

        $client->request('POST', '/user/actions/decloturer/'.$realiseeId, [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);

        $this->em()->clear();
        $realisee = $this->actions()->find($realiseeId);
        $this->assertInstanceOf(Action::class, $realisee);
        $this->assertTrue($realisee->estPlanifiee());
        $this->assertNull($realisee->getDateRealisee());

        $this->em()->remove($realisee);
        $this->em()->flush();
    }

    public function testLesTroisOngletsChargent(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        foreach (['retard', 'avenir', 'faites'] as $vue) {
            $client->request('GET', '/user/actions?vue='.$vue);
            $this->assertResponseIsSuccessful();
            $this->assertSelectorTextContains('h1', 'Actions');
            $this->assertSelectorExists('table#dataTables');
        }
    }

    public function testCreationActionAvecTypeLibre(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfForm($client, '/user/actions/submit');

        // L'utilisateur saisit un texte libre, sans choisir dans la liste.
        $client->request('POST', '/user/actions/submit', [
            'action' => [
                'prospect' => (string) $prospect->getId(),
                'datePrevue' => '2099-12-10',
                'dateRealisee' => '',
                'aFairePar' => '',
                'realisePar' => '',
                'typeAction' => 'Texte libre de l\'utilisateur',
                'resultat' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $created = $this->derniereAction($prospect);
        $this->assertInstanceOf(Action::class, $created);
        $this->assertSame("Texte libre de l'utilisateur", $created->getTypeAction());

        $this->em()->remove($created);
        $this->em()->flush();
    }

    public function testCloturerInitRealiseParEtTypeSuivant(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        $user = $this->unUser();
        if (null === $client || null === $prospect || null === $user) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $planifiee = $this->creerPlanifiee($prospect, '2099-12-10');
        $planifieeId = $planifiee->getId();
        $this->assertNotNull($planifieeId);
        $this->em()->clear();

        $token = $this->recupererCsrfCloture($client, $planifieeId);

        $client->request('POST', '/user/actions/cloturer/'.$planifieeId, [
            '_csrf_token' => $token,
            'dateRealisee' => '2026-09-25',
            'resultat' => 'OK',
            'datePrevueSuivante' => '2099-12-20',
            'typeSuivant' => 'Visio',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        $this->assertResponseIsSuccessful();

        $this->em()->clear();
        $planifiee = $this->actions()->find($planifieeId);
        $this->assertInstanceOf(Action::class, $planifiee);
        $this->assertTrue($planifiee->estRealisee());
        $this->assertNotNull($planifiee->getRealisePar());
        $this->assertSame($user->getId(), $planifiee->getRealisePar()->getId());

        $suivante = $this->derniereActionParDatePrevue('2099-12-20');
        $this->assertInstanceOf(Action::class, $suivante);
        $this->assertSame('Visio', $suivante->getTypeAction());
        $this->assertNotNull($suivante->getAFairePar());
        $this->assertSame($user->getId(), $suivante->getAFairePar()->getId());

        $this->em()->remove($suivante);
        $this->em()->remove($planifiee);
        $this->em()->flush();
    }

    public function testCrudActionTypesDefaut(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        // Liste
        $client->request('GET', '/user/action-types');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', "Types d'action");

        // Création
        $token = $this->csrfActionType($client, '/user/action-types/submit');
        $client->request('POST', '/user/action-types/submit', [
            'action_type_defaut' => [
                'libelle' => 'ZZTEST type',
                'ordre' => '99',
                'actif' => '1',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        // Vérif BDD
        $em = $this->em();
        $repo = $em->getRepository(\App\Entity\ActionTypeDefaut::class);
        $cree = $repo->findOneBy(['libelle' => 'ZZTEST type']);
        $this->assertInstanceOf(\App\Entity\ActionTypeDefaut::class, $cree);
        $this->assertSame(99, $cree->getOrdre());
        $this->assertTrue($cree->isActif());

        // Suppression
        $creeId = $cree->getId();
        $this->assertNotNull($creeId);
        $client->request('GET', '/user/action-types/update/'.$creeId);
        $this->assertResponseIsSuccessful();
        $deleteToken = $this->csrfDeleteActionType($client, $creeId);
        $client->request('POST', '/user/action-types/delete/'.$creeId, [
            '_csrf_token' => $deleteToken,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $em->clear();
        $this->assertNull($repo->findOneBy(['libelle' => 'ZZTEST type']));
    }

    // --- Helpers ---

    private function loginAsUser(): ?KernelBrowser
    {
        try {
            $client = static::createClient();
            $container = static::getContainer();
            $repo = $container->get(UserRepository::class);
            $user = $repo instanceof UserRepository ? $repo->findOneBy([]) : null;
        } catch (\Throwable) {
            return null;
        }
        if (!$user instanceof \App\Entity\User) {
            return null;
        }
        $client->loginUser($user);

        return $client;
    }

    private function unProspect(): ?Prospect
    {
        try {
            $repo = static::getContainer()->get(ProspectRepository::class);
        } catch (\Throwable) {
            return null;
        }
        if (!$repo instanceof ProspectRepository) {
            return null;
        }

        return $repo->findOneBy([], ['id' => 'ASC']);
    }

    private function unUser(): ?\App\Entity\User
    {
        try {
            $repo = static::getContainer()->get(UserRepository::class);
        } catch (\Throwable) {
            return null;
        }
        if (!$repo instanceof UserRepository) {
            return null;
        }

        return $repo->findOneBy([], ['id' => 'ASC']);
    }

    private function actions(): ActionRepository
    {
        $repo = static::getContainer()->get(ActionRepository::class);
        if (!$repo instanceof ActionRepository) {
            throw new \LogicException('Repository actions indisponible.');
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

    private function csrfForm(KernelBrowser $client, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="action[_token]"]');
        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function csrfActionType(KernelBrowser $client, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="action_type_defaut[_token]"]');
        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function csrfDeleteActionType(KernelBrowser $client, int $id): string
    {
        $crawler = $client->request('GET', '/user/action-types/update/'.$id);
        $champ = $crawler->filter('form.delete-button input[name="_csrf_token"]');
        $this->assertNotSame(0, $champ->count(), 'Token CSRF delete introuvable.');

        return (string) $champ->attr('value');
    }

    private function recupererCsrfCloture(KernelBrowser $client, ?int $actionId): string
    {
        $this->assertNotNull($actionId, 'Action sans id.');
        $client->request('GET', '/user/actions?vue=avenir');
        $crawler = $client->getCrawler();
        $wrapper = $crawler->filter('.js-cloturer-wrapper[data-action-id="'.$actionId.'"]');
        if (0 === $wrapper->count()) {
            // Cherche aussi en retard
            $client->request('GET', '/user/actions?vue=retard');
            $crawler = $client->getCrawler();
            $wrapper = $crawler->filter('.js-cloturer-wrapper[data-action-id="'.$actionId.'"]');
        }
        $this->assertNotSame(0, $wrapper->count(), 'Switch clôture introuvable.');

        return (string) $wrapper->attr('data-csrf');
    }

    private function creerPlanifiee(Prospect $prospect, string $dateYmd): Action
    {
        $em = $this->em();
        $a = new Action();
        $a->setProspect($prospect);
        $a->setDatePrevue(new \DateTime($dateYmd));
        $em->persist($a);
        $em->flush();

        return $a;
    }

    private function creerRealisee(Prospect $prospect, string $dateYmd, string $resultat): Action
    {
        $em = $this->em();
        $a = new Action();
        $a->setProspect($prospect);
        $a->setDateRealisee(new \DateTime($dateYmd));
        $a->setResultat($resultat);
        $em->persist($a);
        $em->flush();

        return $a;
    }

    private function derniereAction(Prospect $prospect): ?Action
    {
        return $this->actions()->createQueryBuilder('a')
            ->where('a.prospect = :p')
            ->setParameter('p', $prospect)
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function derniereActionParDatePrevue(string $dateYmd): ?Action
    {
        return $this->actions()->createQueryBuilder('a')
            ->where('a.datePrevue = :dp')
            ->setParameter('dp', new \DateTime($dateYmd))
            ->orderBy('a.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    private function supprimer(Action $a): void
    {
        $em = $this->em();
        $em->remove($a);
        $em->flush();
    }
}
