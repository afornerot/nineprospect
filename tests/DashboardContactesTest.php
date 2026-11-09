<?php

namespace App\Tests;

use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pavé « Prospects contactés par mois » du tableau de bord : courbe mensuelle
 * du nombre de prospects passés à « contacté », comptés à la date de leur
 * premier contact, filtré par « Affecté à », prospects sans date exclus.
 *
 * Chaque scénario travaille sur un utilisateur jetable : le filtre
 * « Affecté à » isole la série des données déjà présentes en base de test.
 */
class DashboardContactesTest extends WebTestCase
{
    /** @var list<int> */
    private array $prospectsCrees = [];

    /** @var list<int> */
    private array $utilisateursCrees = [];

    public function testUneSerieMensuelleDesPremiersContacts(): void
    {
        $user = $this->creerUtilisateur();

        // Deux premiers contacts en janvier, un en mars.
        $this->contacter($this->creerProspect($user, 'A'), '2024-01-10 09:00:00', $user);
        $this->contacter($this->creerProspect($user, 'B'), '2024-01-20 11:30:00', $user);
        $this->contacter($this->creerProspect($user, 'C'), '2024-03-05 14:00:00', $user);

        // Jamais contacté : aucune date → exclu de la courbe.
        $this->creerProspect($user, 'D');

        $resultat = $this->repository()->countContactesParMois((int) $user->getId());

        $this->assertSame(['2024-01', '2024-03'], $resultat['mois'], 'Les mois sont la réunion des premiers contacts, triés.');
        $this->assertSame(
            [['label' => 'Contactés', 'data' => [2, 1]]],
            $resultat['series'],
            'Une seule série, alignée sur les mois communs.'
        );
    }

    public function testFiltreParAuteurDuPremierContact(): void
    {
        $user = $this->creerUtilisateur();
        $autre = $this->creerUtilisateur();

        // Les deux prospects sont affectés à `$user`, mais le premier
        // contact du second est signé par `$autre` : le filtre suit
        // l'auteur du changement, pas l'affectation commerciale.
        $parLui = $this->creerProspect($user, 'lui');
        $this->contacter($parLui, '2024-05-02 10:00:00', $user);

        $parAutre = $this->creerProspect($user, 'autre');
        $this->contacter($parAutre, '2024-05-03 10:00:00', $autre);

        // Contact non signé (import, commande) : visible pour « Tous »
        // seulement, dans aucun intervenant.
        $sansAuteur = $this->creerProspect($user, 'systeme');
        $this->contacter($sansAuteur, '2024-05-04 10:00:00', null);

        $sien = $this->repository()->countContactesParMois((int) $user->getId());
        $autreSerie = $this->repository()->countContactesParMois((int) $autre->getId());
        $tous = $this->repository()->countContactesParMois(null);

        $this->assertSame(['2024-05'], $sien['mois']);
        $this->assertSame([['label' => 'Contactés', 'data' => [1]]], $sien['series']);
        $this->assertSame([['label' => 'Contactés', 'data' => [1]]], $autreSerie['series']);
        $index = array_search('2024-05', $tous['mois'], true);
        $this->assertIsInt($index, '« Tous » contient le mois des contacts du scénario.');
        $this->assertSame(
            3,
            $tous['series'][0]['data'][$index],
            '« Tous » inclut les contacts non signés (base de test non vide : on ne fige pas les autres mois).'
        );
    }


    public function testLePaveEstPlaceAuDessusDeQualification(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $user = $this->creerUtilisateur();
        $this->contacter($this->creerProspect($user, 'ui'), '2024-07-04 12:00:00', $user);

        $client->request('GET', '/user/dashboard?utilisateur='.$user->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Prospects contactés par mois', $html);
        $this->assertStringContainsString('contacteChart', $html);
        $this->assertStringContainsString('var contacteMois = ["2024-07"];', $html);
        $this->assertStringContainsString(
            'var contacteSeries = [{"label":"Contactés","data":[1]}];',
            $html,
            'Le graphe reçoit la série mensuelle.'
        );

        $positionContactes = strpos($html, 'Prospects contactés par mois');
        $positionQualification = strpos($html, 'Qualification des prospects');
        $this->assertIsInt($positionContactes, 'Le pavé des contacts est rendu.');
        $this->assertIsInt($positionQualification, 'Le pavé de qualification est rendu.');
        $this->assertLessThan(
            $positionQualification,
            $positionContactes,
            'Le pavé « Prospects contactés par mois » est placé au-dessus de « Qualification des prospects ».'
        );
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

    private function repository(): ProspectRepository
    {
        $repo = static::getContainer()->get(ProspectRepository::class);
        if (!$repo instanceof ProspectRepository) {
            throw new \LogicException('Repository des prospects indisponible.');
        }

        return $repo;
    }

    private function creerProspect(User $user, string $suffixe): Prospect
    {
        $prospect = new Prospect();
        $prospect->setNom('ZZCONTACT '.$suffixe.' '.uniqid());
        $prospect->addUser($user);
        $this->em()->persist($prospect);
        $this->em()->flush();
        $this->prospectsCrees[] = (int) $prospect->getId();

        return $prospect;
    }

    private function creerUtilisateur(): User
    {
        $user = new User();
        $user->setUsername('zzcontact-'.uniqid());
        $user->setEmail('zzcontact-'.uniqid().'@example.test');
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();
        $this->utilisateursCrees[] = (int) $user->getId();

        return $user;
    }

    /**
     * Renseigne le premier contact et son auteur sans passer par
     * `setContacte()` : le listener de session écraserait l'auteur, et la
     * date doit être choisie (le setter la mettrait à « maintenant »).
     */
    private function contacter(Prospect $prospect, string $quand, ?User $auteur = null): void
    {
        $prospect->setDatePremierContact(new \DateTime($quand));
        $prospect->setContactePar($auteur);
        $this->em()->flush();
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
