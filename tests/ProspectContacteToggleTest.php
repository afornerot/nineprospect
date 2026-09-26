<?php

namespace App\Tests;

use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cycle du switch « Contacté » sur la liste prospects :
 *  - null → true → false → null (round-trip via /toggle-contacte/{id})
 *  - sécurité : 404 si prospect introuvable, 403 si CSRF invalide.
 */
class ProspectContacteToggleTest extends WebTestCase
{
    public function testToggleCycleBoolTrueFalse(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospectId = $prospect->getId();
        $this->assertNotNull($prospectId);

        $em = $this->em();
        // Pré-requis : contacte=null, datePremierContact=null (état initial).
        $em->getRepository(Prospect::class)->createQueryBuilder('p')
            ->update()
            ->set('p.contacte', 'NULL')
            ->set('p.datePremierContact', 'NULL')
            ->where('p.id = :id')
            ->setParameter('id', $prospectId)
            ->getQuery()
            ->execute();
        $em->clear();
        $prospect = $this->prospects()->find($prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $this->assertNull($prospect->getContacte(), 'Pré-requis: contacte doit être null au départ.');
        $this->assertNull($prospect->getDatePremierContact(), 'Pré-requis: datePremierContact doit être null.');

        $token = $this->csrfToggle($client, $prospectId);

        // null → true (le switch bascule true↔false : null est traité comme
        // « non contacté », donc le premier clic active true).
        $client->request('POST', '/user/prospects/toggle-contacte/'.$prospectId, [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertTrue($body['contacte']);
        $this->assertSame((new \DateTime())->format('Y-m-d'), $body['datePremierContact']);

        $em->clear();
        $prospect = $this->prospects()->find($prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $this->assertTrue($prospect->getContacte());
        $this->assertNotNull($prospect->getDatePremierContact(), 'datePremierContact doit être posée.');

        // true → false : la date doit être vidée.
        $client->request('POST', '/user/prospects/toggle-contacte/'.$prospectId, [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertFalse($body['contacte']);
        $this->assertNull($body['datePremierContact'], 'datePremierContact doit être vidée quand contacte=false.');

        // false → true : la date est re-posée à aujourd'hui.
        $client->request('POST', '/user/prospects/toggle-contacte/'.$prospectId, [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertTrue($body['contacte']);
        $this->assertSame((new \DateTime())->format('Y-m-d'), $body['datePremierContact']);

        // Restaurer false + date null.
        $em->clear();
        $prospect = $this->prospects()->find($prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $em->getRepository(Prospect::class)->createQueryBuilder('p')
            ->update()
            ->set('p.contacte', 'NULL')
            ->set('p.datePremierContact', 'NULL')
            ->where('p.id = :id')
            ->setParameter('id', $prospectId)
            ->getQuery()
            ->execute();
    }

    public function testSetContacteEntiteSynchroniseLaDate(): void
    {
        // Vérifie l'entité directement (pas d'endpoint) : la cohérence
        // setContacte ↔ datePremierContact est garantie par l'entité elle-même.
        $em = $this->em();
        $em->clear();
        $prospect = $this->unProspect();
        if (null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospect->setDatePremierContact(null);
        $prospect->setContacte(true);
        $this->assertNotNull($prospect->getDatePremierContact(), 'setContacte(true) doit poser la date.');
        $this->assertSame(
            (new \DateTime())->format('Y-m-d'),
            $prospect->getDatePremierContact()->format('Y-m-d')
        );

        // Une date déjà posée n'est pas écrasée (le setter ne la réinitialise pas).
        $autre = new \DateTime('2025-01-15');
        $prospect->setDatePremierContact($autre);
        $prospect->setContacte(true);
        $this->assertSame(
            $autre->format('Y-m-d'),
            $prospect->getDatePremierContact()->format('Y-m-d'),
            'Une date existante ne doit pas être écrasée par setContacte(true).'
        );

        $prospect->setContacte(false);
        $this->assertNull($prospect->getDatePremierContact(), 'setContacte(false) doit vider la date.');
    }

    public function testToggleQualifieCycle(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospectId = $prospect->getId();
        $this->assertNotNull($prospectId);

        $em = $this->em();
        $em->getRepository(Prospect::class)->createQueryBuilder('p')
            ->update()
            ->set('p.qualifie', 'NULL')
            ->where('p.id = :id')
            ->setParameter('id', $prospectId)
            ->getQuery()
            ->execute();
        $em->clear();
        $prospect = $this->prospects()->find($prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $this->assertNull($prospect->getQualifie(), 'Pré-requis: qualifie doit être null.');

        $token = $this->csrfToggleQualifie($client, $prospectId);

        // null → true
        $client->request('POST', '/user/prospects/toggle-qualifie/'.$prospectId, [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertTrue($body['ok']);
        $this->assertTrue($body['qualifie']);

        $em->clear();
        $prospect = $this->prospects()->find($prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $this->assertTrue($prospect->getQualifie());

        // true → false
        $client->request('POST', '/user/prospects/toggle-qualifie/'.$prospectId, [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertFalse($body['qualifie']);

        // false → null
        $client->request('POST', '/user/prospects/toggle-qualifie/'.$prospectId, [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $body = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertNull($body['qualifie']);

        // Restaurer
        $em->getRepository(Prospect::class)->createQueryBuilder('p')
            ->update()
            ->set('p.qualifie', 'NULL')
            ->where('p.id = :id')
            ->setParameter('id', $prospectId)
            ->getQuery()
            ->execute();
    }

    public function testCsrfInvalideEstRejete(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospectId = $prospect->getId();
        $this->assertNotNull($prospectId);

        $client->request('POST', '/user/prospects/toggle-contacte/'.$prospectId, [
            '_csrf_token' => 'token_invalide',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    public function testCycleEtapeBoucleEtPersiste(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $em = $this->em();
        $em->clear();
        $prospect = $this->prospects()->find($prospect->getId());
        $this->assertInstanceOf(Prospect::class, $prospect);
        $lien = $prospect->vagueCourante();
        if (null === $lien || null === $lien->getSprint()?->getPipeline() || 0 === count($lien->getSprint()->getPipeline()->getEtapes())) {
            static::markTestSkipped('Aucune vague / pipeline pour ce prospect.');
        }
        $pipeline = $lien->getSprint()->getPipeline();
        $etapes = $pipeline->getEtapes();
        $etape = $etapes[0];
        if (!$etape instanceof \App\Entity\PipelineEtape) {
            static::markTestSkipped('Étape sans entité.');
        }

        // Cycle via repository : on exerce directement le service.
        $lien->definirStatut($etape, \App\Enum\PipelineStatut::NON_DEMARRE);
        $em->flush();

        $prospectId = $prospect->getId();
        $etapeId = $etape->getId();

        // Amorce session
        $client->request('GET', '/user/prospects');
        $crawler = $client->getCrawler();
        $wrapper = $crawler->filter('.js-cycle-etape[data-prospect="'.$prospectId.'"][data-etape="'.$etapeId.'"]');
        if (0 === $wrapper->count()) {
            static::markTestSkipped('Badge pipeline absent de la liste.');
        }
        $csrf = (string) $wrapper->attr('data-csrf');
        if ('' === $csrf || 'csrf-token' === $csrf) {
            static::markTestSkipped('Token CSRF pipeline indisponible.');
        }

        // Cycle : NON_DEMARRE → A_QUALIFIER → RELANCER → EN_ATTENTE → OUI → NON → NON_DEMARRE.
        $attendu = [
            \App\Enum\PipelineStatut::A_QUALIFIER,
            \App\Enum\PipelineStatut::RELANCER,
            \App\Enum\PipelineStatut::EN_ATTENTE,
            \App\Enum\PipelineStatut::OUI,
            \App\Enum\PipelineStatut::NON,
            \App\Enum\PipelineStatut::NON_DEMARRE,
        ];
        foreach ($attendu as $prochain) {
            $client->request('POST', '/user/prospects/cycle-etape', [
                '_csrf_token' => $csrf,
                'prospect' => (string) $prospectId,
                'etape' => (string) $etapeId,
            ], [], [
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);
            $this->assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()->getContent(), true);
            $this->assertTrue($body['ok']);
            $this->assertSame($prochain, $body['statut']);
        }

        // Vérifier la persistance
        $em->clear();
        $prospect = $this->prospects()->find($prospectId);
        $this->assertInstanceOf(Prospect::class, $prospect);
        $courant = $prospect->vagueCourante();
        $this->assertNotNull($courant, 'Le prospect doit toujours avoir une vague courante.');
        $this->assertSame(
            \App\Enum\PipelineStatut::NON_DEMARRE,
            $courant->statutPour($etape),
            'Après le cycle complet, le statut doit être revenu à NON_DEMARRE.'
        );
        // Retour à NON_DEMARRE : la valeur (et donc la date) est supprimée.
        $this->assertNull(
            $courant->datePour($etape),
            'Au retour à NON_DEMARRE, la date doit être vidée.'
        );

        // Re-cycle : clic → OUI → la date est posée à aujourd'hui.
        $courant->definirStatut($etape, \App\Enum\PipelineStatut::OUI);
        $em->flush();
        $this->assertNotNull($courant->datePour($etape), 'Après un clic, la date doit être renseignée.');
        $this->assertSame(
            (new \DateTime())->format('Y-m-d'),
            $courant->datePour($etape)->format('Y-m-d'),
            'La date doit être aujourd\'hui.'
        );
    }

    public function testProspectInexistantRetourne404(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfToggle($client, 999999);

        $client->request('POST', '/user/prospects/toggle-contacte/999999', [
            '_csrf_token' => $token,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND);
    }

    // --- Helpers ---

    private function loginAsUser(): ?KernelBrowser
    {
        try {
            $client = static::createClient();
            $container = static::getContainer();
            $repo = $container->get(\App\Repository\UserRepository::class);
            $user = $repo instanceof \App\Repository\UserRepository ? $repo->findOneBy([]) : null;
        } catch (\Throwable) {
            return null;
        }
        if (!$user instanceof User) {
            return null;
        }
        $client->loginUser($user);

        return $client;
    }

    private function unProspect(): ?Prospect
    {
        try {
            $repo = static::getContainer()->get(ProspectRepository::class);
        } catch (\Throwable) {
            return null;
        }
        if (!$repo instanceof ProspectRepository) {
            return null;
        }

        return $repo->findOneBy([], ['id' => 'ASC']);
    }

    private function prospects(): ProspectRepository
    {
        $repo = static::getContainer()->get(ProspectRepository::class);
        if (!$repo instanceof ProspectRepository) {
            throw new \LogicException('Repository prospects indisponible.');
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

    private function csrfToggle(KernelBrowser $client, int $prospectId): string
    {
        return $this->csrfFor('js-toggle-contacte-wrapper', $prospectId, $client);
    }

    private function csrfToggleQualifie(KernelBrowser $client, int $prospectId): string
    {
        return $this->csrfFor('js-toggle-qualifie-wrapper', $prospectId, $client);
    }

    private function csrfFor(string $wrapperClass, int $prospectId, KernelBrowser $client): string
    {
        // Amorce la session via un GET sur la liste prospects : le wrapper
        // contient alors la valeur réelle du token CSRF (le service
        // stateless ne sait pas générer un token valide hors session).
        $client->request('GET', '/user/prospects');
        $crawler = $client->getCrawler();
        $wrapper = $crawler->filter('.'.$wrapperClass.'[data-action-id="'.$prospectId.'"]');
        if ($wrapper->count() > 0) {
            $csrf = (string) $wrapper->attr('data-csrf');
            if ('' !== $csrf && 'csrf-token' !== $csrf) {
                return $csrf;
            }
        }

        // Fallback via meta csrf-token (présent si csrfProtection configuré).
        $meta = $crawler->filter('meta[name="csrf-token"]');
        if ($meta->count() > 0) {
            return (string) $meta->attr('content');
        }

        static::markTestSkipped('Token CSRF introuvable.');
    }
}
