<?php

namespace App\Repository;

use App\Entity\Departement;
use App\Entity\Prospect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Departement>
 */
class DepartementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Departement::class);
    }

    /**
     * @return array<int, Departement>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.numero', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNumero(string $numero): ?Departement
    {
        return $this->findOneBy(['numero' => $numero]);
    }

    /**
     * Nombre de prospects par département (avec région).
     *
     * @return array<int, array{id: int, numero: string|null, nom: string|null, region: string|null, total: int}>
     */
    public function countProspectsGrouped(): array
    {
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('d.id AS id, d.numero AS numero, d.nom AS nom, d.region AS region, COUNT(p.id) AS total')
            ->from(Departement::class, 'd')
            ->leftJoin(Prospect::class, 'p', 'WITH', 'p.departement = d')
            ->groupBy('d.id')
            ->add('orderBy', 'total DESC')
            ->getQuery()
            ->getArrayResult();

        $result = [];

        foreach ($lignes as $ligne) {
            $result[] = [
                'id' => (int) $ligne['id'],
                'numero' => isset($ligne['numero']) ? (string) $ligne['numero'] : null,
                'nom' => isset($ligne['nom']) ? (string) $ligne['nom'] : null,
                'region' => isset($ligne['region']) ? (string) $ligne['region'] : null,
                'total' => (int) $ligne['total'],
            ];
        }

        return $result;
    }

    public function countProspectsSansDepartement(): int
    {
        return (int) $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Prospect::class, 'p')
            ->where('p.departement IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
