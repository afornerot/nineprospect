<?php

namespace App\Tests;

use App\Entity\Produit;
use App\Entity\Prospect;
use App\Entity\ProspectProduit;
use App\Entity\User;
use App\Enum\QualificationProduit;
use App\Repository\ProspectProduitRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pavé « Qualification des prospects » du tableau de bord : courbe mensuelle
 * du nombre de prospects qualifiés (« Oui », « Hors produit » ou « Injoignable »), un prospect
 * compté une seule fois dans le mois de sa décision la plus récente, filtré
 * par « Affecté à », qualifications non datées exclues.
 *
 * Chaque scénario travaille sur un utilisateur jetable : le filtre
 * « Affecté à » isole la série des données déjà présentes en base de test.
 */
class DashboardQualificationTest extends WebTestCase
{
    /** @var list<int> */
    private array $prospectsCrees = [];

    /** @var list<int> */
    private array $utilisateursCrees = [];

    /** @var list<int> */
    private array $produitsCreees = [];

    public function testUneCourbeParProduitAvecLesMoisCommuns(): void
    {
        $user = $this->creerUtilisateur();
        $produitA = $this->creerProduit('ZZQUALI Produit A');
        $produitB = $this->creerProduit('ZZQUALI Produit B');

        // A : deux décisions sur le produit A (janvier puis mars).
        $a = $this->creerProspect($user, 'A');
        $this->lier($a, $produitA, QualificationProduit::OUI, '2024-01-10 09:00:00', $user);
        $this->lier($a, $produitA, QualificationProduit::HORS_PRODUIT, '2024-03-05 14:00:00', $user);

        // B : une décision janvier sur A, une en février sur B.
        $b = $this->creerProspect($user, 'B');
        $this->lier($b, $produitA, QualificationProduit::HORS_PRODUIT, '2024-01-20 11:30:00', $user);
        $this->lier($b, $produitB, QualificationProduit::OUI, '2024-02-02 09:15:00', $user);

        // C : qualifié « Oui » mais sans date (antérieur à l'audit) → exclu.
        $c = $this->creerProspect($user, 'C');
        $this->lier($c, $produitA, QualificationProduit::OUI, null, $user);

        // D : état « Non » avec une date résiduelle → exclu.
        $d = $this->creerProspect($user, 'D');
        $this->lier($d, $produitB, QualificationProduit::NON, '2024-02-01 08:00:00', $user);

        $resultat = $this->repository()->countQualificationsParMoisParProduit((int) $user->getId());

        $this->assertSame(['2024-01', '2024-02', '2024-03'], $resultat['mois'], 'Les mois sont la réunion des décisions, triés.');
        $this->assertSame([
            ['label' => 'ZZQUALI Produit A', 'data' => [2, 0, 1]],
            ['label' => 'ZZQUALI Produit B', 'data' => [0, 1, 0]],
        ], $resultat['series'], 'Une courbe par produit, alignée sur les mois communs.');
    }

    public function testFiltreParAuteurDeLaQualification(): void
    {
        $user = $this->creerUtilisateur();
        $autre = $this->creerUtilisateur();
        $produit = $this->creerProduit('ZZQUALI Produit filtre');

        // Les deux prospects sont affectés à `$user`, mais la décision du
        // second est signée par `$autre` : le filtre suit l'auteur du
        // changement, pas l'affectation commerciale.
        $parLui = $this->creerProspect($user, 'lui');
        $this->lier($parLui, $produit, QualificationProduit::OUI, '2024-05-02 10:00:00', $user);

        $parAutre = $this->creerProspect($user, 'autre');
        $this->lier($parAutre, $produit, QualificationProduit::OUI, '2024-05-03 10:00:00', $autre);

        // Décision non signée (import, commande) : visible pour « Tous »
        // seulement, dans aucun intervenant.
        $sansAuteur = $this->creerProspect($user, 'systeme');
        $this->lier($sansAuteur, $produit, QualificationProduit::HORS_PRODUIT, '2024-05-04 10:00:00', null);

        $sien = $this->repository()->countQualificationsParMoisParProduit((int) $user->getId());
        $autreSerie = $this->repository()->countQualificationsParMoisParProduit((int) $autre->getId());
        $tous = $this->repository()->countQualificationsParMoisParProduit(null);

        $this->assertSame(['2024-05'], $sien['mois']);
        $this->assertSame([['label' => 'ZZQUALI Produit filtre', 'data' => [1]]], $sien['series']);
        $this->assertSame([['label' => 'ZZQUALI Produit filtre', 'data' => [1]]], $autreSerie['series']);
        $this->assertSame([['label' => 'ZZQUALI Produit filtre', 'data' => [3]]], $tous['series'], '« Tous » inclut les décisions non signées.');
    }

