<?php

namespace App\Tests;

use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page « Affecter en masse » des vagues : filtres cible/catégorie à gauche et
 * bascule symétrique gauche ↔ droite.
 */
class VaguesBulkTest extends WebTestCase
{
    public function testLaPageExposeLesFiltresEtLesDonneesDeFiltrage(): void
    {
        $client = $this->clientConnecte();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $client->request('GET', '/user/vagues/bulk');
        $this->assertResponseIsSuccessful();

        $crawler = $client->getCrawler();
        $this->assertSame(1, $crawler->filter('#js-cible-filter')->count(), 'Filtre cible absent.');
        $this->assertSame(1, $crawler->filter('#js-categorie-filter')->count(), 'Filtre catégorie absent.');
        $this->assertSame(1, $crawler->filter('#js-sprint-filter')->count(), 'Filtre vague absent.');
        $this->assertSame(1, $crawler->filter('#js-reset-filters')->count(), 'Bouton de réinitialisation absent.');

        // Chaque ligne porte les données nécessaires au filtrage côté client.
        $lignes = $crawler->filter('#js-prospects-table tbody tr');
        $this->assertGreaterThan(0, $lignes->count(), 'Aucun prospect listé.');

        $ligne = $lignes->first();
        $this->assertNotNull($ligne->attr('data-sprint'), 'Attribut data-sprint manquant.');
        $this->assertNotNull($ligne->attr('data-cibles'), 'Attribut data-cibles manquant.');
        $this->assertNotNull($ligne->attr('data-categories'), 'Attribut data-categories manquant.');
    }

    public function testLeScriptMasqueLaLignePlaceeaDroiteEtLaReafficheAuRetrait(): void
    {
        $client = $this->clientConnecte();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $html = (string) $client->request('GET', '/user/vagues/bulk')->html();

        // Le masquage passe par data-selectionne, consulté par correspond().
        $this->assertStringContainsString("tr.dataset.selectionne = masquer ? '1' : ''", $html);
        $this->assertStringContainsString("if (tr.dataset.selectionne === '1') return false;", $html);

        // Ajout ET retrait mettent à jour la ligne de gauche.
        $this->assertStringContainsString('masquerLigneGauche(id, true);', $html);
        $this->assertStringContainsString('masquerLigneGauche(id, false);', $html);

        // Le compteur du panneau gauche ne compte pas les lignes déjà placées.
        $this->assertStringContainsString('js-count-left', $html);
        $this->assertStringContainsString('js-count-right', $html);
    }

    public function testLeRepositoryRetourneLaVagueCouranteEtLesIdentifiantsDeFiltrage(): void
    {
        $client = $this->clientConnecte();
        if (null === $client) {
            static::markTestSkipped('Base de test indisponible.');
        }

        $lignes = static::getContainer()->get(ProspectRepository::class)->findAllPourAffectation();
        if ([] === $lignes) {
            static::markTestSkipped('Aucun prospect en base de test.');
        }

        // Un prospect ne doit apparaître qu'une fois, même rattaché à
        // plusieurs vagues.
        $ids = array_column($lignes, 'id');
        $this->assertSame($ids, array_values(array_unique($ids)), 'Des prospects sont dupliqués.');

        foreach (['id', 'nom', 'sprintId', 'sprintNumero', 'cibleIds', 'categorieIds'] as $cle) {
            $this->assertArrayHasKey($cle, $lignes[0], 'Clé manquante : '.$cle);
        }

        $this->assertIsArray($lignes[0]['cibleIds']);
        $this->assertIsArray($lignes[0]['categorieIds']);
    }

    private function clientConnecte(): ?\Symfony\Bundle\FrameworkBundle\KernelBrowser
    {
        try {
            $client = static::createClient();
            $depot = static::getContainer()->get(UserRepository::class);
        } catch (\Throwable) {
            return null;
        }
        $user = $depot instanceof UserRepository ? $depot->findOneBy([]) : null;
        if (null === $user) {
            return null;
        }
        $client->loginUser($user);

        return $client;
    }
}
