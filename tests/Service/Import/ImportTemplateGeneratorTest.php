<?php

namespace App\Tests\Service\Import;

use App\Service\Import\ImportTemplateGenerator;
use PHPUnit\Framework\TestCase;

class ImportTemplateGeneratorTest extends TestCase
{
    public function testGenerateProduitUnXlsxNonVide(): void
    {
        $generator = new ImportTemplateGenerator();
        $content = $generator->generate();

        $this->assertNotEmpty($content);
        // Un xlsx commence par PK\x03\x04 (signature ZIP)
        $this->assertSame("PK\x03\x04", substr($content, 0, 4));
    }

    public function testGenerateProduitUnXlsxLisible(): void
    {
        $generator = new ImportTemplateGenerator();
        $content = $generator->generate();

        $tmpPath = sys_get_temp_dir().'/'.uniqid('template_').'.xlsx';
        file_put_contents($tmpPath, $content);

        try {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
            $spreadsheet = $reader->load($tmpPath);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);

            // En-tête + 5 exemples
            $this->assertCount(6, $rows);
            $this->assertSame('Organisation', $rows[0][0]);
            $this->assertSame('Nom', $rows[0][1]);
            $this->assertSame('Prénom', $rows[0][2]);
            $this->assertSame('Courriel', $rows[0][3]);

            // 1er exemple
            $this->assertNotEmpty($rows[1][0]);
            $this->assertStringContainsString('@example.com', $rows[1][3]);
        } finally {
            @unlink($tmpPath);
        }
    }

    public function testTemplateContientAUmoins10Colonnes(): void
    {
        $this->assertCount(10, ImportTemplateGenerator::COLUMNS);
        $this->assertContains('Organisation', ImportTemplateGenerator::COLUMNS);
        $this->assertContains('Nom', ImportTemplateGenerator::COLUMNS);
        $this->assertContains('Prénom', ImportTemplateGenerator::COLUMNS);
        $this->assertContains('Courriel', ImportTemplateGenerator::COLUMNS);
        $this->assertContains('Téléphone', ImportTemplateGenerator::COLUMNS);
        $this->assertContains('Code postal', ImportTemplateGenerator::COLUMNS);
    }
}
