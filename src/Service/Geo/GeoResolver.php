<?php

namespace App\Service\Geo;

use App\Entity\Departement;
use App\Repository\DepartementRepository;

/**
 * Résolution code postal -> département / région à partir du référentiel.
 * Les valeurs non numériques sont conservées comme ville.
 */
final class GeoResolver
{
    /**
     * @var array<string, Departement|null>
     */
    private array $cache = [];

    public function __construct(
        private DepartementRepository $departements,
    ) {
    }

    /**
     * @return array{codePostal: string|null, ville: string|null, departement: Departement|null, anomalie: string|null}
     */
    public function resolve(?string $valeur): array
    {
        $resultat = ['codePostal' => null, 'ville' => null, 'departement' => null, 'anomalie' => null];
        $valeur = trim((string) $valeur);

        if ('' === $valeur) {
            return $resultat;
        }

        if (!preg_match('/^\d{5}$/', $valeur)) {
            if (preg_match('/^\d+$/', $valeur)) {
                $resultat['anomalie'] = 'code postal invalide ('.$valeur.')';
            } else {
                $resultat['ville'] = $valeur;
            }

            return $resultat;
        }

        $resultat['codePostal'] = $valeur;
        $cle = str_starts_with($valeur, '97') || str_starts_with($valeur, '98') ? substr($valeur, 0, 3) : substr($valeur, 0, 2);
        $departement = $this->trouver($cle);

        if (null === $departement) {
            $resultat['anomalie'] = 'département non trouvé pour le code postal '.$valeur;

            return $resultat;
        }

        $resultat['departement'] = $departement;

        return $resultat;
    }

    private function trouver(string $numero): ?Departement
    {
        if (!array_key_exists($numero, $this->cache)) {
            $this->cache[$numero] = $this->departements->findOneByNumero($numero);
        }

        return $this->cache[$numero];
    }
}
