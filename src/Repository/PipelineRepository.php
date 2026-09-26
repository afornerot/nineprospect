<?php

namespace App\Repository;

use App\Entity\Pipeline;
use App\Entity\PipelineValeur;
use App\Entity\Sprint;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pipeline>
 */
class PipelineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pipeline::class);
    }

    /**
     * @return array<int, Pipeline>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.parDefaut', 'DESC')
            ->addOrderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function getDefault(): ?Pipeline
    {
        return $this->findOneBy(['parDefaut' => true]);
    }

    public function countSprints(int $pipelineId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Sprint::class, 's')
            ->where('s.pipeline = :pipeline')
            ->setParameter('pipeline', $pipelineId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de valeurs enregistrées sur une étape (bloque sa suppression).
     *
     * @param array<int> $etapeIds
     */
    public function countValeurs(array $etapeIds): int
    {
        if ([] === $etapeIds) {
            return 0;
        }

        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(PipelineValeur::class, 'v')
            ->where('v.etape IN (:ids)')
            ->setParameter('ids', $etapeIds)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
