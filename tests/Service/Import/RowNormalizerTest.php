<?php

namespace App\Tests\Service\Import;

use App\Service\Import\RowNormalizer;
use App\Service\Normalizer\EmailNormalizer;
use App\Service\Normalizer\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class RowNormalizerTest extends TestCase
{
    private RowNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new RowNormalizer(
            new EmailNormalizer(),
            new PhoneNormalizer(),
        );
    }

    public function testMapHeaderReconnaîtLesSynonymesFrançais(): void
    {
        $detected = $this->normalizer->mapHeader([
            'Organisation',
            'NOM',
            'Prénom',
            'Courriel',
            'Fonction',
            'Téléphone',
            'Adresse',
            'Code postal',
            'Ville',
            'Site web',
        ]);

        $this->assertSame(
            ['organisation', 'nom', 'prenom', 'email', 'fonction', 'telephone', 'adresse', 'codePostal', 'ville', 'siteWeb'],
            $detected,
        );
    }

    public function testMissingRequiredDétecteLesColonnesManquantes(): void
    {
        $missing = $this->normalizer->missingRequired(['organisation', 'nom']);
        $this->assertContains('prenom', $missing);
        $this->assertContains('email', $missing);
    }

    public function testNormalizeAvecLigneComplète(): void
    {
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
            'Nom' => 'DUPONT',
            'Prénom' => 'Marie',
            'Courriel' => 'marie.dupont@example.com',
            'Téléphone' => '02 48 00 00 00',
            'Code postal' => '18000',
            'Ville' => 'Bourges',
        ]);

        $this->assertSame(2, $row->rowNumber);
        $this->assertSame('Société X', $row->organisation);
        $this->assertSame('DUPONT', $row->nom);
        $this->assertSame('Marie', $row->prenom);
        $this->assertSame('marie.dupont@example.com', $row->email);
        $this->assertNotNull($row->telephone);
        $this->assertStringStartsWith('+33', $row->telephone);
        $this->assertSame('18000', $row->codePostal);
        $this->assertSame('Bourges', $row->ville);
        $this->assertSame('import:societe x', $row->cleEntreprise);
        $this->assertSame([], $row->errors);
    }

    public function testNormalizeAvecEmailInvalide(): void
    {
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
            'Nom' => 'DUPONT',
            'Prénom' => 'Marie',
            'Courriel' => 'marie.dupont@',
        ]);

        $this->assertArrayHasKey('email', $row->errors);
    }

    public function testNormalizeAvecOrganisationManquante(): void
    {
        $row = $this->normalizer->normalize(2, [
            'Organisation' => '',
            'Nom' => 'DUPONT',
            'Prénom' => 'Marie',
            'Courriel' => 'marie@example.com',
        ]);

        $this->assertArrayHasKey('organisation', $row->errors);
    }

    public function testNormalizeParticulier(): void
    {
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Particulier',
            'Nom' => 'MARTIN',
            'Prénom' => 'Pierre',
            'Courriel' => 'pierre@example.com',
        ]);

        $this->assertStringStartsWith('import:particulier:', $row->cleEntreprise);
    }

    public function testNormalizeAvecCodePostalInvalide(): void
    {
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
            'Nom' => 'DUPONT',
            'Prénom' => 'Marie',
            'Courriel' => 'marie@example.com',
            'Code postal' => '180',
        ]);

        $this->assertArrayHasKey('codePostal', $row->errors);
    }

    public function testNormalizeEntrepriseSeuleSansContactNiEmail(): void
    {
        // Cas 1 : juste l'Organisation → on doit accepter, aucune erreur.
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
        ]);

        $this->assertSame('Société X', $row->organisation);
        $this->assertNull($row->nom);
        $this->assertNull($row->prenom);
        $this->assertNull($row->email);
        $this->assertSame([], $row->errors, 'Aucune erreur attendue (organisation suffit)');
    }

    public function testNormalizeEmailSeulSansNomNiPrenom(): void
    {
        // Cas 3 : Organisation + email, pas de Nom/Prénom → accepté.
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
            'Courriel' => 'contact@x.com',
        ]);

        $this->assertSame('contact@x.com', $row->email);
        $this->assertNull($row->nom);
        $this->assertNull($row->prenom);
        $this->assertSame([], $row->errors);
    }

    public function testNormalizeContactSansEmail(): void
    {
        // Cas 2 : Nom + Prénom présents, pas d'email → accepté, email reste null.
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
            'Nom' => 'DUPONT',
            'Prénom' => 'Marie',
        ]);

        $this->assertSame('DUPONT', $row->nom);
        $this->assertSame('Marie', $row->prenom);
        $this->assertNull($row->email);
        $this->assertSame([], $row->errors);
    }

    public function testNormalizeNomSeulAccepte(): void
    {
        // Nom seul sans Prénom doit être accepté (anomalie non bloquante,
        // l'Executor décidera de ne pas créer de Contact).
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
            'Nom' => 'DUPONT',
        ]);

        $this->assertSame('DUPONT', $row->nom);
        $this->assertNull($row->prenom);
        $this->assertSame([], $row->errors);
    }

    public function testNormalizePrenomSeulAccepte(): void
    {
        $row = $this->normalizer->normalize(2, [
            'Organisation' => 'Société X',
            'Prénom' => 'Marie',
        ]);

        $this->assertSame('Marie', $row->prenom);
        $this->assertNull($row->nom);
        $this->assertSame([], $row->errors);
    }
}
