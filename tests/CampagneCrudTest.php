<?php

namespace App\Tests;

use App\Entity\Campagne;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\CampagneRepository;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Rattachement des prospects à une campagne depuis la fiche (mêmes règles que
 * pour les vagues, mais la relation est ManyToOne côté prospect : ajouter un
 * prospect le déplace vers la campagne, le retirer met `prospect.campagne`
 * à `null`).
 */
class CampagneCrudTest extends WebTestCase
{
    public function testCampagneProspectsAjoutEtRetrait(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de données de test indisponible (nécessite la stack Docker).');
        }

        // Campagne de test (la première disponible).
        $campagne = $this->campagnes()->findOneBy([], ['id' => 'ASC']);
        if (!$campagne instanceof Campagne) {
            static::markTestSkipped('Aucune campagne en base de test.');
        }
        $campagneId = (int) $campagne->getId();

        // Repère deux prospects : l'un déjà rattaché, l'autre ailleurs (ou libre).
        $deuxProspects = $this->prospects()->findBy([], ['id' => 'ASC'], 2);
        $premier = $deuxProspects[0] ?? null;
        $second = $deuxProspects[1] ?? null;
        if (!$premier instanceof Prospect || !$second instanceof Prospect) {
            static::markTestSkipped('Pas assez de prospects en base de test.');
        }

        // Mémorise la campagne d'origine du second pour la restaurer ensuite.
        $campagneOrigineSecond = $second->getCampagne();
        $premier->setCampagne(null);
        $second->setCampagne(null);
        $this->em()->flush();

        try {
            // Ajout des deux prospects depuis la fiche de la campagne.
            $uri = '/user/campagnes/update/'.$campagneId;
            $token = $this->csrfCampagne($client, $uri);
            $client->request('POST', $uri, [
                'campagne' => [
                    'nom' => $campagne->getNom() ?? '',
                    'externalId' => $campagne->getExternalId() ?? '',
                    'sourceLabel' => $campagne->getSourceLabel() ?? '',
                    'budget' => $campagne->getBudget(),
                    'dateDebut' => '',
                    'dateFin' => '',
                    'notes' => '',
                    'prospects' => [$premier->getId(), $second->getId()],
                    'submit' => 'Valider',
                    '_token' => $token,
                ],
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);

            $this->assertResponseRedirects('/user/campagnes');
            $this->em()->clear();

            $premierRelu = $this->prospects()->find($premier->getId());
            $secondRelu = $this->prospects()->find($second->getId());
            $this->assertNotNull($premierRelu);
            $this->assertNotNull($secondRelu);
            $this->assertSame($campagneId, $premierRelu->getCampagne()?->getId());
            $this->assertSame($campagneId, $secondRelu->getCampagne()?->getId());

            // Retrait d'un prospect : la sélection restante fait foi.
            $token = $this->csrfCampagne($client, $uri);
            $client->request('POST', $uri, [
                'campagne' => [
                    'nom' => $campagne->getNom() ?? '',
                    'externalId' => $campagne->getExternalId() ?? '',
                    'sourceLabel' => $campagne->getSourceLabel() ?? '',
                    'budget' => $campagne->getBudget(),
                    'dateDebut' => '',
                    'dateFin' => '',
                    'notes' => '',
                    'prospects' => [$premier->getId()],
                    'submit' => 'Valider',
                    '_token' => $token,
                ],
            ], [], ['HTTP_ORIGIN' => 'http://localhost']);

            $this->assertResponseRedirects('/user/campagnes');
            $this->em()->clear();

            $premierRelu = $this->prospects()->find($premier->getId());
            $secondRelu = $this->prospects()->find($second->getId());
            $this->assertNotNull($premierRelu);
            $this->assertNotNull($secondRelu);
            $this->assertSame($campagneId, $premierRelu->getCampagne()?->getId());
            $this->assertNull($secondRelu->getCampagne());
        } finally {
            // Restaure l'état initial pour ne pas polluer la base de test.
            $this->em()->clear();
            $p = $this->prospects()->find($premier->getId());
            $s = $this->prospects()->find($second->getId());
            if ($p) {
                $p->setCampagne(null);
            }
            if ($s) {
                $s->setCampagne($campagneOrigineSecond);
            }
            $this->em()->flush();
        }
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

    private function csrfCampagne(KernelBrowser $client, string $uri): string
    {
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="campagne[_token]"]');

        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable sur '.$uri);

        return (string) $champ->attr('value');
    }

    private function campagnes(): CampagneRepository
    {
        $repository = static::getContainer()->get(CampagneRepository::class);
        if (!$repository instanceof CampagneRepository) {
            throw new \LogicException('Repository des campagnes indisponible.');
        }

        return $repository;
    }

    private function prospects(): ProspectRepository
    {
        $repository = static::getContainer()->get(ProspectRepository::class);
        if (!$repository instanceof ProspectRepository) {
            throw new \LogicException('Repository des prospects indisponible.');
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
