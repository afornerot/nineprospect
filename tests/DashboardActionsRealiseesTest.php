<?php

namespace App\Tests;

use App\Entity\Action;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\ActionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pavé « Actions réalisées » du tableau de bord : nombre de clôtures par mois,
 * mois = `dateRealisee`, filtré par l'auteur de la clôture (`realisePar`).
 */
class DashboardActionsRealiseesTest extends WebTestCase
{
    /** @var list<int> */
    private array $prospectsCrees = [];

    /** @var list<int> */
    private array $utilisateursCrees = [];

    public function testLeComptageSuitLeMoisDeRealisation(): void
    {
        $user = $this->creerUtilisateur();

        $this->creerAction('2024-01-15', $user);
        $this->creerAction('2024-01-31', $user);
        $this->creerAction('2024-03-02', $user);

        // Planifiée mais non clôturée : exclue.
        $this->creerAction(null, $user);

        $resultat = $this->repository()->countRealiseesParMois(null);

        $this->assertSame(['2024-01', '2024-03'], $resultat['mois']);
        $this->assertSame([['label' => 'Actions réalisées', 'data' => [2, 1]]], $resultat['series']);
    }

    public function testLeFiltrePorteSurLAuteurDeLaCloture(): void
    {
        $user = $this->creerUtilisateur();
        $autre = $this->creerUtilisateur();

        $this->creerAction('2024-05-02', $user);
        $this->creerAction('2024-05-03', $autre);
        $this->creerAction('2024-06-04', $autre);

        // Clôture sans auteur (import, commande) : visible pour « Tous » seulement.
        $this->creerAction('2024-06-05', null);

        $sien = $this->repository()->countRealiseesParMois((int) $user->getId());
        $this->assertSame(['2024-05'], $sien['mois']);
        $this->assertSame([['label' => 'Actions réalisées', 'data' => [1]]], $sien['series']);

        $autreSerie = $this->repository()->countRealiseesParMois((int) $autre->getId());
        $this->assertSame(['2024-05', '2024-06'], $autreSerie['mois']);
        $this->assertSame([['label' => 'Actions réalisées', 'data' => [1, 1]]], $autreSerie['series']);

        $tous = $this->repository()->countRealiseesParMois(null);
        $this->assertSame([['label' => 'Actions réalisées', 'data' => [2, 2]]], $tous['series'], '« Tous » inclut les clôtures non signées.');
    }

    public function testAucuneRealisationDonneUneSerieVide(): void
    {
        $resultat = $this->repository()->countRealiseesParMois(999999);

        $this->assertSame([], $resultat['mois']);
        $this->assertSame([], $resultat['series'], 'Pas de mois, pas de série à dessiner.');
    }

    public function testLePaveEstAffiche(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $user = $this->creerUtilisateur();
        $this->creerAction('2024-07-04', $user);

        $client->request('GET', '/user/dashboard?utilisateur='.$user->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Actions réalisées', $html);
        $this->assertStringContainsString('actionChart', $html);
        $this->assertStringContainsString('var actionMois = ["2024-07"];', $html);
        $this->assertStringContainsString(
            'var actionSeries = [{"label":"Actions réalisées","data":[1]}];',
            $html
        );

        // Les en-têtes des trois cartes d'actions portent leur compteur, aligné
        // sur les KPI (mêmes repositories, même filtre).
        $repos = $this->repository();
        $attendu = [
            'En retard' => $repos->countDue($user),
            "Aujourd'hui" => $repos->countToday($user),
            'À venir' => $repos->countUpcoming($user),
        ];
        foreach ($attendu as $titre => $nombre) {
            $motif = '#<span>'.preg_quote($titre, '#').'</span>\s*<span class="badge[^"]*">(\d+)</span>#';
            $this->assertSame(
                1,
                preg_match($motif, $html, $trouve),
                sprintf('La carte « %s » expose son compteur dans son en-tête.', $titre)
            );
            $this->assertSame((string) $nombre, $trouve[1], sprintf('Compteur de « %s ».', $titre));
        }
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();

        foreach ($this->prospectsCrees as $id) {
            $prospect = $em->find(Prospect::class, $id);
            if (null !== $prospect) {
                $em->remove($prospect);
            }
        }
        $em->flush();

        foreach ($this->utilisateursCrees as $id) {
            $user = $em->find(User::class, $id);
            if (null !== $user) {
                $em->remove($user);
            }
        }
        $em->flush();

        $this->prospectsCrees = [];
        $this->utilisateursCrees = [];

        parent::tearDown();
    }

    // --- Helpers ---

    private function loginAsUser(): ?KernelBrowser
    {
        try {
            $client = static::createClient();
            $repo = static::getContainer()->get(UserRepository::class);
            $user = $repo instanceof UserRepository ? $repo->findOneBy([]) : null;
        } catch (\Throwable) {
            return null;
        }
        if (!$user instanceof User) {
            return null;
        }
        $client->loginUser($user);

        return $client;
    }

    private function repository(): ActionRepository
    {
        $repo = static::getContainer()->get(ActionRepository::class);
        if (!$repo instanceof ActionRepository) {
            throw new \LogicException('Repository des actions indisponible.');
        }

        return $repo;
    }

    /**
     * Une action portée par un prospect dédié ; `$realisee` null = planifiée.
     */
    private function creerAction(?string $realisee, ?User $realisePar): Action
    {
        $em = $this->em();

        $prospect = new Prospect();
        $prospect->setNom('ZZACTION '.uniqid());
        $em->persist($prospect);

        $action = new Action();
        $action->setProspect($prospect);
        $action->setTypeAction('Appel');
        $action->setDatePrevue(new \DateTime('2024-01-01'));
        $action->setRealisePar($realisePar);
        if (null !== $realisee) {
            $action->setDateRealisee(new \DateTime($realisee));
        }
        $em->persist($action);
        $em->flush();

        $this->prospectsCrees[] = (int) $prospect->getId();

        return $action;
    }

    private function creerUtilisateur(): User
    {
        $user = new User();
        $user->setUsername('zzaction-'.uniqid());
        $user->setEmail('zzaction-'.uniqid().'@example.test');
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();
        $this->utilisateursCrees[] = (int) $user->getId();

        return $user;
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
