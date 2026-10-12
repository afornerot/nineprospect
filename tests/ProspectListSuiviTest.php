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
 * Colonne « Prc Action » de la liste prospects et filtre par statut.
 * Une action en retard reste visible et signalée même quand une action
 * future est saisie ; le filtre « En retard » remonte tout prospect ayant
 * au moins une action en retard non réalisée.
 */
class ProspectListSuiviTest extends WebTestCase
{
    public function testLaDerniereActionEnRetardRestVisible(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->creerProspect('ZZTEST Suivi '.uniqid());
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $hier = (new \DateTime('yesterday'))->format('Y-m-d');
        $demain = (new \DateTime('tomorrow'))->format('Y-m-d');

        $enRetard = $this->creerPlanifiee($prospect, $hier);
        $avenir = $this->creerPlanifiee($prospect, $demain);
        $this->em()->clear();

        $q = urlencode((string) $prospect->getNom());

        // La colonne « Prc Action » affiche l'action en retard (badge de
        // statut), même quand une action future existe — lien vers l'update.
        $client->request('GET', '/user/prospects?q='.$q);
        $this->assertResponseIsSuccessful();
        $row = $client->getCrawler()->filter('tr#prospect-'.$prospect->getId());
        $this->assertSame(1, $row->count(), 'Le prospect doit être présent dans la liste.');
        $this->assertStringContainsString('En retard', $row->html());
        $this->assertStringContainsString('/user/actions/update/'.$enRetard->getId(), $row->html());
        $this->assertStringNotContainsString('/user/actions/update/'.$avenir->getId(), $row->html());

        // Le filtre « En retard » remonte le prospect (au moins une action
        // en retard non réalisée)…
        $client->request('GET', '/user/prospects?q='.$q.'&statut=retard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('tr#prospect-'.$prospect->getId());

        // … et le filtre « Réalisée » ne le remonte pas (aucune action faite).
        $client->request('GET', '/user/prospects?q='.$q.'&statut=faites');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('tr#prospect-'.$prospect->getId());

        $this->em()->clear();
        foreach ([$enRetard, $avenir] as $a) {
            $rechargee = $this->actions()->find((int) $a->getId());
            if ($rechargee instanceof Action) {
                $this->em()->remove($rechargee);
            }
        }
        $this->supprimer($prospect);
    }

    public function testAjouterUneActionFutureNeMasquePasLesPassees(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->creerProspect('ZZTEST Suivi2 '.uniqid());
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $hier = (new \DateTime('yesterday'))->format('Y-m-d');
        $demain = (new \DateTime('tomorrow'))->format('Y-m-d');

        // Avant toute action future : le prospect est bien « En retard ».
        $enRetard = $this->creerPlanifiee($prospect, $hier);
        $this->em()->clear();

        $q = urlencode((string) $prospect->getNom());
        $client->request('GET', '/user/prospects?q='.$q.'&statut=retard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('tr#prospect-'.$prospect->getId());

        // On ajoute une action à venir : l'action passée reste visible et
        // le prospect reste filtré « En retard ».
        $avenir = $this->creerPlanifiee($prospect, $demain);
        $this->em()->clear();

        $client->request('GET', '/user/prospects?q='.$q);
        $this->assertResponseIsSuccessful();
        $row = $client->getCrawler()->filter('tr#prospect-'.$prospect->getId());
        $this->assertSame(1, $row->count());
        $this->assertStringContainsString('En retard', $row->html());
        $this->assertStringContainsString('/'.$enRetard->getId(), $row->html());

        $client->request('GET', '/user/prospects?q='.$q.'&statut=retard');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('tr#prospect-'.$prospect->getId());

        $this->em()->clear();
        foreach ([$enRetard, $avenir] as $a) {
            $rechargee = $this->actions()->find((int) $a->getId());
            if ($rechargee instanceof Action) {
                $this->em()->remove($rechargee);
            }
        }
        $this->supprimer($prospect);
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

    private function creerPlanifiee(Prospect $prospect, string $dateYmd): Action
    {
        $em = $this->em();
        // Le prospect peut être détaché après un clear() : on le ré-attache.
        $prospectManaged = $em->find(Prospect::class, $prospect->getId());

        $a = new Action();
        $a->setProspect($prospectManaged instanceof Prospect ? $prospectManaged : $prospect);
        $a->setDatePrevue(new \DateTime($dateYmd));
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
