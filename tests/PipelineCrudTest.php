<?php

namespace App\Tests;

use App\Entity\Pipeline;
use App\Entity\PipelineEtape;
use App\Entity\User;
use App\Repository\PipelineRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * CRUD des pipelines (liste, création, édition, étapes, suppressions).
 */
class PipelineCrudTest extends WebTestCase
{
    public function testPipelineCrudLifecycle(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $this->nettoyer();

        // Création
        $token = $this->csrfDeFormulaire($client, 'pipeline', '/user/pipelines/submit');
        $client->request('POST', '/user/pipelines/submit', [
            'pipeline' => [
                'nom' => 'ZZTEST pipeline',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects();
        $pipeline = $this->pipelineParNom('ZZTEST pipeline');
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertFalse($pipeline->isParDefaut());

        $id = (int) $pipeline->getId();

        // Ajout d'une étape
        $uri = '/user/pipelines/'.$id.'/etapes/submit';
        $token = $this->csrfEtape($client, $id);
        $client->request('POST', $uri, [
            'pipeline_etape' => [
                'nom' => 'ZZTEST étape',
                'ordre' => 1,
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects();
        $pipeline = $this->relirePipeline($id);
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertCount(1, $pipeline->getEtapes());
        $etape = $pipeline->getEtapes()->first();
        $this->assertNotFalse($etape);

        // Modification de l'étape
        $etapeId = (int) $etape->getId();
        $uri = '/user/pipelines/'.$id.'/etapes/update/'.$etapeId;
        $token = $this->csrfEtape($client, $id);
        $client->request('POST', $uri, [
            'pipeline_etape' => [
                'nom' => 'ZZTEST étape modifiée',
                'ordre' => 3,
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects();
        $pipeline = $this->relirePipeline($id);
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $etapeModifiee = $pipeline->getEtapes()->first();
        $this->assertInstanceOf(PipelineEtape::class, $etapeModifiee);
        $this->assertSame('ZZTEST étape modifiée', $etapeModifiee->getNom());

        // Suppression de l'étape
        $client->request('POST', '/user/pipelines/'.$id.'/etapes/delete/'.$etapeId, [
            '_csrf_token' => $this->csrfSurPage(
                $client,
                '/user/pipelines/update/'.$id,
                'form.delete-button[action$="/etapes/delete/'.$etapeId.'"] input[name="_csrf_token"]'
            ),
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/user/pipelines/update/'.$id);
        $pipeline = $this->relirePipeline($id);
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertCount(0, $pipeline->getEtapes());

        // Suppression du pipeline
        $client->request('POST', '/user/pipelines/delete/'.$id, [
            '_csrf_token' => $this->csrfDeletePipeline($client, $id),
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/user/pipelines');
        $this->assertNull($this->relirePipeline($id));
    }

    public function testDuplicateOrdreIsRejected(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $this->nettoyer();

        // Création du pipeline de test
        $token = $this->csrfDeFormulaire($client, 'pipeline', '/user/pipelines/submit');
        $client->request('POST', '/user/pipelines/submit', [
            'pipeline' => [
                'nom' => 'ZZTEST pipeline',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $pipeline = $this->pipelineParNom('ZZTEST pipeline');
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $id = (int) $pipeline->getId();

        // Première étape (ordre 1)
        $this->soumettreEtape($client, $id, '/user/pipelines/'.$id.'/etapes/submit', 'ZZTEST A', 1);
        $this->assertResponseRedirects();

        // Ajout avec un ordre déjà pris : erreur de formulaire, pas de violation SQL
        $this->soumettreEtape($client, $id, '/user/pipelines/'.$id.'/etapes/submit', 'ZZTEST B', 1);
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Cet ordre est déjà utilisé dans ce pipeline.', (string) $client->getResponse()->getContent());
        $pipeline = $this->relirePipeline($id);
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertCount(1, $pipeline->getEtapes());

        // Ajout à un ordre libre
        $this->soumettreEtape($client, $id, '/user/pipelines/'.$id.'/etapes/submit', 'ZZTEST B', 2);
        $this->assertResponseRedirects();
        $pipeline = $this->relirePipeline($id);
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $this->assertCount(2, $pipeline->getEtapes());
        $etapeB = $this->etapeParNom($pipeline, 'ZZTEST B');
        $this->assertInstanceOf(PipelineEtape::class, $etapeB);
        $etapeBId = (int) $etapeB->getId();

        // Modification vers un ordre déjà pris : la valeur en base est conservée
        $this->soumettreEtape($client, $id, '/user/pipelines/'.$id.'/etapes/update/'.$etapeBId, 'ZZTEST B', 1);
        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('Cet ordre est déjà utilisé dans ce pipeline.', (string) $client->getResponse()->getContent());
        $pipeline = $this->relirePipeline($id);
        $this->assertInstanceOf(Pipeline::class, $pipeline);
        $etapeB = $this->etapeParNom($pipeline, 'ZZTEST B');
        $this->assertInstanceOf(PipelineEtape::class, $etapeB);
        $this->assertSame(2, $etapeB->getOrdre());
    }

    public function testDuplicateNameIsRejected(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $repository = $this->pipelines();
        $defaut = $repository->getDefault();
        if (null === $defaut) {
            static::markTestSkipped('Pipeline par défaut absent des fixtures de test.');
        }

        $token = $this->csrfDeFormulaire($client, 'pipeline', '/user/pipelines/submit');
        $client->request('POST', '/user/pipelines/submit', [
            'pipeline' => [
                'nom' => $defaut->getNom(),
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseStatusCodeSame(422);
        $this->assertStringContainsString('déjà utilisé', (string) $client->getResponse()->getContent());
    }

    public function testDefaultPipelineCannotBeDeleted(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        $repository = $this->pipelines();
        $defaut = $repository->getDefault();
        if (null === $defaut) {
            static::markTestSkipped('Pipeline par défaut absent des fixtures de test.');
        }

        $defautId = (int) $defaut->getId();
        $client->request('POST', '/user/pipelines/delete/'.$defautId, [
            '_csrf_token' => $this->csrfDeletePipeline($client, $defautId),
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);

        $this->assertResponseRedirects('/user/pipelines/update/'.$defautId);
        $this->assertNotNull($this->relirePipeline($defautId));
    }

    /**
     * Relecture fraîche d'un pipeline : purge l'identité map de l'EntityManager
     * courant avant de relire. Le noyau étant redémarré entre deux requêtes
     * cliente, le repository obtenu au début du test peut appartenir à un
     * conteneur (et un UnitOfWork) devenus périmés.
     */
    private function relirePipeline(int $id): ?Pipeline
    {
        $em = $this->em();
        $em->clear();
        $pipeline = $em->find(Pipeline::class, $id);

        return $pipeline instanceof Pipeline ? $pipeline : null;
    }

    private function pipelineParNom(string $nom): ?Pipeline
    {
        $em = $this->em();
        $em->clear();
        $pipeline = $em->getRepository(Pipeline::class)->findOneBy(['nom' => $nom]);

        return $pipeline instanceof Pipeline ? $pipeline : null;
    }

    private function nettoyer(): void
    {
        $em = $this->em();
        $em->clear();
        foreach ($em->getRepository(Pipeline::class)->findAll() as $pipeline) {
            if ('ZZTEST pipeline' === $pipeline->getNom()) {
                $em->remove($pipeline);
            }
        }
        $em->flush();
        $em->clear();
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

    /**
     * Valeur d'un token CSRF de formulaire, récupérée sur la page de saisie.
     */
    private function csrfDeFormulaire(KernelBrowser $client, string $nom, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="'.$nom.'[_token]"]');

        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    /**
     * Soumission d'un mini-formulaire d'étape (ajout ou modification).
     */
    private function soumettreEtape(KernelBrowser $client, int $id, string $uri, string $nom, int $ordre): void
    {
        $client->request('POST', $uri, [
            'pipeline_etape' => [
                'nom' => $nom,
                'ordre' => $ordre,
                '_token' => $this->csrfEtape($client, $id),
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
    }

    private function etapeParNom(Pipeline $pipeline, string $nom): ?PipelineEtape
    {
        foreach ($pipeline->getEtapes() as $etape) {
            if ($nom === $etape->getNom()) {
                return $etape;
            }
        }

        return null;
    }

    /**
     * Token CSRF d'un mini-formulaire d'étape, extrait de la page d'édition.
     */
    private function csrfEtape(KernelBrowser $client, int $id): string
    {
        return $this->csrfSurPage($client, '/user/pipelines/update/'.$id, 'input[name="pipeline_etape[_token]"]');
    }

    /**
     * Token CSRF du bouton de suppression du pipeline, extrait de la page d'édition.
     */
    private function csrfDeletePipeline(KernelBrowser $client, int $id): string
    {
        return $this->csrfSurPage(
            $client,
            '/user/pipelines/update/'.$id,
            'form.delete-button[action$="/user/pipelines/delete/'.$id.'"] input[name="_csrf_token"]'
        );
    }

    /**
     * Token CSRF extrait d'une page rendue (formulaire ou bouton de suppression).
     */
    private function csrfSurPage(KernelBrowser $client, string $uri, string $selecteur): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter($selecteur);

        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable ('.$selecteur.') sur '.$uri);

        return (string) $champ->first()->attr('value');
    }

    private function pipelines(): PipelineRepository
    {
        $repository = static::getContainer()->get(PipelineRepository::class);
        if (!$repository instanceof PipelineRepository) {
            throw new \LogicException('Repository des pipelines indisponible.');
        }

        return $repository;
    }

    private function em(): \Doctrine\ORM\EntityManagerInterface
    {
        $em = static::getContainer()->get(\Doctrine\ORM\EntityManagerInterface::class);
        if (!$em instanceof \Doctrine\ORM\EntityManagerInterface) {
            throw new \LogicException('Entity manager indisponible.');
        }

        return $em;
    }
}
