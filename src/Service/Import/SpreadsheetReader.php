<?php

namespace App\Service\Import;

use League\Csv\Reader as CsvReader;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Lit un fichier .xlsx ou .csv et le transforme en tableau de lignes brutes
 * (chaque ligne = tableau associatif en-tête => valeur).
 *
 * Pour le xlsx on utilise PhpSpreadsheet (limite volontairement la 1re feuille).
 * Pour le csv on utilise league/csv qui gère encodage (BOM/Windows-1252) et
 * séparateur (;, ,, \t, |) automatiquement.
 */
final class SpreadsheetReader
{
    /**
     * Lit un fichier à partir d'un UploadedFile et retourne les lignes.
     *
     * @throws \RuntimeException fichier illisible ou format invalide
     *
     * @return array{header: list<string>, rows: list<array<string, string>>, columns: list<string>}
     */
    public function readUploadedFile(UploadedFile $file): array
    {
        $path = $file->getRealPath();
        if (false === $path || !is_readable($path)) {
            throw new \RuntimeException('Fichier uploadé inaccessible.');
        }

        return $this->readFile($path);
    }

    /**
     * @throws \RuntimeException
     *
     * @return array{header: list<string>, rows: list<array<string, string>>, columns: list<string>}
     */
    public function readFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Fichier illisible : %s', $path));
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx', 'xls', 'ods' => $this->readSpreadsheet($path),
            'csv', 'txt' => $this->readCsv($path),
            default => throw new \RuntimeException(sprintf('Format non supporté : .%s (attendu : .xlsx ou .csv).', $extension)),
        };
    }

    /**
     * @return array{header: list<string>, rows: list<array<string, string>>, columns: list<string>}
     */
    private function readSpreadsheet(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de lire le tableur : '.$e->getMessage(), 0, $e);
        }

        $reader->setReadDataOnly(true);

        $spreadsheet = $reader->load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $allRows = $sheet->toArray(null, true, true, false);

        if (empty($allRows)) {
            throw new \RuntimeException('Le tableur est vide.');
        }

        // Première ligne = en-têtes
        $header = array_map(static fn ($v) => trim((string) $v), array_shift($allRows));
        $header = array_values(array_filter($header, static fn ($v) => '' !== $v));

        if ([] === $header) {
            throw new \RuntimeException('La première ligne (en-têtes) est vide.');
        }

        $rows = [];
        foreach ($allRows as $line) {
            // Filtrer les lignes totalement vides
            $cellules = array_map(static fn ($v) => trim((string) $v), array_slice($line, 0, count($header)));
            if ([] === array_filter($cellules, static fn ($v) => '' !== $v)) {
                continue;
            }
            $rows[] = array_combine($header, array_values($cellules));
        }

        /** @var list<string> $headerList */
        $headerList = $header;

        return [
            'header' => $headerList,
            'rows' => $rows,
            'columns' => $headerList,
        ];
    }

    /**
     * @return array{header: list<string>, rows: list<array<string, string>>, columns: list<string>}
     */
    private function readCsv(string $path): array
    {
        try {
            $reader = CsvReader::from($path, 'r');
            $reader->setHeaderOffset(0);
            // Détection du délimiteur (priorité au ;, ,, \t, |)
            $delimiter = $this->detectCsvDelimiter($path);
            if (1 === strlen($delimiter)) {
                $reader->setDelimiter($delimiter);
            }
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de lire le CSV : '.$e->getMessage(), 0, $e);
        }

        $header = $reader->getHeader();
        if ([] === $header) {
            throw new \RuntimeException('La première ligne (en-têtes) est vide.');
        }

        // Normaliser les en-têtes
        $header = array_map(static fn ($v) => trim((string) $v), $header);

        $rows = [];
        foreach ($reader->getRecords() as $record) {
            $ligne = [];
            foreach ($header as $col) {
                $ligne[$col] = trim((string) ($record[$col] ?? ''));
            }
            // Ignorer les lignes totalement vides
            if ([] === array_filter($ligne, static fn ($v) => '' !== $v)) {
                continue;
            }
            $rows[] = $ligne;
        }

        /** @var list<string> $headerList */
        $headerList = array_values($header);

        return [
            'header' => $headerList,
            'rows' => $rows,
            'columns' => $headerList,
        ];
    }

    private function detectCsvDelimiter(string $path): string
    {
        $extrait = '';
        $handle = @fopen($path, 'r');
        if (false === $handle) {
            return ',';
        }
        for ($i = 0; $i < 5; ++$i) {
            $line = fgets($handle);
            if (false === $line) {
                break;
            }
            $extrait .= $line;
        }
        fclose($handle);

        $best = ',';
        $bestScore = -1;
        foreach ([';', ',', "\t", '|'] as $sep) {
            $score = substr_count($extrait, $sep);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $sep;
            }
        }

        return $best;
    }
}
