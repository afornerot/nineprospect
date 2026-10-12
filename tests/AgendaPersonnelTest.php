<?php

namespace App\Tests;

use App\Entity\Action;
use App\Entity\Prospect;
use App\Repository\ActionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Agenda personnel : par défaut l'utilisateur voit son propre agenda,
 * peut consulter l'agenda d'un collègue et réaffecter ses actions.
 */
class AgendaPersonnelTest extends WebTestCase
{
    public function testAgendaPersonnelEtConsultation(): void
    {
        $client = $this->loginAsUser();
        $users = $this->deuxUtilisateurs();
        $prospect = $this->creerProspect('ZZTEST Agenda '.uniqid());
        if (null === $client || null === $users || null === $prospect) {
            static::markTestSkipped('Base de test indisponible (il faut au moins 2 utilisateurs).');
        }

        $aujourdhui = (new \DateTime('today'))->format('Y-m-d');
        $start = date('Y-m-01');
        $end = date('Y-m-t');

        [$moi, $collaborateur] = $users;

        $maAction = $this->creerPlanifiee($prospect, $aujourdhui, $moi);
        $sonAction = $this->creerPlanifiee($prospect, $aujourdhui, $collaborateur);
        $this->em()->clear();

        // La page agenda charge avec le sélecteur de collaborateur.
        $client->request('GET', '/user/agenda');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('#filtre-user');

        // L'agenda par défaut (sans ?user=) = actions affectées au user courant.
        $ids = $this->idsEvenements($client, $start, $end, null);
        $this->assertContains($maAction->getId(), $ids);
        $this->assertNotContains($sonAction->getId(), $ids);

        // L'agenda d'un collaborateur remonte ses actions, pas les nôtres.
        $ids = $this->idsEvenements($client, $start, $end, $collaborateur->getId());
        $this->assertNotContains($maAction->getId(), $ids);
        $this->assertContains($sonAction->getId(), $ids);

        // Nettoyage
        $this->em()->clear();
        foreach ([$maAction, $sonAction] as $a) {
            $rechargee = $this->actions()->find((int) $a->getId());
            if ($rechargee instanceof Action) {
                $this->em()->remove($rechargee);
            }
        }
        $this->supprimer($prospect);
    }

    public function testReaffecterUneActionMetAJourAFairePar(): void
    {
        $client = $this->loginAsUser();
        $users = $this->deuxUtilisateurs();
        $prospect = $this->creerProspect('ZZTEST Reaffect '.uniqid());
        if (null === $client || null === $users || null === $prospect) {
            static::markTestSkipped('Base de test indisponible (il faut au moins 2 utilisateurs).');
        }

        $aujourdhui = (new \DateTime('today'))->format('Y-m-d');
        [$moi, $collaborateur] = $users;

        $action = $this->creerPlanifiee($prospect, $aujourdhui, $moi);
        $actionId = $action->getId();
        $this->assertNotNull($actionId);
        $this->em()->clear();

        // Token CSRF puis réaffectation à un autre utilisateur.
        $client->request('GET', '/user/agenda/reaffecter-csrf/'.$actionId);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($data);
        $token = (string) ($data['token'] ?? '');

        $client->request('POST', '/user/actions/reaffecter/'.$actionId, [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['versUser' => (string) $collaborateur->getId(), 'csrf_token' => $token]) ?: '{}');

        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);
        $this->assertTrue($body['ok']);
        $this->assertSame($collaborateur->getUsername(), $body['aFairePar']);

        $this->em()->clear();
        $action = $this->actions()->find($actionId);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertNotNull($action->getAFairePar());
        $this->assertSame($collaborateur->getId(), $action->getAFairePar()->getId());

        // Le champ reste consultable dans l'agenda du collaborateur.
        $this->em()->clear();
        $ids = $this->idsEvenements($client, date('Y-m-01'), date('Y-m-t'), $collaborateur->getId());
        $this->assertContains($actionId, $ids);

