<?php

namespace App\Tests;

use App\Entity\Pipeline;
use App\Entity\PipelineEtape;
use App\Entity\PipelineValeur;
use App\Entity\Prospect;
use App\Entity\ProspectSprint;
use App\Entity\Sprint;
use App\Entity\User;
use App\Enum\PipelineStatut;
use App\Repository\PipelineRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pavé « Étape de pipeline des prospects » du tableau de bord : nombre de
 * changements de statut par mois, une courbe par **libellé** d'étape, tous
 * pipelines confondus, filtré par l'auteur du changement.
 *
 * Chaque scénario travaille sur un utilisateur jetable : le filtre
 * « Affecté à » isole la série des données déjà présentes en base de test.
 */
class DashboardEtapesPipelineTest extends WebTestCase
{
    /** @var list<int> */
    private array $prospectsCrees = [];

    /** @var list<int> */
    private array $sprintsCrees = [];

    /** @var list<int> */
    private array $pipelinesCrees = [];

    /** @var list<int> */
    private array $utilisateursCrees = [];

    public function testLeLibelleRegroupeLesEtapesDeTousLesPipelines(): void
    {
        $user = $this->creerUtilisateur();
        $autre = $this->creerUtilisateur();

        // Trois pipelines distincts portant chacun une étape « Appel » : la
        // casse ne doit pas créer trois courbes.
        [$prospectA] = $this->contexteEtape('Appel', '2024-01-10 09:00:00', $user);
        [$prospectB] = $this->contexteEtape('APPEL', '2024-01-20 10:00:00', $autre);
        [$prospectC] = $this->contexteEtape('appel', '2024-02-05 11:00:00', $user);

        $resultat = $this->repository()->countStatutsEtapeParMoisParLibelle(null);

        $this->assertSame(['2024-01', '2024-02'], $resultat['mois']);
        $this->assertSame([['label' => 'Appel', 'data' => [2, 1]]], $resultat['series'], 'Les trois libellés forment une seule courbe.');

        // Filtre par auteur : `$user` a signé un point en janvier et un en février.
        $sien = $this->repository()->countStatutsEtapeParMoisParLibelle((int) $user->getId());
        $this->assertSame([['label' => 'Appel', 'data' => [1, 1]]], $sien['series']);

        // Comme pour la qualification, la liste des mois suit le filtre : cet
        // intervenant n'a qu'un point, en janvier.
        $autreSerie = $this->repository()->countStatutsEtapeParMoisParLibelle((int) $autre->getId());
        $this->assertSame(['2024-01'], $autreSerie['mois']);
        $this->assertSame([['label' => 'Appel', 'data' => [1]]], $autreSerie['series']);
    }

    public function testTousLesStatutsSontComptesEtLesSansDateExclus(): void
    {
        $user = $this->creerUtilisateur();
        $this->contexteEtape('Relance', '2024-03-04 09:00:00', $user);
        $this->contexteEtape('Relance', '2024-03-05 09:00:00', $user);

        // Valeur sans date (antérieure à l'audit) : exclue.
        [$sansDate, $etapeSansDate] = $this->contexteEtape('Relance');
        $this->poserStatut($sansDate, $etapeSansDate, PipelineStatut::OUI, null, $user);

        $resultat = $this->repository()->countStatutsEtapeParMoisParLibelle(null);

        $this->assertSame(['2024-03'], $resultat['mois']);
        $this->assertSame([['label' => 'Relance', 'data' => [2]]], $resultat['series'], 'Tous les statuts comptent, seule la date filtre.');

        $sien = $this->repository()->countStatutsEtapeParMoisParLibelle((int) $user->getId());
        $this->assertSame([['label' => 'Relance', 'data' => [2]]], $sien['series']);
    }

    public function testSeuleLaValeurCouranteDEtapeEstComptee(): void
    {
        $user = $this->creerUtilisateur();
        [$prospect, $etape] = $this->contexteEtape('Signature', '2024-01-15 09:00:00', $user);

        // Une ligne par couple prospect↔vague↔étape : le second changement
        // écrase le premier, et c'est le mois du dernier qui compte.
        $this->poserStatut($prospect, $etape, PipelineStatut::NON, '2024-04-02 09:00:00', $user);

        $resultat = $this->repository()->countStatutsEtapeParMoisParLibelle(null);

        $this->assertSame(['2024-04'], $resultat['mois'], 'Le mois du premier changement disparaît avec la valeur écrasée.');
        $this->assertSame([['label' => 'Signature', 'data' => [1]]], $resultat['series']);
    }

