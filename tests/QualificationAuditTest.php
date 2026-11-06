<?php

namespace App\Tests;

use App\Entity\Cible;
use App\Entity\Prospect;
use App\Entity\ProspectCible;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Audit de la qualification d'un prospect sur une cible
 * (non / oui / hors cible) : chaque changement effectif de `qualifie`
 * est daté et signé par son auteur.
 */
class QualificationAuditTest extends WebTestCase
{
    public function testLeCycleDateEtSigneLeChangement(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $user = $this->utilisateurConnecte();
        $prospect = $this->creerProspect();
        $cible = $this->creerCible();
        $lien = $this->lier($prospect, $cible, null);

        $this->assertNull($lien->getQualifieModifieAt(), 'Un lien créé « Non » n\'est pas daté.');

        // Le kernel de test n'est pas redémarré entre les requêtes : on
        // détache les entités pour que les contrôleurs relisent la base.
        $this->em()->clear();
        $csrf = $this->csrfCycle($client, $prospect, $cible);

        // null → true : daté et signé par l'utilisateur courant.
        $this->basculer($client, $prospect, $cible, $csrf);
        $this->assertResponseIsSuccessful();
        $lien = $this->recharger($lien);
        $this->assertTrue($lien->getQualifie());
        $this->assertInstanceOf(\DateTime::class, $lien->getQualifieModifieAt());
        $this->assertSame((new \DateTime())->format('Y-m-d'), $lien->getQualifieModifieAt()->format('Y-m-d'));
        $this->assertSame($user->getUsername(), $lien->getQualifieModifiePar()?->getUsername());

        // true → false (Hors cible) : nouvel horodatage.
        $precedent = $lien->getQualifieModifieAt();
        $this->basculer($client, $prospect, $cible, $csrf, 1);
        $this->assertResponseIsSuccessful();
        $lien = $this->recharger($lien);
        $this->assertFalse($lien->getQualifie());
        $this->assertGreaterThanOrEqual($precedent, $lien->getQualifieModifieAt());

        // false → null (Non) : plus de décision, date et auteur sont vidés.
        $this->basculer($client, $prospect, $cible, $csrf, 1);
        $this->assertResponseIsSuccessful();
        $lien = $this->recharger($lien);
        $this->assertNull($lien->getQualifie());
        $this->assertNull($lien->getQualifieModifieAt(), 'Le retour à « Non » doit vider la date.');
        $this->assertNull($lien->getQualifieModifiePar(), 'Le retour à « Non » doit vider l\'auteur.');

        // Nouveau cycle après remise à zéro : la date repart de zéro.
        $this->basculer($client, $prospect, $cible, $csrf, 1);
        $this->assertResponseIsSuccessful();
        $lien = $this->recharger($lien);
        $this->assertTrue($lien->getQualifie());
        $this->assertInstanceOf(\DateTime::class, $lien->getQualifieModifieAt());
        $this->assertSame($user->getUsername(), $lien->getQualifieModifiePar()?->getUsername());

        $this->nettoyer($lien, $prospect, $cible);
    }

    public function testUnEnregistrementSansChangementDeValeurNeRehorodatePas(): void
    {
        $prospect = $this->creerProspect();
        $cible = $this->creerCible();
        $lien = $this->lier($prospect, $cible, true);

        $ancienne = new \DateTime('2020-01-01 08:30:00');
        $lien->setQualifieModifieAt($ancienne);
        $this->em()->flush();

        // Modification de la qualification libre (texte) : `qualifie` ne
        // change pas, l'audit ne doit pas bouger.
        $lien->setQualification('Secteur porteur');
        $this->em()->flush();
        $this->em()->clear();

        $lien = $this->em()->find(ProspectCible::class, (int) $lien->getId());
        $this->assertInstanceOf(ProspectCible::class, $lien);
        $this->assertSame('2020-01-01 08:30:00', $lien->getQualifieModifieAt()?->format('Y-m-d H:i:s'));

        // Changement réel : réhorodaté.
        $lien->setQualifie(false);
        $this->em()->flush();
        $this->em()->clear();

        $lien = $this->em()->find(ProspectCible::class, (int) $lien->getId());
        $this->assertInstanceOf(ProspectCible::class, $lien);
        $this->assertSame((new \DateTime())->format('Y-m-d'), $lien->getQualifieModifieAt()?->format('Y-m-d'));

        $this->nettoyer($lien, $prospect, $cible);
    }

