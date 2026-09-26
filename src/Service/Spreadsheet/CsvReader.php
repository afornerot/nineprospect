<?php

namespace App\Service\Spreadsheet;

/**
 * Lecteur CSV tolérant : délimiteur et encodage déduits du fichier.
 * Gère les champs multi-lignes (guillemets) et le BOM UTF-8.
 */
final class CsvReader
{
    /**
     * @throws \RuntimeException si le fichier est illisible
     *
     * @return list<list<string>> lignes brutes (colonnes manquantes = chaîne vide)
     */
    public function read(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Fichier illisible : %s', $path));
        }

        $contenu = file_get_contents($path);
        if (false === $contenu) {
            throw new \RuntimeException(sprintf('Impossible de lire : %s', $path));
        }

        $contenu = $this->convertirEncodage($contenu);
        $separateur = $this->detecterSeparateur($contenu);

        $stream = fopen('php://temp', 'r+');
        if (false === $stream) {
            throw new \RuntimeException('Ouverture du flux temporaire impossible.');
        }

        fwrite($stream, $contenu);
        rewind($stream);

        $lignes = [];
        while (($ligne = fgetcsv($stream, 0, $separateur, '"', '')) !== false) {
            /* @var list<string|null> $ligne */
            $lignes[] = array_map(static fn (?string $cellule): string => (string) $cellule, $ligne);
        }

        fclose($stream);

        return $lignes;
    }

    private function convertirEncodage(string $contenu): string
    {
        if (str_starts_with($contenu, "\xEF\xBB\xBF")) {
            $contenu = substr($contenu, 3);
        }

        if (mb_check_encoding($contenu, 'UTF-8')) {
            return $contenu;
        }

        return @mb_convert_encoding($contenu, 'UTF-8', 'Windows-1252');
    }

    private function detecterSeparateur(string $contenu): string
    {
        $extrait = substr($contenu, 0, 5000);
        $meilleur = ',';
        $meilleurScore = -1;

        foreach ([';', ',', "\t", '|'] as $separateur) {
            $score = substr_count($extrait, $separateur);
            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleur = $separateur;
            }
        }

        return $meilleur;
    }
}