    public function testLePaveRemplaceLesTopDepartementsEtAfficheUneSeriesParProduit(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $user = $this->creerUtilisateur();
        $produit = $this->creerProduit('ZZQUALI Produit UI');
        $prospect = $this->creerProspect($user, 'ui');
        $this->lier($prospect, $produit, QualificationProduit::OUI, '2024-07-04 12:00:00', $user);

        $client->request('GET', '/user/dashboard?utilisateur='.$user->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Qualification des prospects', $html);
        $this->assertStringContainsString('qualificationChart', $html);
        $this->assertStringContainsString('var qualificationMois = ["2024-07"];', $html);
        $this->assertStringContainsString(
            'var qualificationSeries = [{"label":"ZZQUALI Produit UI","data":[1]}];',
            $html,
            'Le graphe reçoit une série par produit.'
        );
        $this->assertStringNotContainsString('Top départements', $html, 'Le pavé « Top départements » est retiré.');
        $this->assertStringNotContainsString('geoChart', $html);
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
        foreach ($this->produitsCreees as $id) {
            $produit = $em->find(Produit::class, $id);
            if (null !== $produit) {
                $em->remove($produit);
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
        $this->produitsCreees = [];
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

    private function repository(): ProspectProduitRepository
    {
        $repo = static::getContainer()->get(ProspectProduitRepository::class);
        if (!$repo instanceof ProspectProduitRepository) {
            throw new \LogicException('Repository des produits de prospect indisponible.');
        }

        return $repo;
    }

    private function creerProspect(User $user, string $suffixe): Prospect
    {
        $prospect = new Prospect();
        $prospect->setNom('ZZQUALI '.$suffixe.' '.uniqid());
        $prospect->addUser($user);
        $this->em()->persist($prospect);
        $this->em()->flush();
        $this->prospectsCrees[] = (int) $prospect->getId();

        return $prospect;
    }

    private function creerProduit(string $titre): Produit
    {
        $produit = new Produit();
        $produit->setTitre($titre);
        $this->em()->persist($produit);
        $this->em()->flush();
        $this->produitsCreees[] = (int) $produit->getId();

        return $produit;
    }

    private function creerUtilisateur(): User
    {
        $user = new User();
        $user->setUsername('zzquali-'.uniqid());
        $user->setEmail('zzquali-'.uniqid().'@example.test');
        $user->setPassword('x');
        $this->em()->persist($user);
        $this->em()->flush();
        $this->utilisateursCrees[] = (int) $user->getId();

        return $user;
    }

    /**
     * Crée le lien via l'ORM (le listener date une décision à la création),
     * puis force l'état d'audit voulu par un UPDATE DQL : les listeners ne
     * s'exécutent pas, ce qui permet de simuler une qualification antérieure
     * à l'audit (`null`) comme une date et un auteur choisis.
     */
    private function lier(Prospect $prospect, Produit $produit, QualificationProduit $qualifie, ?string $quand, ?User $auteur = null): void
    {
        $lien = new ProspectProduit();
        $lien->setProspect($prospect);
        $lien->setProduit($produit);
        $lien->setQualifie($qualifie);
        $this->em()->persist($lien);
        $this->em()->flush();
        $id = (int) $lien->getId();

        $this->em()->createQueryBuilder()
            ->update(ProspectProduit::class, 'pc')
            ->set('pc.qualifieModifieAt', ':quand')
            ->set('pc.qualifieModifiePar', ':auteur')
            ->where('pc.id = :id')
            ->setParameter('quand', null === $quand ? null : new \DateTime($quand))
            ->setParameter('auteur', $auteur)
            ->setParameter('id', $id)
            ->getQuery()
            ->execute();
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
