<?php

namespace App\Tests;

use App\Entity\Contact;
use App\Entity\Prospect;
use App\Repository\ContactRepository;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Règles de validation et comportement des setters de {@see Contact} :
 *  - nom OU prénom obligatoire (au moins un des deux non vide)
 *  - nomComplet recalculé à chaque modification du nom ou du prénom
 *  - URL de retour transportée par le champ caché `retour` (aucun paramètre
 *    d'URL), utilisée par Valider/Annuler.
 */
class ContactValidationTest extends WebTestCase
{
    public function testSetterNomMetEnMajuscules(): void
    {
        $contact = new Contact();
        $contact->setNom('dupont');
        $this->assertSame('DUPONT', $contact->getNom());
        $this->assertSame('DUPONT', $contact->getNomComplet());

        $contact->setNom('martin-smith');
        $this->assertSame('MARTIN-SMITH', $contact->getNom());
    }

    public function testSetterNomRegenereNomComplet(): void
    {
        $contact = new Contact();
        $contact->setPrenom('Jean');
        $this->assertSame('Jean', $contact->getNomComplet());

        $contact->setNom('Dupont');
        $this->assertSame('DUPONT Jean', $contact->getNomComplet());

        $contact->setNom('Martin');
        $this->assertSame('MARTIN Jean', $contact->getNomComplet());
    }

    public function testSetterPrenomRegenereNomComplet(): void
    {
        $contact = new Contact();
        $contact->setNom('Dupont');
        $this->assertSame('DUPONT', $contact->getNomComplet());

        $contact->setPrenom('Jean');
        $this->assertSame('DUPONT Jean', $contact->getNomComplet());

        $contact->setPrenom('Marie');
        $this->assertSame('DUPONT Marie', $contact->getNomComplet());
    }

    public function testSetterAvecChaineVideNInserePasEspaceVide(): void
    {
        $contact = new Contact();
        $contact->setPrenom('');
        $contact->setNom('Dupont');
        $this->assertSame('DUPONT', $contact->getNomComplet());

        $contact->setPrenom(null);
        $this->assertSame('DUPONT', $contact->getNomComplet());
    }

    public function testCreationAvecNomSeulementEstAcceptee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfContact($client, $prospect->getId(), 'list');
        $client->request('POST', '/user/contacts/submit', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'ZZTEST NomSeul',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/contacts');

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST NOMSEUL']);
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame('ZZTEST NOMSEUL', $contact->getNomComplet());
        $this->assertNull($contact->getPrenom());

        $this->supprimer($contact);
    }

    public function testCreationAvecPrenomSeulementEstAcceptee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfContact($client, $prospect->getId(), 'list');
        $client->request('POST', '/user/contacts/submit', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => '',
                'prenom' => 'ZZTEST-Prenom',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/contacts');

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['prenom' => 'ZZTEST-Prenom']);
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame('ZZTEST-Prenom', $contact->getNomComplet());
        $this->assertNull($contact->getNom());

        $this->supprimer($contact);
    }

    public function testCreationAvecNomEtPrenomVidesEstRejetee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfContact($client, $prospect->getId(), 'list');
        $client->request('POST', '/user/contacts/submit', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => '',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => '', 'prenom' => '']);
        $this->assertNull($contact);
    }

    public function testUpdateChangeNomRegenereNomComplet(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $em = $this->em();
        $contact = new Contact();
        $contact->setProspect($prospect);
        $contact->setNom('Init');
        $contact->setPrenom('Avant');
        $em->persist($contact);
        $em->flush();
        $contactId = $contact->getId();
        $em->clear();

        $token = $this->csrfContact($client, $prospect->getId(), 'update', $contactId);
        $client->request('POST', '/user/contacts/update/'.$contactId, [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'Apres',
                'prenom' => 'Apres',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/contacts');

        $em->clear();
        $contact = $this->contacts()->find($contactId);
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame('APRES Apres', $contact->getNomComplet());

        $this->supprimer($contact);
    }

    public function testRetourVientDeLaFicheProspectConsultee(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        // 1. On consulte la fiche prospect : elle devient la page de retour.
        $fiche = '/user/prospects/show/'.$prospect->getId();
        $client->request('GET', $fiche);
        $this->assertResponseIsSuccessful();

        // 2. Le formulaire de création fige cette URL dans son champ caché.
        $crawler = $client->request('GET', '/user/contacts/submit?prospect='.$prospect->getId());
        $token = (string) $crawler->filter('input[name="contact[_token]"]')->attr('value');
        $retour = (string) $crawler->filter('input[name="contact[retour]"]')->attr('value');
        $this->assertSame($fiche, $retour, 'Le retour doit être la fiche prospect consultée.');

        // 3. La validation ramène sur la fiche, sans aucun paramètre d'URL.
        $client->request('POST', '/user/contacts/submit', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'ZZTEST retourFiche',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'retour' => $retour,
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects($fiche);

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST RETOURFICHE']);
        if ($contact instanceof Contact) {
            $this->supprimer($contact);
        }
    }

    public function testRetourIgnoreUneUrlExterne(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfContact($client, $prospect->getId());
        $client->request('POST', '/user/contacts/submit', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'ZZTEST retourExterne',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'retour' => 'https://evil.example/pwn',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/contacts');

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST RETOUREXTERNE']);
        if ($contact instanceof Contact) {
            $this->supprimer($contact);
        }
    }

    public function testChampRetourEstChampHiddenDansLeFormulaire(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $client->request('GET', '/user/prospects/show/'.$prospect->getId());
        $client->request('GET', '/user/contacts/submit?prospect='.$prospect->getId());

        $hidden = $client->getCrawler()->filter('input[type="hidden"][name="contact[retour]"]');
        $this->assertSame(1, $hidden->count(), 'Le champ retour doit être rendu.');
        $this->assertSame(
            '/user/prospects/show/'.$prospect->getId(),
            $hidden->attr('value'),
            'Le champ retour doit contenir la page précédente.',
        );
    }

    public function testListeContactNeTransporteAucunRetour(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        // La liste doit contenir au moins une ligne pour porter un lien
        // « Modifier » : on cree le contact ici plutot que de dependre de
        // fixtures absentes.
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $prospect = $em->getRepository(Prospect::class)->findOneBy([]);
        if (null === $prospect) {
            static::markTestSkipped('Aucun prospect en base de test.');
        }
        $contact = new Contact();
        $contact->setNom('ZZTEST-liste-contact');
        $contact->setProspect($prospect);
        $em->persist($contact);
        $em->flush();

        $client->request('GET', '/user/contacts');
        $crawler = $client->getCrawler();

        $btn = $crawler->filter('a.btn-success');
        $this->assertGreaterThan(0, $btn->count(), 'Bouton Ajouter absent.');
        $this->assertSame('/user/contacts/submit', $btn->first()->attr('href'));

        $modifier = $crawler->filter('a[href*="/user/contacts/update/"][title="Modifier"]');
        $this->assertGreaterThan(0, $modifier->count(), 'Aucun lien Modifier trouvé.');
        $this->assertStringNotContainsString('redirect=', (string) $modifier->first()->attr('href'));
        $this->assertStringNotContainsString('retour=', (string) $modifier->first()->attr('href'));

        $this->supprimer($contact);
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
        if (!$user instanceof \App\Entity\User) {
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

    private function contacts(): ContactRepository
    {
        $repo = static::getContainer()->get(ContactRepository::class);
        if (!$repo instanceof ContactRepository) {
            throw new \LogicException('Repository contacts indisponible.');
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

    private function csrfContact(KernelBrowser $client, ?int $prospectId, string $mode = 'submit', ?int $contactId = null): string
    {
        $uri = match ($mode) {
            'update' => '/user/contacts/update/'.$contactId,
            default => '/user/contacts/submit'.(null !== $prospectId ? '?prospect='.$prospectId : ''),
        };
        $crawler = $client->request('GET', $uri);
        $champ = $crawler->filter('input[name="contact[_token]"]');
        $this->assertNotSame(0, $champ->count(), 'Champ CSRF introuvable.');

        return (string) $champ->attr('value');
    }

    private function supprimer(Contact $contact): void
    {
        $em = $this->em();
        $em->remove($contact);
        $em->flush();
    }
}
