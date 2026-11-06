<?php

namespace App\Tests;

use App\Entity\Contact;
use App\Entity\Prospect;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Colonnes de la liste prospects : la colonne « Catégories » est remplacée par
 * « Téléphone » (celui du contact principal, à défaut celui du prospect), et
 * tous les emails affichés sont des liens `mailto:`.
 */
class ProspectListColumnsTest extends WebTestCase
{
    /** @var list<int> */
    private array $prospectsCrees = [];

    public function testLaColonneCategorieEstRemplaceeParLeTelephone(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $avecContact = $this->creerProspect('ZZCOL contact', '+33111111111', 'prospect@example.test', [
            ['Marie Exemple', '+33633333333', 'marie@example.test'],
        ]);
        $sansContact = $this->creerProspect('ZZCOL sans contact', '+33222222222', 'siege@example.test');
        $contactSansTel = $this->creerProspect('ZZCOL contact sans tel', '+33444444444', null, [
            ['Sans Tel', null, 'sanstel@example.test'],
        ]);
        $this->em()->clear();

        $client->request('GET', '/user/prospects?q='.urlencode('ZZCOL'));
        $this->assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        $this->assertStringContainsString('<th width="150px">Téléphone</th>', $html);
        $this->assertStringNotContainsString('Catégories</th>', $html, 'La colonne « Catégories » est retirée de la liste.');

        // Téléphone du contact principal ; email du prospect et du contact en mailto.
        $this->assertLigne($html, (int) $avecContact->getId(), [
            'mailto:prospect@example.test',
            'mailto:marie@example.test',
            'tel:+33633333333',
        ]);

        // Sans contact : repli sur le téléphone et l'email du prospect.
        $this->assertLigne($html, (int) $sansContact->getId(), [
            'mailto:siege@example.test',
            'tel:+33222222222',
        ]);

        // Contact sans téléphone : repli sur le téléphone du prospect.
        $this->assertLigne($html, (int) $contactSansTel->getId(), [
            'mailto:sanstel@example.test',
            'tel:+33444444444',
        ]);
    }

    protected function tearDown(): void
    {
        $em = $this->em();
        $em->clear();

        foreach ($this->prospectsCrees as $id) {
            $prospect = $em->find(Prospect::class, $id);
            if (null !== $prospect) {
                $em->remove($prospect);
            }
        }
        $em->flush();
        $this->prospectsCrees = [];

        parent::tearDown();
    }

    // --- Helpers ---

    /**
     * Vérifie que tous les liens attendus apparaissent dans la ligne du prospect.
     *
     * @param list<string> $attendus
     */
    private function assertLigne(string $html, int $prospectId, array $attendus): void
    {
        $debut = strpos($html, '<tr id="prospect-'.$prospectId.'"');
        $this->assertNotFalse($debut, 'Ligne attendue pour le prospect '.$prospectId.'.');

        $ligne = substr($html, (int) $debut, 1500);
        foreach ($attendus as $lien) {
            $this->assertStringContainsString($lien, $ligne, 'Lien « '.$lien.' » attendu.');
        }
    }

    /**
     * @param list<array{0: string, 1: ?string, 2: ?string}> $contacts
     */
    private function creerProspect(string $nom, string $telephone, ?string $email, array $contacts = []): Prospect
    {
        $prospect = new Prospect();
        $prospect->setNom($nom.' '.uniqid());
        $prospect->setTelephone($telephone);
        $prospect->setEmail($email);
        $this->em()->persist($prospect);
        $this->em()->flush();
        $this->prospectsCrees[] = (int) $prospect->getId();

        foreach ($contacts as [$nomComplet, $tel, $mail]) {
            $contact = new Contact();
            $contact->setProspect($prospect);
            $contact->setNomComplet($nomComplet);
            $contact->setEstPrincipal(true);
            $contact->setEmail($mail);
            if (null !== $tel) {
                $contact->setTelephone($tel);
            }
            $this->em()->persist($contact);
        }
        $this->em()->flush();

        return $prospect;
    }

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

    private function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        if (!$em instanceof EntityManagerInterface) {
            throw new \LogicException('Entity manager indisponible.');
        }

        return $em;
    }
}
