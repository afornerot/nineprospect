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
 * Audit du contact : le passage à « Contacté » est signé par l'utilisateur
 * qui l'a basculé (`prospect.contacte_par_id`, posé par
 * App\EventListener\ContacteModificationListener), affiché « Contacté le …
 * par … » sur la fiche, et vidé au retour à « non contacté ».
 */
class ProspectContacteAuditTest extends WebTestCase
{
    public function testLeBasculementDateEtSigneLeContact(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $username = (string) $this->utilisateurConnecte()->getUsername();
        $prospect = $this->creerProspect();
        $prospectId = (int) $prospect->getId();

        $this->assertNull($prospect->getContacte(), 'Un prospect neuf n\'est pas contacté.');
        $this->assertNull($prospect->getContactePar(), 'Un prospect neuf n\'a pas de signataire.');

        $csrf = $this->csrfToggle($client, $prospectId);

        // null → true : la date et le signataire sont posés.
        $corps = $this->basculer($client, $prospectId, $csrf);
        $this->assertTrue($corps['contacte']);
        $this->assertSame((new \DateTime())->format('Y-m-d'), $corps['datePremierContact']);
        $this->assertSame($username, $corps['contactePar']);

        $recharge = $this->recharger($prospectId);
        $this->assertTrue($recharge->getContacte());
        $this->assertSame($username, $recharge->getContactePar()?->getUsername());

        // true → false : plus de contact, donc plus de signataire.
        $corps = $this->basculer($client, $prospectId, $csrf);
        $this->assertFalse($corps['contacte']);
        $this->assertNull($corps['datePremierContact']);
        $this->assertNull($corps['contactePar'], 'Le retour à « non contacté » vide le signataire.');

        $recharge = $this->recharger($prospectId);
        $this->assertNull($recharge->getContactePar());

        // false → true : signature reposée sur la session courante.
        $corps = $this->basculer($client, $prospectId, $csrf);
        $this->assertTrue($corps['contacte']);
        $this->assertSame($username, $corps['contactePar']);

        // La fiche expose une ligne « Contacté le » (date) et une ligne
        // « Contacté par » (auteur).
        $client->request('GET', '/user/prospects/show/'.$prospectId);
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        $this->assertStringContainsString('Contacté le', $html);
        $this->assertMatchesRegularExpression(
            '/Contacté par<\/dt>\s*<dd class="col-sm-8">\s*<span class="js-contacte-par text-muted">'.$username.'<\/span>/',
            $html,
            'La fiche affiche l\'auteur du contact.'
        );

        // Écran d'édition : le rappel de l'auteur est présent.
        $client->request('GET', '/user/prospects/update/'.$prospectId);
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Contacté par '.$username, (string) $client->getResponse()->getContent());

        // La liste reprend l'auteur dans le title du switch.
        $client->request('GET', '/user/prospects');
        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('title="Contacté par '.$username.'"', (string) $client->getResponse()->getContent());

        // Export PDF : rendu sans erreur avec le nouveau champ.
        $client->request('GET', '/user/reporting/prospect/'.$prospectId.'/pdf');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/pdf');

        $this->supprimer($prospectId);
    }

    public function testEnregistrementSansChangementDeContactNeResignePas(): void
    {
        $prospect = $this->creerProspect();
        $prospect->setContacte(true);
        $this->em()->flush();

        $prospect->setContactePar($this->utilisateurConnecte());
        $this->em()->flush();

        // Modification sans rapport : ni contact ni date ne bougent.
        $prospect->setNotes('Relance prévue');
        $this->em()->flush();
        $this->em()->clear();

        $recharge = $this->recharger((int) $prospect->getId());
        $this->assertSame(
            $this->utilisateurConnecte()->getUsername(),
            $recharge->getContactePar()?->getUsername(),
            'Un enregistrement sans changement de contact préserve le signataire.'
        );

        // Re-dater un contact existant ne réattribue pas la signature.
        $recharge->setDatePremierContact(new \DateTime('2024-05-04'));
        $this->em()->flush();
        $this->em()->clear();

        $recharge = $this->recharger((int) $prospect->getId());
        $this->assertSame('2024-05-04', $recharge->getDatePremierContact()?->format('Y-m-d'));
        $this->assertSame($this->utilisateurConnecte()->getUsername(), $recharge->getContactePar()?->getUsername());

        $this->supprimer((int) $prospect->getId());
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
        $prospect->setNom('ZZTEST Contact '.uniqid());
        $this->em()->persist($prospect);
        $this->em()->flush();

        return $prospect;
    }

    private function recharger(int $id): Prospect
    {
        $this->em()->clear();
        $prospect = $this->prospects()->find($id);
        $this->assertInstanceOf(Prospect::class, $prospect);

        return $prospect;
    }

    /**
     * @return array{ok: bool, contacte: bool|null, datePremierContact: string|null, contactePar: string|null}
     */
    private function basculer(KernelBrowser $client, int $prospectId, string $csrf): array
    {
        $client->request('POST', '/user/prospects/toggle-contacte/'.$prospectId, [
            '_csrf_token' => $csrf,
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();
        $corps = json_decode((string) $client->getResponse()->getContent(), true);
        $this->assertIsArray($corps);
        $this->assertTrue($corps['ok']);
        $this->assertArrayHasKey('contacte', $corps);
        $this->assertArrayHasKey('datePremierContact', $corps);
        $this->assertArrayHasKey('contactePar', $corps);

        $contacte = $corps['contacte'];
        $date = $corps['datePremierContact'];
        $par = $corps['contactePar'];

        return [
            'ok' => true,
            'contacte' => \is_bool($contacte) ? $contacte : null,
            'datePremierContact' => \is_string($date) ? $date : null,
            'contactePar' => \is_string($par) ? $par : null,
        ];
    }

    private function csrfToggle(KernelBrowser $client, int $prospectId): string
    {
        $client->request('GET', '/user/prospects');
        $wrapper = $client->getCrawler()->filter('.js-toggle-contacte-wrapper[data-action-id="'.$prospectId.'"]');
        $this->assertSame(1, $wrapper->count(), 'Le switch « Contacté » doit être présent dans la liste.');
        $token = (string) $wrapper->attr('data-csrf');
        $this->assertNotSame('', $token);

        return $token;
    }

    private function supprimer(int $prospectId): void
    {
        $this->em()->clear();
        $prospect = $this->prospects()->find($prospectId);
        if ($prospect instanceof Prospect) {
            $this->em()->remove($prospect);
            $this->em()->flush();
        }
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
}
