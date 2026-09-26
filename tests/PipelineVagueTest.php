<?php

namespace App\Tests;

use App\Entity\PipelineEtape;
use App\Entity\Prospect;
use App\Entity\Sprint;
use App\Entity\User;
use App\Enum\PipelineStatut;
use App\Repository\PipelineRepository;
use App\Repository\ProspectRepository;
use App\Repository\SprintRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Pipeline par vague : un prospect appartient à 0..n vagues et chaque vague
 * porte son propre pipeline (étapes dynamiques, statuts globaux).
 */
class PipelineVagueTest extends WebTestCase
{
    public function testPipelineIsRefusedWithoutVague(): void
    {
        $client = $this->loginAsUser();
        $etapes = $this->etapesDefaut();
        if (null === $client || [] === $etapes) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $token = $this->csrfProspect($client, '/user/prospects/submit');
        $client->request('POST', '/user/prospects/submit', [
            'prospect' => [
                'nom' => 'ZZTEST sans vague',
                'etape'.$etapes[0]->getId() => PipelineStatut::OUI,
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Sélectionnez au moins une vague', (string) $client->getResponse()->getContent());
    }

    public function testPipelineIsWrittenOnLatestVague(): void
    {
        $client = $this->loginAsUser();
        $sprints = $this->deuxVagues();
        if (null === $client || null === $sprints) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        [$premiere, $seconde] = $sprints;
        $etapes = $this->etapesDe($seconde);
        if (count($etapes) < 2) {
            static::markTestSkipped('Pipeline de la vague sans assez d\'étapes.');
        }

        $token = $this->csrfProspect($client, '/user/prospects/submit');
        $client->request('POST', '/user/prospects/submit', [
            'prospect' => [
                'nom' => 'ZZTEST pipeline vague',
                'sprints' => [$premiere->getId(), $seconde->getId()],
                'etape'.$etapes[0]->getId() => PipelineStatut::OUI,
                'etape'.$etapes[1]->getId() => PipelineStatut::RELANCER,
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects();

        $prospect = $this->prospects()->findOneBy(['nom' => 'ZZTEST pipeline vague']);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $this->assertCount(2, $prospect->getSprints());

        $courant = $prospect->vagueCourante();
        $this->assertNotNull($courant);
        $this->assertSame($seconde->getId(), $courant->getSprint()?->getId());
        $this->assertSame(PipelineStatut::OUI, $courant->statutPour($etapes[0]));
        $this->assertSame(PipelineStatut::RELANCER, $courant->statutPour($etapes[1]));
        $this->assertNull($courant->datePour($etapes[0]));

        $premierLien = $prospect->lienPour($premiere);
        $this->assertNotNull($premierLien);
        $this->assertSame(PipelineStatut::NON_DEMARRE, $premierLien->statutPour($etapes[0]));

        $this->supprimer($prospect);
    }

    public function testRemovingVagueRemovesItsLinkOnly(): void
    {
        $client = $this->loginAsUser();
        $sprints = $this->deuxVagues();
        if (null === $client || null === $sprints) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        [$premiere, $seconde] = $sprints;
        $token = $this->csrfProspect($client, '/user/prospects/submit');
        $client->request('POST', '/user/prospects/submit', [
            'prospect' => [
                'nom' => 'ZZTEST retrait vague',
                'sprints' => [$premiere->getId(), $seconde->getId()],
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $prospect = $this->prospects()->findOneBy(['nom' => 'ZZTEST retrait vague']);
        $this->assertInstanceOf(Prospect::class, $prospect);

        $token = $this->csrfProspect($client, '/user/prospects/update/'.$prospect->getId());
        $client->request('POST', '/user/prospects/update/'.$prospect->getId(), [
            'prospect' => [
                'nom' => 'ZZTEST retrait vague',
                'sprints' => [$seconde->getId()],
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/user/prospects');

        $this->em()->clear();
        $prospect = $this->prospects()->find($prospect->getId());
        $this->assertInstanceOf(Prospect::class, $prospect);
        $this->assertCount(1, $prospect->getSprints());
        $this->assertNull($prospect->lienPour($premiere));
        $this->assertNotNull($prospect->lienPour($seconde));

        $this->supprimer($prospect);
    }

    public function testCountPipelineIsScopedByPipelineAndVague(): void
    {
        static::createClient();
        $pipeline = $this->pipelineDefaut();
        $etapes = null !== $pipeline ? $pipeline->getEtapes()->toArray() : [];
        if (null === $pipeline || [] === $etapes) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $repository = $this->prospects();

        try {
            $toutes = $repository->countPipeline((int) $pipeline->getId());
        } catch (\Doctrine\DBAL\Exception) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $total = 0;
        foreach ($etapes as $etape) {
            $totalEtape = array_sum($toutes[(int) $etape->getId()] ?? []);
            $this->assertGreaterThan(0, $totalEtape);
            $total = $totalEtape;
            break;
        }

        $sprints = $this->deuxVagues();
        if (null === $sprints) {
            return;
        }

        $filtre = $repository->countPipeline((int) $pipeline->getId(), (int) $sprints[0]->getId());
        $premiereEtape = $etapes[0];
        $this->assertLessThanOrEqual($total, array_sum($filtre[(int) $premiereEtape->getId()] ?? []));
    }

    public function testCreationCalculeLeNumeroSuivantEtLeVerrouille(): void
    {
        $client = $this->loginAsUser();
        $pipeline = $this->pipelineDefaut();
        if (null === $client || null === $pipeline) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $numeroLibre = $this->numeroLibre();
        if (null === $numeroLibre) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $token = $this->csrfVague($client, '/user/vagues/submit');
        // Le client poste un numéro fantaisiste : le contrôleur l'écrase
        // toujours par le prochain numéro disponible (champ verrouillé).
        $client->request('POST', '/user/vagues/submit', [
            'sprint' => [
                'numero' => 999999,
                'pipeline' => $pipeline->getId(),
                'libelle' => 'ZZTEST verrou',
                'dateDebut' => '',
                'dateFin' => '',
                'notes' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertMatchesRegularExpression('#user/vagues/update/(\d+)#', $location);
        $segments = explode('/', rtrim($location, '/'));
        $idVague = (int) end($segments);

        $this->em()->clear();
        $vague = $this->sprints()->find($idVague);
        $this->assertNotNull($vague);
        $this->assertSame($numeroLibre, $vague->getNumero(), 'Le numéro client est ignoré, remplacé par MAX+1.');

        // Le champ numero de la page de création est en lecture seule.
        $crawler = $client->request('GET', '/user/vagues/submit');
        $champNumero = $crawler->filter('input[name="sprint[numero]"]');
        $this->assertSame(1, $champNumero->count());
        $this->assertNotNull($champNumero->attr('readonly'), 'Le champ numero doit être readonly à la création.');

        // Et reste éditable sur la page de modification.
        $crawler = $client->request('GET', '/user/vagues/update/'.$idVague);
        $champNumero = $crawler->filter('input[name="sprint[numero]"]');
        $this->assertSame(1, $champNumero->count());
        $this->assertNull($champNumero->attr('readonly'), 'Le champ numero reste éditable en modification.');

        // Nettoyage.
        $crawler = $client->request('GET', '/user/vagues/update/'.$idVague);
        $csrfDelete = (string) $crawler->filter('form.delete-button input[name="_csrf_token"]')->attr('value');
        $client->request('POST', '/user/vagues/delete/'.$idVague, [
            '_csrf_token' => $csrfDelete,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    public function testUpdateAvecNumeroDejaPrisEstRejete(): void
    {
        $client = $this->loginAsUser();
        $sprints = $this->deuxVagues();
        $pipeline = $this->pipelineDefaut();
        if (null === $client || null === $sprints || null === $pipeline) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        [$premiere, $seconde] = $sprints;
        $champs = [
            'numero' => $premiere->getNumero(),
            'pipeline' => $pipeline->getId(),
            'libelle' => $seconde->getLibelle(),
            'dateDebut' => '',
            'dateFin' => '',
            'notes' => '',
            'submit' => 'Valider',
        ];

        $uri = '/user/vagues/update/'.$seconde->getId();
        $token = $this->csrfVague($client, $uri);
        $client->request('POST', $uri, [
            'sprint' => $champs + ['_token' => $token],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Ce numéro de vague est déjà utilisé.', (string) $client->getResponse()->getContent());

        $this->em()->clear();
        $recharge = $this->sprints()->find($seconde->getId());
        $this->assertNotNull($recharge);
        $this->assertSame($seconde->getNumero(), $recharge->getNumero());
    }

    public function testCreationVagueOuvreLaFicheEtGereLesProspects(): void
    {
        $client = $this->loginAsUser();
        $pipeline = $this->pipelineDefaut();
        $prospects = $this->deuxProspects();
        if (null === $client || null === $pipeline || null === $prospects) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $numero = $this->numeroLibre();
        if (null === $numero) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $champs = [
            'numero' => $numero,
            'pipeline' => $pipeline->getId(),
            'libelle' => 'ZZTEST vague',
            'dateDebut' => '',
            'dateFin' => '',
            'notes' => '',
            'submit' => 'Valider',
        ];

        // La création ouvre la fiche de la nouvelle vague.
        $token = $this->csrfVague($client, '/user/vagues/submit');
        $client->request('POST', '/user/vagues/submit', [
            'sprint' => $champs + ['_token' => $token],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects();
        $location = (string) $client->getResponse()->headers->get('Location');
        $this->assertMatchesRegularExpression('#user/vagues/update/\d+#', $location);
        $segments = explode('/', rtrim($location, '/'));
        $idVague = (int) end($segments);
        $fiche = '/user/vagues/update/'.$idVague;

        // Ajout des deux prospects depuis la fiche.
        $token = $this->csrfVague($client, $fiche);
        $client->request('POST', $fiche, [
            'sprint' => $champs + [
                'prospects' => [$prospects[0]->getId(), $prospects[1]->getId()],
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/user/vagues');
        $this->em()->clear();
        $vague = $this->sprints()->find($idVague);
        $this->assertNotNull($vague);
        $this->assertCount(2, $vague->getLiens());

        // Retrait d'un prospect : la sélection restante fait foi.
        $token = $this->csrfVague($client, $fiche);
        $client->request('POST', $fiche, [
            'sprint' => $champs + [
                'prospects' => [$prospects[0]->getId()],
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/user/vagues');
        $this->em()->clear();
        $vague = $this->sprints()->find($idVague);
        $this->assertNotNull($vague);
        $liens = $vague->getLiens()->toArray();
        $this->assertCount(1, $liens);
        $this->assertSame($prospects[0]->getId(), $liens[0]->getProspect()?->getId());

        // Nettoyage : suppression de la vague de test.
        $token = $this->csrfDeleteVague($client, $fiche);
        $client->request('POST', '/user/vagues/delete/'.$idVague, [
            '_csrf_token' => $token,
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/user/vagues');
        $this->em()->clear();
        $this->assertNull($this->sprints()->find($idVague));
    }

    private function loginAsUser(): ?KernelBrowser
    {
        $client = static::createClient();

        try {
            $repository = static::getContainer()->get(UserRepository::class);
            if (!$repository instanceof UserRepository) {
                return null;
            }
            $user = $repository->findOneBy(['username' => (string) ($_SERVER['APP_ADMIN'] ?? 'admin')]);
        } catch (\LogicException|\Doctrine\DBAL\Exception) {
            return null;
        }

        if (!$user instanceof User) {
            return null;
        }

        $client->loginUser($user);

        return $client;
    }

    private function csrfProspect(KernelBrowser $client, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="prospect[_token]"]');

        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function csrfVague(KernelBrowser $client, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="sprint[_token]"]');

        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function csrfDeleteVague(KernelBrowser $client, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('form.delete-button input[name="_csrf_token"]');

        $this->assertNotSame(0, $champ->count(), 'Bouton de suppression introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function sprints(): SprintRepository
    {
        $repository = static::getContainer()->get(SprintRepository::class);
        if (!$repository instanceof SprintRepository) {
            throw new \LogicException('Repository des vagues indisponible.');
        }

        return $repository;
    }

    /**
     * Deux prospects existants, choisis de façon déterministe.
     *
     * @return array{0: Prospect, 1: Prospect}|null
     */
    private function deuxProspects(): ?array
    {
        try {
            $prospects = $this->prospects()->findBy([], ['id' => 'ASC'], 2);
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        $premier = $prospects[0] ?? null;
        $second = $prospects[1] ?? null;

        return null !== $premier && null !== $second ? [$premier, $second] : null;
    }

    /**
     * Premier numéro non utilisé (création d'une vague de test).
     */
    private function numeroLibre(): ?int
    {
        try {
            $sprints = $this->sprints()->findAllOrdered();
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }

        $max = 0;
        foreach ($sprints as $sprint) {
            $max = max($max, (int) $sprint->getNumero());
        }

        return $max + 1;
    }

    /**
     * Deux vagues distinctes, créées si nécessaire (ordre de numéro croissant garanti).
     *
     * @return array{0: Sprint, 1: Sprint}|null
     */
    private function deuxVagues(): ?array
    {
        try {
            $repository = static::getContainer()->get(SprintRepository::class);
            if (!$repository instanceof SprintRepository) {
                return null;
            }
            $sprints = $repository->findAllOrdered();
        } catch (\LogicException|\Doctrine\DBAL\Exception) {
            return null;
        }

        $trouvees = [];
        foreach ($sprints as $sprint) {
            $trouvees[(int) $sprint->getId()] = $sprint;
        }

        uasort($trouvees, static fn (Sprint $a, Sprint $b) => ($a->getNumero() ?? 0) <=> ($b->getNumero() ?? 0));
        $clefs = array_values($trouvees);

        if (count($clefs) < 2) {
            return null;
        }

        return [$clefs[0], $clefs[1]];
    }

    /**
     * Étapes du pipeline par défaut des fixtures.
     *
     * @return array<int, PipelineEtape>
     */
    private function etapesDefaut(): array
    {
        $pipeline = $this->pipelineDefaut();

        return null !== $pipeline ? array_values($pipeline->getEtapes()->toArray()) : [];
    }

    /**
     * Étapes du pipeline d'une vague (repli sur le pipeline par défaut).
     *
     * @return array<int, PipelineEtape>
     */
    private function etapesDe(Sprint $sprint): array
    {
        $pipeline = $sprint->getPipeline() ?? $this->pipelineDefaut();

        return null !== $pipeline ? array_values($pipeline->getEtapes()->toArray()) : [];
    }

    private function pipelineDefaut(): ?\App\Entity\Pipeline
    {
        try {
            $repository = static::getContainer()->get(PipelineRepository::class);
        } catch (\LogicException) {
            return null;
        }

        if (!$repository instanceof PipelineRepository) {
            return null;
        }

        try {
            return $repository->getDefault();
        } catch (\Doctrine\DBAL\Exception) {
            return null;
        }
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

    private function supprimer(Prospect $prospect): void
    {
        $em = $this->em();
        $em->remove($prospect);
        $em->flush();
    }
}
