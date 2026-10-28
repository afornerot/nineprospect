<?php

namespace App\Tests;

use App\Entity\Action;
use App\Entity\Contact;
use App\Entity\Prospect;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Chaînes de navigation réelles : le retour ne passe plus par la query string,
 * il est reconstruit depuis la session. Ces tests couvrent les parcours qui
 * cassaient avant le refactor.
 */
class RetourNavigationTest extends WebTestCase
{
    public function testListeFiltreeFicheContactPuisRetourListe(): void
    {
        $client = $this->clientConnecte();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        // 1. Liste filtrée : elle devient l'origine de retour.
        $filtre = '/user/prospects?q=&campagne=&sprint=&utilisateur=&contacte=&cible=4&categorie=&qualifie=&statut=';
        $client->request('GET', $filtre);
        $this->assertResponseIsSuccessful();

        // 2. Fiche : « Retour à la liste » reprend le filtre et le scroll.
        $client->request('GET', '/user/prospects/show/'.$prospect->getId());
        $this->assertResponseIsSuccessful();
        $liste = (string) $client->getCrawler()->filter('a:contains("Retour à la liste")')->attr('href');
        $this->assertStringContainsString('cible=4', $liste);
        $this->assertStringContainsString('#prospect-'.$prospect->getId(), $liste);

        // 3. Ajout d'un contact depuis la fiche : le retour fige la fiche.
        $client->request('GET', '/user/contacts/submit?prospect='.$prospect->getId());
        $this->assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        $retour = (string) $crawler->filter('input[name="contact[retour]"]')->attr('value');
        $token = (string) $crawler->filter('input[name="contact[_token]"]')->attr('value');
        $this->assertSame('/user/prospects/show/'.$prospect->getId(), $retour);

        // 4. Enregistrement : retour sur la fiche, aucun paramètre d'URL.
        $client->request('POST', '/user/contacts/submit', ['contact' => [
            'prospect' => (string) $prospect->getId(),
            'nom' => 'ZZTEST parcours',
            'prenom' => '',
            'email' => '',
            'telephoneBrut' => '',
            'poste' => 'Directeur',
            'linkedinUrl' => '',
            'estPrincipal' => '',
            'retour' => $retour,
            'submit' => 'Valider',
            '_token' => $token,
        ]], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/prospects/show/'.$prospect->getId());

        // 5. Le filtre d'origine survit aux allers-retours dans la fiche.
        $client->request('GET', '/user/prospects/show/'.$prospect->getId());
        $listeFinale = (string) $client->getCrawler()->filter('a:contains("Retour à la liste")')->attr('href');
        $this->assertStringContainsString('cible=4', $listeFinale);
        $this->assertStringContainsString('#prospect-'.$prospect->getId(), $listeFinale);

        $this->supprimerContact('ZZTEST PARCOURS');
    }

    public function testListeActionsModificationPuisRetourListe(): void
    {
        $client = $this->clientConnecte();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }
        $action = $this->uneAction();

        $client->request('GET', '/user/actions?vue=retard');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/user/actions/update/'.$action->getId());
        $this->assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        $retour = (string) $crawler->filter('input[name="action[retour]"]')->attr('value');
        $this->assertSame('/user/actions?vue=retard', $retour, 'La vue courante de la liste est conservée.');

        $token = (string) $crawler->filter('input[name="action[_token]"]')->attr('value');
        $client->request('POST', '/user/actions/update/'.$action->getId(), ['action' => [
            'prospect' => (string) $action->getProspect()?->getId(),
            'contact' => '',
            'datePrevue' => $action->getDatePrevue()?->format('Y-m-d') ?? date('Y-m-d'),
            'dateRealisee' => '',
            'typeAction' => 'ZZTEST parcours',
            'resultat' => '',
            'retour' => $retour,
            'submit' => 'Valider',
            '_token' => $token,
        ]], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/actions?vue=retard');
    }

    public function testModificationDepuisAgendaRecaleSurLaNouvelleDate(): void
    {
        $client = $this->clientConnecte();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }
        $action = $this->uneAction();

        $client->request('GET', '/user/agenda');
        $this->assertResponseIsSuccessful();

        $client->request('GET', '/user/actions/update/'.$action->getId());
        $this->assertResponseIsSuccessful();
        $crawler = $client->getCrawler();
        $retour = (string) $crawler->filter('input[name="action[retour]"]')->attr('value');
        $this->assertStringStartsWith('/user/agenda', $retour);

        // L'utilisateur déplace l'action : le retour doit pointer sur la
        // nouvelle date, sinon il ne retrouve pas l'action à l'écran.
        $token = (string) $crawler->filter('input[name="action[_token]"]')->attr('value');
        $client->request('POST', '/user/actions/update/'.$action->getId(), ['action' => [
            'prospect' => (string) $action->getProspect()?->getId(),
            'contact' => '',
            'datePrevue' => '2027-03-15',
            'dateRealisee' => '',
            'typeAction' => 'ZZTEST agenda',
            'resultat' => '',
            'retour' => $retour,
            'submit' => 'Valider',
            '_token' => $token,
        ]], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/agenda?date=2027-03-15');
    }

    public function testChaqueListeGardeSonPropreContexte(): void
    {
        $client = $this->clientConnecte();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $client->request('GET', '/user/prospects?cible=4');
        $client->request('GET', '/user/contacts?utilisateur=1');
        $client->request('GET', '/user/actions?vue=avenir');
        $client->request('GET', '/user/prospects/show/'.$prospect->getId());

        // Le retour générique suit le dernier écran consulté…
        $client->request('GET', '/user/actions/update/'.$this->uneAction()->getId());
        $this->assertSame(
            '/user/prospects/show/'.$prospect->getId(),
            (string) $client->getCrawler()->filter('input[name="action[retour]"]')->attr('value'),
            'Le formulaire revient sur la page réellement consultée juste avant.',
        );

        // …tandis que « Retour à la liste » garde l’entrée indexée de sa
        // section, même après avoir consulté les listes voisines.
        $client->request('GET', '/user/prospects/show/'.$prospect->getId());
        $this->assertStringContainsString(
            'cible=4',
            (string) $client->getCrawler()->filter('a:contains("Retour à la liste")')->attr('href'),
        );
        $client->request('GET', '/user/actions/update/'.$this->uneAction()->getId());
        $client->request('GET', '/user/actions?vue=avenir');
        $client->request('GET', '/user/actions/update/'.$this->uneAction()->getId());
        $this->assertSame(
            '/user/actions?vue=avenir',
            (string) $client->getCrawler()->filter('input[name="action[retour]"]')->attr('value'),
        );
    }

    public function testRetourExterneEstRefuse(): void
    {
        $client = $this->clientConnecte();
        $prospect = $this->unProspect();
        if (null === $client || null === $prospect) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $client->request('GET', '/user/contacts/submit?prospect='.$prospect->getId());
        $token = (string) $client->getCrawler()->filter('input[name="contact[_token]"]')->attr('value');

        $client->request('POST', '/user/contacts/submit', ['contact' => [
            'prospect' => (string) $prospect->getId(),
            'nom' => 'ZZTEST endless',
            'prenom' => '',
            'email' => '',
            'telephoneBrut' => '',
            'poste' => '',
            'linkedinUrl' => '',
            'estPrincipal' => '',
            'retour' => 'https://evil.example/pwn',
            'submit' => 'Valider',
            '_token' => $token,
        ]], [], ['HTTP_ORIGIN' => 'http://localhost']);
        $this->assertResponseRedirects('/user/contacts');

        $this->supprimerContact('ZZTEST ENDLESS');
    }

    // --- Helpers ---

    private function clientConnecte(): ?KernelBrowser
    {
        try {
            $client = static::createClient();
            $depot = static::getContainer()->get(UserRepository::class);
        } catch (\Throwable) {
            return null;
        }
        $user = $depot instanceof UserRepository ? $depot->findOneBy([]) : null;
        if (!$user instanceof \App\Entity\User) {
            return null;
        }
        $client->loginUser($user);

        return $client;
    }

    private function unProspect(): ?Prospect
    {
        try {
            $depot = static::getContainer()->get(ProspectRepository::class);
        } catch (\Throwable) {
            return null;
        }
        if (!$depot instanceof ProspectRepository) {
            return null;
        }

        return $depot->findOneBy([]);
    }

    /**
     * Une action en base, créée à la volée si le jeu de test est vide.
     */
    private function uneAction(): Action
    {
        $em = $this->em();
        $action = $em->getRepository(Action::class)->findOneBy([]);
        if ($action instanceof Action) {
            return $action;
        }

        $action = new Action();
        $action->setProspect($this->unProspect());
        $action->setTypeAction('ZZTEST fixture');
        $action->setDatePrevue(new \DateTime('2026-10-30'));
        $em->persist($action);
        $em->flush();

        return $action;
    }

    private function supprimerContact(string $nom): void
    {
        $em = $this->em();
        $em->clear();
        $contact = $em->getRepository(Contact::class)->findOneBy(['nom' => $nom]);
        if ($contact instanceof Contact) {
            $em->remove($contact);
            $em->flush();
        }
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