    public function testLePaveEstAfficheAvecUneSerieParEtape(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $user = $this->creerUtilisateur();
        [$prospect, $etape] = $this->contexteEtape('ZZQUALI Etape', '2024-07-04 12:00:00', $user);

        $client->request('GET', '/user/dashboard?utilisateur='.$user->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('Étape de pipeline des prospects', $html);
        $this->assertStringContainsString('etapeChart', $html);
        $this->assertStringContainsString('var etapeMois = ["2024-07"];', $html);
        $this->assertStringContainsString(
            'var etapeSeries = [{"label":"ZZQUALI Etape","data":[1]}];',
            $html,
            'Le graphe reçoit une série par libellé d’étape.'
        );

        $this->nettoyerContexte();
    }

    protected function tearDown(): void
    {
        $this->nettoyerContexte();

        parent::tearDown();
    }

    // --- Helpers ---

    private function nettoyerContexte(): void
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

        foreach ($this->sprintsCrees as $id) {
            $sprint = $em->find(Sprint::class, $id);
            if (null !== $sprint) {
                $em->remove($sprint);
            }
        }
        $em->flush();

        foreach ($this->pipelinesCrees as $id) {
            $pipeline = $em->find(Pipeline::class, $id);
            if (null !== $pipeline) {
                $em->remove($pipeline);
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
        $this->sprintsCrees = [];
        $this->pipelinesCrees = [];
        $this->utilisateursCrees = [];
    }

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

    private function repository(): PipelineRepository
    {
        $repo = static::getContainer()->get(PipelineRepository::class);
        if (!$repo instanceof PipelineRepository) {
            throw new \LogicException('Repository des pipelines indisponible.');
        }

        return $repo;
    }

    /**
     * Un pipeline à une étape portant le libellé demandé, une vague et un
     * prospect rattaché.
     *
     * @return array{0: int, 1: int} identifiants du prospect et de l'étape
     */
    private function contexteEtape(string $libelle, ?string $quand = null, ?User $auteur = null): array
    {
        $em = $this->em();

        $pipeline = new Pipeline();
        $pipeline->setNom('ZZETAPE Pipeline '.uniqid());
        $etape = new PipelineEtape();
        $etape->setNom($libelle);
        $etape->setOrdre(1);
        $pipeline->addEtape($etape);
        $em->persist($pipeline);

        $sprint = new Sprint();
        $sprint->setNumero(random_int(900000, 999999));
        $sprint->setPipeline($pipeline);
        $em->persist($sprint);

        $prospect = new Prospect();
        $prospect->setNom('ZZETAPE '.uniqid());
        $lien = new ProspectSprint();
        $lien->setSprint($sprint);
        $prospect->addSprint($lien);
        $em->persist($prospect);
        $em->flush();

        $this->prospectsCrees[] = (int) $prospect->getId();
        $this->sprintsCrees[] = (int) $sprint->getId();
        $this->pipelinesCrees[] = (int) $pipeline->getId();

        $prospectId = (int) $prospect->getId();
        $etapeId = (int) $etape->getId();

        if (null !== $quand) {
            $this->poserStatut($prospectId, $etapeId, PipelineStatut::A_QUALIFIER, $quand, $auteur);
        }

        return [$prospectId, $etapeId];
    }

    /**
     * Écrit la valeur par UPDATE DQL : les listeners ne s'exécutent pas, ce
     * qui permet de choisir librement la date et l'auteur.
     */
    private function poserStatut(int $prospectId, int $etapeId, int $statut, ?string $quand, ?User $auteur = null): void
    {
        $em = $this->em();
        $prospect = $em->find(Prospect::class, $prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $lien = $prospect->vagueCourante();
        $this->assertInstanceOf(ProspectSprint::class, $lien);
        $etape = $em->find(PipelineEtape::class, $etapeId);
        $this->assertInstanceOf(PipelineEtape::class, $etape);

        $lien->definirStatut($etape, $statut);
        $em->flush();

        $em->createQueryBuilder()
            ->update(PipelineValeur::class, 'v')
            ->set('v.date', ':quand')
            ->set('v.modifiePar', ':auteur')
            ->where('v.prospectSprint = :lien')
            ->andWhere('v.etape = :etape')
            ->setParameter('quand', null === $quand ? null : new \DateTime($quand))
            ->setParameter('auteur', null === $auteur ? null : $auteur->getId())
            ->setParameter('lien', $lien->getId())
            ->setParameter('etape', $etapeId)
            ->getQuery()
            ->execute();
        $em->clear();
    }

    private function creerUtilisateur(): User
    {
        $user = new User();
        $user->setUsername('zzetape-'.uniqid());
        $user->setEmail('zzetape-'.uniqid().'@example.test');
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