        $this->em()->clear();
        $actionFinale = $this->actions()->find($actionId);
        if ($actionFinale instanceof Action) {
            $this->em()->remove($actionFinale);
        }
        $this->supprimer($prospect);
    }

    public function testReaffecterCsrfInvalideEstRefusee(): void
    {
        $client = $this->loginAsUser();
        $users = $this->deuxUtilisateurs();
        $prospect = $this->creerProspect('ZZTEST Reaffect2 '.uniqid());
        if (null === $client || null === $users || null === $prospect) {
            static::markTestSkipped('Base de test indisponible (il faut au moins 2 utilisateurs).');
        }

        [$moi, $collaborateur] = $users;
        $action = $this->creerPlanifiee($prospect, (new \DateTime('today'))->format('Y-m-d'), $moi);
        $actionId = $action->getId();
        $this->assertNotNull($actionId);

        $client->request('POST', '/user/actions/reaffecter/'.$actionId, [], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['versUser' => (string) $collaborateur->getId(), 'csrf_token' => 'token_invalide']) ?: '{}');
        $this->assertResponseStatusCodeSame(403);

        $this->em()->clear();
        $action = $this->actions()->find($actionId);
        $this->assertInstanceOf(Action::class, $action);
        $this->assertSame($moi->getId(), $action->getAFairePar()?->getId());

        $this->em()->remove($action);
        $this->supprimer($prospect);
    }

    // --- Helpers ---

    /**
     * @return array{0: \App\Entity\User, 1: \App\Entity\User}|null
     */
    private function deuxUtilisateurs(): ?array
    {
        try {
            $repo = static::getContainer()->get(UserRepository::class);
        } catch (\Throwable) {
            return null;
        }
        if (!$repo instanceof UserRepository) {
            return null;
        }
        $users = $repo->findAll();
        if ([] === $users) {
            return null;
        }
        // La base de test (copie de la dev) ne contient souvent qu'un seul
        // utilisateur : on en crée un second pour pouvoir tester la
        // consultation d'un agenda autre et la réaffectation.
        $second = $users[1] ?? null;
        if (null === $second) {
            $suffix = bin2hex(random_bytes(4));
            try {
                $em = $this->em();
                $second = new \App\Entity\User();
                $second->setUsername('zztest-'.$suffix);
                $second->setEmail('zztest-'.$suffix.'@example.com');
                $second->setPassword(password_hash('changeme', PASSWORD_BCRYPT));
                $second->setRoles(['ROLE_USER']);
                $em->persist($second);
                $em->flush();
            } catch (\Throwable) {
                return null;
            }
        }

        return [$users[0], $second];
    }

    private function loginAsUser(): ?KernelBrowser
    {
        try {
            $client = static::createClient();
        } catch (\Throwable) {
            return null;
        }
        $users = $this->deuxUtilisateurs();
        if (null === $users) {
            return null;
        }
        $client->loginUser($users[0]);

        return $client;
    }

    private function creerProspect(string $nom): ?Prospect
    {
        try {
            $prospect = new Prospect();
            $prospect->setNom($nom);
            $em = $this->em();
            $em->persist($prospect);
            $em->flush();
        } catch (\Throwable) {
            return null;
        }

        return $prospect;
    }

    private function creerPlanifiee(Prospect $prospect, string $dateYmd, \App\Entity\User $user): Action
    {
        $em = $this->em();
        // Le prospect/l'utilisateur peuvent être détachés après un clear() : on
        // les ré-attache depuis l'EntityManager.
        $prospectManaged = $em->find(Prospect::class, $prospect->getId());
        $userManaged = $em->find(\App\Entity\User::class, $user->getId());

        $a = new Action();
        $a->setProspect($prospectManaged instanceof Prospect ? $prospectManaged : $prospect);
        $a->setDatePrevue(new \DateTime($dateYmd));
        $a->setAFairePar($userManaged instanceof \App\Entity\User ? $userManaged : $user);
        $em->persist($a);
        $em->flush();

        return $a;
    }

    private function supprimer(Prospect $prospect): void
    {
        $em = $this->em();
        $fresh = $em->find(Prospect::class, $prospect->getId());
        if ($fresh instanceof Prospect) {
            $em->remove($fresh);
        }
        $em->flush();
    }

    /**
     * @return array<int, int>
     */
    private function idsEvenements(KernelBrowser $client, string $start, string $end, ?int $userId): array
    {
        $params = ['start' => $start, 'end' => $end];
        if (null !== $userId) {
            $params['user'] = (string) $userId;
        }
        $client->request('GET', '/user/agenda/events?'.http_build_query($params));
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($body);

        return array_map('intval', array_column($body, 'id'));
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
}
