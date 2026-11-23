<?php

namespace App\Tests;

use App\Entity\Produit;
use App\Entity\Prospect;
use App\Entity\ProspectDocument;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Documents Dolibarr mémorisés : association à un produit depuis la fiche
 * prospect, et purge des documents d'un prospect délié de Dolibarr.
 */
class ProspectDocumentTest extends WebTestCase
{
    public function testAssociationProduitSurDevis(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $em = $this->em();
        [$prospect, $produit, $doc] = $this->creerJeu();

        $token = $this->csrf($client, 'doc-produit-'.$doc->getId());

        $client->request('POST', '/user/prospects/documents/'.$doc->getId().'/produit', [
            '_csrf_token' => $token,
            'produit' => (string) $produit->getId(),
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $doc = $em->find(ProspectDocument::class, $doc->getId());
        $this->assertInstanceOf(ProspectDocument::class, $doc);
        $this->assertNotNull($doc->getProduit());
        $this->assertSame($produit->getId(), $doc->getProduit()?->getId());

        // Déliaison : valeur vide → produit retiré.
        $token = $this->csrf($client, 'doc-produit-'.$doc->getId());
        $client->request('POST', '/user/prospects/documents/'.$doc->getId().'/produit', [
            '_csrf_token' => $token,
            'produit' => '',
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseIsSuccessful();

        $em->clear();
        $doc = $em->find(ProspectDocument::class, $doc->getId());
        $this->assertNotNull($doc);
        $this->assertNull($doc->getProduit());

        $this->nettoyer($doc, $produit);
    }

    public function testAssociationFactureRefusee(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $em = $this->em();
        [$prospect, $produit, $doc] = $this->creerJeu(ProspectDocument::TYPE_FACTURE);

        $token = $this->csrf($client, 'doc-produit-'.$doc->getId());
        $client->request('POST', '/user/prospects/documents/'.$doc->getId().'/produit', [
            '_csrf_token' => $token,
            'produit' => (string) $produit->getId(),
        ], [], [
            'HTTP_ORIGIN' => 'http://localhost',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);
        $this->assertResponseStatusCodeSame(422);

        $em->clear();
        $doc = $em->find(ProspectDocument::class, $doc->getId());
        $this->assertInstanceOf(ProspectDocument::class, $doc);
        $this->assertNull($doc->getProduit());

        $this->nettoyer($doc, $produit);
    }

    public function testSyncPurgeLesDocumentsDunProspectDelie(): void
    {
        $client = $this->loginAsUser();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $em = $this->em();
        // Prospect sans lien Dolibarr : synchroniser ne doit jamais appeler
        // l'API et doit purger les documents mémorisés.
        [$prospect, $produit, $doc] = $this->creerJeu();

        $sync = static::getContainer()->get(\App\Service\DolibarrDocumentsSync::class);
        $this->assertInstanceOf(\App\Service\DolibarrDocumentsSync::class, $sync);
        $this->assertNull($sync->synchroniser($prospect));

        $em->clear();
        $this->assertNull($em->find(ProspectDocument::class, $doc->getId()));

        $this->nettoyer(null, $produit, $prospect);
    }

    // --- Helpers ---

    /**
     * Prospect sans lien Dolibarr + produit + document mémorisé. Retourne
     * [prospect, produit, document].
     *
     * @return array{Prospect, Produit, ProspectDocument}
     */
    private function creerJeu(string $type = ProspectDocument::TYPE_DEVIS): array
    {
        $em = $this->em();

        $suffixe = uniqid();
        $prospect = new Prospect();
        $prospect->setNom('ZZTEST docs dolibarr '.$suffixe);
        $prospect->setCleEntreprise('saisie:zztest-docs-'.$suffixe);
        $em->persist($prospect);

        $produit = new Produit();
        $produit->setTitre('ZZTEST produit docs '.$suffixe);
        $em->persist($produit);

        $doc = new ProspectDocument();
        $doc->setProspect($prospect);
        $doc->setType($type);
        // Unique par (type, id Dolibarr) : suffixé pour ne pas entrer en
        // collision entre tests ni avec une exécution précédente interrompue.
        $doc->setDolibarrId('ZZTEST-'.$type.'-'.$suffixe);
        $doc->setRef('ZZ-'.strtoupper($type).'-1');
        $doc->setStatutLabel('Validée');
        $em->persist($doc);

        $em->flush();

        return [$prospect, $produit, $doc];
    }

    private function nettoyer(?ProspectDocument $doc, ?Produit $produit = null, ?Prospect $prospect = null): void
    {
        $em = $this->em();
        // L'EM est réinitialisé après chaque requête HTTP (env test) : les
        // instances passées peuvent être détachées → on retire par id.
        foreach ([$doc, $produit, $prospect] as $entite) {
            if (null === $entite) {
                continue;
            }
            $fraiche = $em->find($entite::class, $entite->getId());
            if (null !== $fraiche) {
                $em->remove($fraiche);
            }
        }
        $em->flush();
    }

    private function csrf(KernelBrowser $client, string $tokenId): string
    {
        // Amorce une session réelle via un GET (cookie de session côté client),
        // puis génère le token DANS cette session : le stockage CSRF est
        // adossé au RequestStack, vide hors handling de requête.
        $client->request('GET', '/user/prospects');
        $this->assertResponseIsSuccessful();

        $container = static::getContainer();
        $cookie = $client->getCookieJar()->get('MOCKSESSID') ?? $client->getCookieJar()->get('PHPSESSID');

        $session = $container->get('session.factory')->createSession();
        if (null !== $cookie) {
            $session->setId($cookie->getValue());
        }

        $request = new \Symfony\Component\HttpFoundation\Request();
        $request->setSession($session);
        $container->get('request_stack')->push($request);

        $token = (string) $container->get('security.csrf.token_manager')->getToken($tokenId)->getValue();
        $session->save();

        return $token;
    }

    private function loginAsUser(): ?KernelBrowser
    {
        try {
            $client = static::createClient();
            $container = static::getContainer();
            $repo = $container->get(UserRepository::class);
            $user = $repo instanceof UserRepository ? $repo->findOneBy([]) : null;
        } catch (\Throwable) {
            return null;
        }
        if (!$user instanceof \App\Entity\User) {
            return null;
        }
        $client->loginUser($user);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
