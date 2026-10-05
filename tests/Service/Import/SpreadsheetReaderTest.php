<?php

namespace App\Tests\Service\Import;

use App\Service\Import\SpreadsheetReader;
use PHPUnit\Framework\TestCase;

class SpreadsheetReaderTest extends TestCase
{
    private SpreadsheetReader $reader;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->reader = new SpreadsheetReader();
        $this->tmpDir = sys_get_temp_dir().'/spreadsheet-reader-test-'.uniqid();
        @mkdir($this->tmpDir, 0775, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmpDir);
    }

    public function testReadCsvAvecSéparateurPointVirgule(): void
    {
        $path = $this->tmpDir.'/test.csv';
        file_put_contents($path, "Organisation;Nom;Prénom;Courriel\nSociété X;DUPONT;Marie;marie@example.com\n");

        $result = $this->reader->readFile($path);

        $this->assertSame(['Organisation', 'Nom', 'Prénom', 'Courriel'], $result['header']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('Société X', $result['rows'][0]['Organisation']);
        $this->assertSame('marie@example.com', $result['rows'][0]['Courriel']);
    }

    public function testReadCsvAvecSéparateurVirguleEtBOM(): void
    {
        $path = $this->tmpDir.'/test.csv';
        file_put_contents($path, "\xEF\xBB\xBFOganisation,Nom,Prenom,Email\nSociété X,Yés,Xé,x@y.com\n");

        $result = $this->reader->readFile($path);

        $this->assertCount(1, $result['rows']);
        $this->assertSame('Société X', $result['rows'][0]['Oganisation']);
    }

    public function testReadCsvIgnoreLesLignesVides(): void
    {
        $path = $this->tmpDir.'/test.csv';
        file_put_contents($path, "Organisation;Nom\nA;B\n\nC;D\n\n");

        $result = $this->reader->readFile($path);

        $this->assertCount(2, $result['rows']);
    }

    public function testReadXlsx(): void
    {
        $path = $this->tmpDir.'/test.xlsx';
        $this->createXlsxFile($path, [
            ['Organisation', 'Nom', 'Prénom', 'Courriel'],
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com'],
            ['Société X', 'MARTIN', 'Pierre', 'pierre@example.com'],
        ]);

        $result = $this->reader->readFile($path);

        $this->assertSame(['Organisation', 'Nom', 'Prénom', 'Courriel'], $result['header']);
        $this->assertCount(2, $result['rows']);
    }

    public function testReadFormatInconnuLanceException(): void
    {
        $path = $this->tmpDir.'/test.foo';
        file_put_contents($path, 'foo');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Format non supporté');
        $this->reader->readFile($path);
    }

    private function createXlsxFile(string $path, array $rows): void
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray($rows);
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($path);
    }
}
