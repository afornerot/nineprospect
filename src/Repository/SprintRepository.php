<?php

namespace App\Repository;

use App\Entity\Sprint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sprint>
 */
class SprintRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sprint::class);
    }

    /**
     * @return array<int, Sprint>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.numero', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByNumero(int $numero): ?Sprint
    {
        return $this->findOneBy(['numero' => $numero]);
    }

    /**
     * Prochain numéro de vague disponible (MAX(numero) + 1, 1 si vide).
     */
    public function nextNumero(): int
    {
        $max = (int) $this->createQueryBuilder('s')
            ->select('MAX(s.numero)')
            ->getQuery()
            ->getSingleScalarResult();

        return $max + 1;
    }
}
