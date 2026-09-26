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
 *  - URL de retour (champ hidden redirect) préservée entre submit/update
 *    et utilisée par Valider/Annuler.
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
        $client->request('POST', '/user/contacts/submit?redirect=/user/contacts', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'ZZTEST NomSeul',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'redirect' => '/user/contacts',
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
        $client->request('POST', '/user/contacts/submit?redirect=/user/contacts', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => '',
                'prenom' => 'ZZTEST-Prenom',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'redirect' => '/user/contacts',
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
        $client->request('POST', '/user/contacts/submit?redirect=/user/contacts', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => '',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'redirect' => '/user/contacts',
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
        $client->request('POST', '/user/contacts/update/'.$contactId.'?redirect=/user/contacts', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'Apres',
                'prenom' => 'Apres',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'redirect' => '/user/contacts',
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

    public function testRedirectVersFicheProspect(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfContact($client, $prospect->getId(), 'submit');
        $redirect = '/user/prospects/show/'.$prospect->getId();
        $client->request('POST', '/user/contacts/submit?redirect='.urlencode($redirect), [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'ZZTEST redirectP',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'redirect' => $redirect,
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects($redirect);

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST REDIRECTP']);
        if ($contact instanceof Contact) {
            $this->supprimer($contact);
        }
    }

    public function testChampRedirectEstChampHiddenDansLeFormulaire(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $redirect = '/user/contacts?test=1';
        $client->request('GET', '/user/contacts/submit?redirect='.urlencode($redirect).'&prospect='.$prospect->getId());
        $crawler = $client->getCrawler();
        $hidden = $crawler->filter('input[type="hidden"][name="contact[redirect]"]');
        $this->assertSame(1, $hidden->count(), 'Le champ redirect doit être rendu.');
        $this->assertSame($redirect, $hidden->attr('value'));
    }

    public function testListeContactPasseRedirectVersListe(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $client->request('GET', '/user/contacts');
        $crawler = $client->getCrawler();
        $btn = $crawler->filter('a.btn-success');
        $this->assertGreaterThan(0, $btn->count(), 'Bouton Ajouter absent.');
        $href = $btn->first()->attr('href');
        $this->assertNotNull($href);
        $this->assertStringContainsString('redirect=', $href, 'Le bouton Ajouter doit passer ?redirect= pour le retour.');
        // L'URL est construite par Twig path() qui n'encode pas les caractères réservés
        // (les "/" restent tels quels), on la compare sans urlencode.
        $this->assertStringContainsString('/user/contacts', $href);
        $this->assertMatchesRegularExpression('#\?redirect=(%2F|\/)user(%2F|\/)contacts#', $href);

        // Au moins un lien « Modifier » doit aussi pointer vers update avec redirect.
        $modifier = $crawler->filter('a[href*="update"][title="Modifier"]');
        $this->assertGreaterThan(0, $modifier->count(), 'Aucun lien Modifier trouvé.');
        $mhref = $modifier->first()->attr('href');
        $this->assertNotNull($mhref);
        $this->assertMatchesRegularExpression('#\?redirect=(%2F|\/)user(%2F|\/)contacts#', $mhref);
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
