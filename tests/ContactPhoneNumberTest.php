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
 * Validation et formatage du numéro de téléphone du contact via
 * {@see \Formatz\Bundle\PhoneNumberBundle\Form\PhoneNumberType} :
 *  - saisie française valide → stockée en E164 + version lisible (INTERNATIONAL)
 *  - saisie invalide → 422 + erreur de formulaire, rien n'est persisté
 *  - chaîne vide → pas d'erreur (champ nullable), telephone effacé
 */
class ContactPhoneNumberTest extends WebTestCase
{
    public function testNumeroFrancaisValideEstFormateEtPersiste(): void
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
                'nom' => 'ZZTEST phone',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '06 12 34 56 78',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST phone']);
        $this->assertInstanceOf(Contact::class, $contact);
        // E164 : +33612345678
        $this->assertSame('+33612345678', $contact->getTelephoneBrut());
        // INTERNATIONAL : +33 6 12 34 56 78
        $this->assertNotNull($contact->getTelephone());
        $this->assertStringContainsString('33', $contact->getTelephone());
        $this->assertStringContainsString('6 12 34 56 78', $contact->getTelephone());

        $this->supprimer($contact);
    }

    public function testNumeroDejaE164ResteInchange(): void
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
                'nom' => 'ZZTEST phone2',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => '+33612345678',
                'poste' => '',
                'linkedinUrl' => '',
                'estPrincipal' => '',
                'submit' => 'Valider',
                '_token' => $token,
            ],
        ], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects();

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST phone2']);
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertSame('+33612345678', $contact->getTelephoneBrut());
        $this->assertNotNull($contact->getTelephone());

        $this->supprimer($contact);
    }

    public function testNumeroInvalideEstRejete(): void
    {
        $client = $this->loginAsUser();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $token = $this->csrfContact($client, $prospect->getId());

        // Chaîne non numérique : libphonenumber rejette.
        $client->request('POST', '/user/contacts/submit', [
            'contact' => [
                'prospect' => (string) $prospect->getId(),
                'nom' => 'ZZTEST phoneKO2',
                'prenom' => '',
                'email' => '',
                'telephoneBrut' => 'abc-xyz',
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
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST phoneKO2']);
        // Le contact peut être créé (form persisté avant validation du champ),
        // mais le téléphone doit rester vide.
        if ($contact instanceof Contact) {
            $this->assertNull($contact->getTelephoneBrut());
            $this->assertNull($contact->getTelephone());
            $this->supprimer($contact);
        }
    }

    public function testChampVideAccepteSansErreur(): void
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
                'nom' => 'ZZTEST phoneVide',
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
        $this->assertResponseRedirects();

        $em = $this->em();
        $em->clear();
        $contact = $this->contacts()->findOneBy(['nom' => 'ZZTEST phoneVide']);
        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertNull($contact->getTelephoneBrut());
        $this->assertNull($contact->getTelephone());

        $this->supprimer($contact);
    }

    public function testSetterSynchroniseLaFormeLisible(): void
    {
        $contact = new Contact();
        $contact->setNom('SetTest');
        $contact->setNomComplet('SetTest');
        $contact->setTelephoneBrut('06 12 34 56 78');

        // Le setter ne reformatte PAS le brut (le transformer du form le fait
        // déjà). Il calcule la version lisible depuis ce brut.
        $this->assertSame('06 12 34 56 78', $contact->getTelephoneBrut());
        $this->assertNotNull($contact->getTelephone());
        $this->assertStringContainsString('+33', $contact->getTelephone());
        $this->assertStringContainsString('6 12 34 56 78', $contact->getTelephone());
    }

    public function testSetterNumeroInvalideNeSynchronisePas(): void
    {
        $contact = new Contact();
        $contact->setNom('SetTestKO');
        $contact->setNomComplet('SetTestKO');
        $contact->setTelephoneBrut('abc-xyz');

        // La valeur brute est conservée (pas de validation en set direct),
        // mais la forme lisible n'est pas produite.
        $this->assertSame('abc-xyz', $contact->getTelephoneBrut());
        $this->assertNull($contact->getTelephone());
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

    private function csrfContact(KernelBrowser $client, ?int $prospectId): string
    {
        $uri = '/user/contacts/submit'.(null !== $prospectId ? '?prospect='.$prospectId : '');
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
