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
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit du changement de statut d'une étape de pipeline : la date existante
 * (`pipeline_valeur.date`) est complétée par son auteur
 * (`pipeline_valeur.modifie_par_id`), posé par
 * App\EventListener\PipelineValeurModificationListener.
 */
class PipelineAuditTest extends WebTestCase
{
    /** @var list<int> */
    private array $prospectsCrees = [];

    /** @var list<int> */
    private array $sprintsCrees = [];

    /** @var list<int> */
    private array $pipelinesCrees = [];

    public function testLeCycleDateEtSigneLeChangement(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        [$prospectId, $etapeId] = $this->contextePipeline();
        $username = $this->utilisateurConnecte()->getUsername();
        $csrf = $this->csrfCycle($client, $prospectId, $etapeId);

        // Clic : le statut avance, la date est posée et l'auteur est signé.
        $corps = $this->cycler($client, $prospectId, $etapeId, $csrf);
        $this->assertSame(
            (new \DateTime())->format('d/m/Y').' · '.$username,
            $corps['date'],
            'La réponse du cycle porte la date et son auteur.'
        );

        $valeur = $this->valeur($prospectId, $etapeId);
        $this->assertInstanceOf(PipelineValeur::class, $valeur);
        $this->assertSame(PipelineStatut::A_QUALIFIER, $valeur->getStatut());
        $this->assertSame((new \DateTime())->format('Y-m-d'), $valeur->getDate()?->format('Y-m-d'));
        $this->assertSame($username, $valeur->getModifiePar()?->getUsername());

        // Re-clic : nouveau statut, l'auteur reste celui de la session.
        $corps = $this->cycler($client, $prospectId, $etapeId, $csrf);
        $this->assertSame(PipelineStatut::RELANCER, $corps['statut']);
        $valeur = $this->valeur($prospectId, $etapeId);
        $this->assertInstanceOf(PipelineValeur::class, $valeur);
        $this->assertSame($username, $valeur->getModifiePar()?->getUsername());

        // Retour à NON_DEMARRE : la ligne disparaît, l'audit avec elle.
        $this->lien($prospectId)->definirStatut($this->etape($etapeId), PipelineStatut::NON_DEMARRE);
        $this->em()->flush();
        $this->assertNull($this->valeur($prospectId, $etapeId), 'Le retour à NON_DEMARRE supprime la valeur.');
    }

    public function testUneDateModifieeSansChangementDeStatutNeResignePas(): void
    {
        [$prospectId, $etapeId] = $this->contextePipeline();

        // Valeur créée hors session : la date est celle fournie, l'auteur
        // n'est pas connu, l'affichage se réduit à la date.
        $this->lien($prospectId)->definirValeur($this->etape($etapeId), PipelineStatut::OUI, new \DateTime('2024-05-06'));
        $this->em()->flush();

        $valeur = $this->valeur($prospectId, $etapeId);
        $this->assertInstanceOf(PipelineValeur::class, $valeur);
        $this->assertNull($valeur->getModifiePar());
        $this->assertSame('06/05/2024', $valeur->dateEtAuteur());

        // Même statut, date corrigée : pas de changement de statut, donc pas
        // de resignature.
        $valeur->setDate(new \DateTime('2024-06-07'));
        $this->em()->flush();

        $valeur = $this->valeur($prospectId, $etapeId);
        $this->assertInstanceOf(PipelineValeur::class, $valeur);
        $this->assertSame('07/06/2024', $valeur->dateEtAuteur());
        $this->assertNull($valeur->getModifiePar(), 'Une date modifiée seule ne signe pas la valeur.');
    }

