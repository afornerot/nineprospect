<?php

namespace App\Tests\Service\Import;

use App\Entity\Prospect;
use App\Repository\ContactRepository;
use App\Repository\ProspectRepository;
use App\Service\Import\ImportAnalyzer;
use App\Service\Import\ImportRowStatus;
use App\Service\Import\RowNormalizer;
use App\Service\Import\SpreadsheetReader;
use App\Service\Normalizer\EmailNormalizer;
use App\Service\Normalizer\PhoneNormalizer;
use PHPUnit\Framework\TestCase;

class ImportAnalyzerTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/import-analyzer-test-'.uniqid();
        @mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testAnalyzeAvecCsvValide(): void
    {
        $path = $this->writeCsv([
            ['Organisation', 'Nom', 'Prénom', 'Courriel'],
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com'],
            ['Société X', 'MARTIN', 'Pierre', 'pierre@example.com'],
        ]);

        $analyzer = $this->createAnalyzer(prospectsByCle: []);
        $preview = $analyzer->analyze([
            'filename' => 'test.csv',
            'filePath' => $path,
            'mode' => 'new',
            'ciblePrincipaleTitle' => 'Test',
        ]);

        $this->assertNull($preview->fatalError);
        $this->assertSame([], $preview->columnsMissing);
        $this->assertCount(1, $preview->groups, 'Les 2 lignes doivent être regroupées en 1 seul groupe');
        $this->assertSame(2, $preview->totalRows());
        $this->assertSame(2, $preview->okRows());
    }

    public function testAnalyzeRejetteLeFichierSiColonneObligatoireManquante(): void
    {
        $path = $this->writeCsv([
            ['Organisation', 'Nom'],
            ['Société X', 'DUPONT'],
        ]);

        $analyzer = $this->createAnalyzer();
        $preview = $analyzer->analyze([
            'filename' => 'test.csv',
            'filePath' => $path,
            'mode' => 'new',
            'ciblePrincipaleTitle' => 'Test',
        ]);

        $this->assertNotNull($preview->fatalError);
        $this->assertTrue($preview->isFatal());
        $this->assertContains('prenom', $preview->columnsMissing);
        $this->assertContains('email', $preview->columnsMissing);
    }

    public function testAnalyzeDétecteDoublonIntraFichier(): void
    {
        $path = $this->writeCsv([
            ['Organisation', 'Nom', 'Prénom', 'Courriel'],
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com'],
            ['Société X', 'DUPONT-MARTIN', 'Marie', 'marie@example.com'],
        ]);

        $analyzer = $this->createAnalyzer();
        $preview = $analyzer->analyze([
            'filename' => 'test.csv',
            'filePath' => $path,
            'mode' => 'new',
            'ciblePrincipaleTitle' => 'Test',
        ]);

        $this->assertSame(2, $preview->totalRows());
        $this->assertSame(1, $preview->duplicateRows());
    }

    public function testAnalyzeDétecteDoublonProspectEnBase(): void
    {
        $path = $this->writeCsv([
            ['Organisation', 'Nom', 'Prénom', 'Courriel'],
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com'],
        ]);

        $existing = $this->createProspect('Société X', 'import:societe x');

        $analyzer = $this->createAnalyzer(prospectsByCle: ['import:societe x' => $existing]);
        $preview = $analyzer->analyze([
            'filename' => 'test.csv',
            'filePath' => $path,
            'mode' => 'new',
            'ciblePrincipaleTitle' => 'Test',
        ]);

        $this->assertSame(1, $preview->duplicateRows());
        $this->assertSame(ImportRowStatus::DUPLICATE_PROSPECT_DB, $preview->allRows()[0]->status);
    }

    public function testAnalyzeDétecteDoublonContactEnBase(): void
    {
        $path = $this->writeCsv([
            ['Organisation', 'Nom', 'Prénom', 'Courriel'],
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com'],
        ]);

        $existingContact = new \App\Entity\Contact();
        $existingContact->setEmail('marie@example.com');

        $analyzer = $this->createAnalyzer(
            prospectsByCle: [],
            contactsByEmail: ['marie@example.com' => $existingContact],
        );
        $preview = $analyzer->analyze([
            'filename' => 'test.csv',
            'filePath' => $path,
            'mode' => 'new',
            'ciblePrincipaleTitle' => 'Test',
        ]);

        $this->assertSame(1, $preview->duplicateRows());
        $this->assertSame(ImportRowStatus::DUPLICATE_CONTACT_DB, $preview->allRows()[0]->status);
    }

    public function testAnalyzeRegroupeParOrganisation(): void
    {
        $path = $this->writeCsv([
            ['Organisation', 'Nom', 'Prénom', 'Courriel'],
            ['Société X', 'A', 'X', 'a@x.com'],
            ['Société X', 'B', 'X', 'b@x.com'],
            ['Société Y', 'C', 'Y', 'c@y.com'],
        ]);

        $analyzer = $this->createAnalyzer();
        $preview = $analyzer->analyze([
            'filename' => 'test.csv',
            'filePath' => $path,
            'mode' => 'new',
            'ciblePrincipaleTitle' => 'Test',
        ]);

        $this->assertSame(2, count($preview->groups));
        $groupSizes = array_map(fn ($g) => $g->count(), $preview->groups);
        sort($groupSizes);
        $this->assertSame([1, 2], $groupSizes);
    }

    public function testAnalyzeAvecFichierInexistant(): void
    {
        $analyzer = $this->createAnalyzer();
        $preview = $analyzer->analyze([
            'filename' => 'no.csv',
            'filePath' => '/tmp/nonexistent-'.uniqid().'.csv',
            'mode' => 'new',
            'ciblePrincipaleTitle' => 'Test',
        ]);

        $this->assertNotNull($preview->fatalError);
        $this->assertTrue($preview->isFatal());
    }

    private function writeCsv(array $rows): string
    {
        $path = $this->tmpDir.'/'.uniqid('test_').'.csv';
        $fp = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fp, $row, ';');
        }
        fclose($fp);

        return $path;
    }

    private function createAnalyzer(array $prospectsByCle = [], array $contactsByEmail = []): ImportAnalyzer
    {
        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn($prospectsByCle);

        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn($contactsByEmail);

        return new ImportAnalyzer(
            new SpreadsheetReader(),
            new RowNormalizer(new EmailNormalizer(), new PhoneNormalizer()),
            $prospectsRepo,
            $contactRepo,
        );
    }

    private function createProspect(string $nom, string $cle): Prospect
    {
        $p = new Prospect();
        $p->setNom($nom);
        $p->setCleEntreprise($cle);

        return $p;
    }
}