    public function testLaFicheAfficheLeDernierChangement(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $prospect = $this->creerProspect();
        $cible = $this->creerCible();
        $lien = $this->lier($prospect, $cible, true);
        $lien->setQualifieModifieAt(new \DateTime('2024-03-12 15:45:00'));
        $lien->setQualifieModifiePar($this->utilisateurConnecte());
        $this->em()->flush();

        $this->em()->clear();

        $client->request('GET', '/user/prospects/show/'.$prospect->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Qualifiée par', $html, 'La colonne s\'intitule « Qualifiée par ».');
        $this->assertStringContainsString('12/03/2024 15:45', $html, 'La fiche affiche la date du dernier changement.');
        $this->assertStringContainsString((string) $this->utilisateurConnecte()->getUsername(), $html, 'La fiche affiche l\'auteur du dernier changement.');

        // Écran d'édition : la ligne de cible rappelle l'auteur et la date.
        $client->request('GET', '/user/prospects/update/'.$prospect->getId());
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Qualifiée par '.$this->utilisateurConnecte()->getUsername().' le 12/03/2024 15:45', $html);

        // Export PDF : la même information est reprise dans le tableau des cibles.
        $client->request('GET', '/user/reporting/prospect/'.$prospect->getId().'/pdf');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');

        $this->nettoyer($lien, $prospect, $cible);
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

    private function creerProspect(): Prospect
    {
        $prospect = new Prospect();
        $prospect->setNom('ZZTEST Audit '.uniqid());
        $this->em()->persist($prospect);
        $this->em()->flush();

        return $prospect;
    }

    private function creerCible(): Cible
    {
        $cible = new Cible();
        $cible->setTitre('ZZTEST Cible '.uniqid());
        $this->em()->persist($cible);
        $this->em()->flush();

        return $cible;
    }

    private function lier(Prospect $prospect, Cible $cible, ?bool $qualifie): ProspectCible
    {
        $lien = new ProspectCible();
        $lien->setProspect($prospect);
        $lien->setCible($cible);
        $lien->setQualifie($qualifie);
        $this->em()->persist($lien);
        $this->em()->flush();

        return $lien;
    }

    private function recharger(ProspectCible $lien): ProspectCible
    {
        $this->em()->clear();
        $recharge = $this->em()->find(ProspectCible::class, (int) $lien->getId());
        $this->assertInstanceOf(ProspectCible::class, $recharge);

        return $recharge;
    }

    private function csrfCycle(KernelBrowser $client, Prospect $prospect, Cible $cible): string
    {
        $client->request('GET', '/user/prospects/show/'.$prospect->getId());
        $bouton = $client->getCrawler()->filter('.js-cycle-cible[data-cible="'.$cible->getId().'"]');
        $this->assertSame(1, $bouton->count(), 'Le badge de qualification doit être présent sur la fiche.');
        $token = (string) $bouton->attr('data-csrf');
        $this->assertNotSame('', $token);

        return $token;
    }

    private function basculer(KernelBrowser $client, Prospect $prospect, Cible $cible, string $csrf, int $fois = 0): void
    {
        for ($i = 0; $i < max(1, $fois); ++$i) {
            $client->request('POST', '/user/prospects/toggle-cible/'.$prospect->getId().'/'.$cible->getId(), [
                '_csrf_token' => $csrf,
            ], [], [
                'HTTP_ORIGIN' => 'http://localhost',
                'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            ]);
        }
    }

    private function nettoyer(?ProspectCible $lien, Prospect $prospect, Cible $cible): void
    {
        $em = $this->em();
        $em->clear();
        foreach ([$lien, $prospect, $cible] as $entite) {
            if (null === $entite || null === $entite->getId()) {
                continue;
            }
            $recharge = $em->find($entite::class, $entite->getId());
            if (null !== $recharge) {
                $em->remove($recharge);
            }
        }
        $em->flush();
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