    public function testLaFicheAfficheLAuteur(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        [$prospectId, $etapeId] = $this->contextePipeline();

        $this->lien($prospectId)->definirValeur($this->etape($etapeId), PipelineStatut::EN_ATTENTE, new \DateTime('2024-03-12'));
        $this->em()->flush();
        $valeur = $this->valeur($prospectId, $etapeId);
        $this->assertInstanceOf(PipelineValeur::class, $valeur);
        $valeur->setModifiePar($this->utilisateurConnecte());
        $this->em()->flush();
        $username = (string) $valeur->getModifiePar()?->getUsername();

        $client->request('GET', '/user/prospects/show/'.$prospectId);
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('12/03/2024 · '.$username, $html, 'La fiche affiche date et auteur.');

        $client->request('GET', '/user/reporting/prospect/'.$prospectId.'/pdf');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');
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

    private function utilisateurConnecte(): User
    {
        $user = $this->em()->getRepository(User::class)->findOneBy([]);
        $this->assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * Contexte isolé : un prospect, une vague dédiée et un pipeline à une
     * étape, sans dépendre des fixtures existantes.
     *
     * @return array{0: int, 1: int} identifiants du prospect et de l'étape
     */
    private function contextePipeline(): array
    {
        $em = $this->em();

        $pipeline = new Pipeline();
        $pipeline->setNom('ZZTEST Pipeline '.uniqid());
        $etape = new PipelineEtape();
        $etape->setNom('ZZTEST Étape');
        $etape->setOrdre(1);
        $pipeline->addEtape($etape);
        $em->persist($pipeline);

        $sprint = new Sprint();
        $sprint->setNumero(random_int(900000, 999999));
        $sprint->setPipeline($pipeline);
        $em->persist($sprint);

        $prospect = new Prospect();
        $prospect->setNom('ZZTEST Pipeline '.uniqid());
        $lien = new ProspectSprint();
        $lien->setSprint($sprint);
        $prospect->addSprint($lien);
        $em->persist($prospect);
        $em->flush();

        $this->prospectsCrees[] = (int) $prospect->getId();
        $this->sprintsCrees[] = (int) $sprint->getId();
        $this->pipelinesCrees[] = (int) $pipeline->getId();

        return [(int) $prospect->getId(), (int) $etape->getId()];
    }

    private function lien(int $prospectId): ProspectSprint
    {
        $em = $this->em();
        $em->clear();
        $prospect = $em->find(Prospect::class, $prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $lien = $prospect->vagueCourante();
        $this->assertInstanceOf(ProspectSprint::class, $lien);

        return $lien;
    }

    private function etape(int $etapeId): PipelineEtape
    {
        $etape = $this->em()->find(PipelineEtape::class, $etapeId);
        $this->assertInstanceOf(PipelineEtape::class, $etape);

        return $etape;
    }

    private function valeur(int $prospectId, int $etapeId): ?PipelineValeur
    {
        $prospect = $this->em()->find(Prospect::class, $prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);

        return $prospect->vagueCourante()?->valeurPour($this->etape($etapeId));
    }

    private function csrfCycle(KernelBrowser $client, int $prospectId, int $etapeId): string
    {
        $client->request('GET', '/user/prospects/show/'.$prospectId);
        $this->assertResponseIsSuccessful();
        $bouton = $client->getCrawler()->filter('.js-cycle-etape[data-prospect="'.$prospectId.'"][data-etape="'.$etapeId.'"]');
        $this->assertSame(1, $bouton->count(), 'Le badge de cycle doit être présent sur la fiche.');
        $token = (string) $bouton->attr('data-csrf');
        $this->assertNotSame('', $token);

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    private function cycler(KernelBrowser $client, int $prospectId, int $etapeId, string $csrf): array
    {
        $client->request('POST', '/user/prospects/cycle-etape', [
            '_csrf_token' => $csrf,
            'prospect' => (string) $prospectId,
            'etape' => (string) $etapeId,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $corps = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($corps);
        $this->assertTrue($corps['ok']);

        return $corps;
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

        $this->prospectsCrees = [];
        $this->sprintsCrees = [];
        $this->pipelinesCrees = [];

        parent::tearDown();
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
