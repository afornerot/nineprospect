<?php

namespace App\Service\Normalizer;

/**
 * Lecture des dates hétérogènes de la feuille source :
 * ISO, "29 July 2025 05:19", "29 juillet 2025", "29/7/2025", "23-03-2026 20:18",
 * sérial Excel (45958.34) et "21/08" (jour/mois sans année).
 */
final class DateParser
{
    private const MOIS = [
        'january' => 1, 'janvier' => 1,
        'february' => 2, 'fevrier' => 2, 'février' => 2,
        'march' => 3, 'mars' => 3,
        'april' => 4, 'avril' => 4,
        'may' => 5, 'mai' => 5,
        'june' => 6, 'juin' => 6,
        'july' => 7, 'juillet' => 7,
        'august' => 8, 'aout' => 8, 'août' => 8,
        'september' => 9, 'septembre' => 9,
        'october' => 10, 'octobre' => 10,
        'november' => 11, 'novembre' => 11,
        'december' => 12, 'decembre' => 12, 'décembre' => 12,
    ];

    /**
     * Date complète (date + heure éventuelle). Retourne null si illisible.
     */
    public function parse(?string $valeur): ?\DateTime
    {
        $v = trim((string) $valeur);
        if ('' === $v) {
            return null;
        }

        $serie = $this->depuisSerie($v);
        if (null !== $serie) {
            return $serie;
        }

        // Suppression du décalage horaire ISO et normalisation des séparateurs
        $v = preg_replace('/\s*[+-]\d{2}:\d{2}$/', '', $v) ?? $v;
        $v = str_replace('T', ' ', $v);
        $v = preg_replace('/\s+/', ' ', trim($v)) ?? $v;

        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'd-m-Y H:i:s', 'd-m-Y H:i', 'Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $v);
            if (false === $date) {
                continue;
            }

            $erreurs = \DateTime::getLastErrors();
            if (false === $erreurs || (0 === $erreurs['warning_count'] && 0 === $erreurs['error_count'])) {
                return $date;
            }
        }

        return $this->depuisMoisTextuel($v);
    }

    /**
     * Date raccourcie "21/08" ou "21/08/2025". L'année manquante est fournie
     * par l'appelant (année du lead sinon année courante).
     */
    public function parseCourt(?string $valeur, ?int $anneeParDefaut = null): ?\DateTime
    {
        $v = trim((string) $valeur);
        if ('' === $v) {
            return null;
        }

        if (preg_match('#^(\d{1,2})[/-](\d{1,2})(?:[/-](\d{2,4}))?$#', $v, $m)) {
            $jour = (int) $m[1];
            $mois = (int) $m[2];
            $annee = isset($m[3]) ? (int) $m[3] : ($anneeParDefaut ?? (int) (new \DateTime())->format('Y'));

            if (2 === strlen($m[3] ?? '')) {
                $annee += $annee < 70 ? 2000 : 1900;
            }

            if ($mois >= 1 && $mois <= 12 && $jour >= 1 && $jour <= 31) {
                $date = \DateTime::createFromFormat('!Y-m-d', sprintf('%04d-%02d-%02d', $annee, $mois, $jour));
                if (false !== $date) {
                    return $date;
                }
            }
        }

        // Deux dates collées dans la même cellule ("02/09/2025 17/09") : on garde la première
        if (preg_match('#\d{1,2}[/-]\d{1,2}([/-]\d{2,4})?#', $v, $m)) {
            return $this->parseCourt($m[0], $anneeParDefaut);
        }

        return $this->parse($v);
    }

    private function depuisSerie(string $valeur): ?\DateTime
    {
        if (!preg_match('/^\d+(\.\d+)?$/', $valeur)) {
            return null;
        }

        $serie = (float) $valeur;
        if ($serie < 20000 || $serie > 80000) {
            return null;
        }

        $date = new \DateTime('1899-12-30 00:00:00');
        $date->modify(sprintf('+%d days', (int) $serie));

        $heure = fmod($serie, 1);
        if ($heure > 0.0001) {
            $secondes = (int) round($heure * 86400);
            $date->modify(sprintf('+%d seconds', $secondes));
        }

        return $date;
    }

    private function depuisMoisTextuel(string $valeur): ?\DateTime
    {
        // "29 July 2025 05:19" ou "29 juillet 2025 05:19"
        if (!preg_match('#^(\d{1,2})\s+([A-Za-zÀ-ÿ]+)\s+(\d{4})(?:\s+(\d{1,2}):(\d{2})(?::(\d{2}))?)?$#u', $valeur, $m)) {
            return null;
        }

        $mois = self::MOIS[mb_strtolower($m[2])] ?? null;
        if (null === $mois) {
            return null;
        }

        $date = \DateTime::createFromFormat('!Y-m-d H:i:s', sprintf(
            '%04d-%02d-%02d %02d:%02d:%02d',
            (int) $m[3],
            $mois,
            (int) $m[1],
            (int) ($m[4] ?? 0),
            (int) ($m[5] ?? 0),
            (int) ($m[6] ?? 0),
        ));

        return false === $date ? null : $date;
    }
}
