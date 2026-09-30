<?php

namespace App\Repository;

use App\Entity\ProspectCible;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProspectCible>
 */
class ProspectCibleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProspectCible::class);
    }

    public function findByProspectAndCible(int $prospectId, int $cibleId): ?ProspectCible
    {
        return $this->createQueryBuilder('pc')
            ->where('pc.prospect = :prospectId')
            ->andWhere('pc.cible = :cibleId')
            ->setParameter('prospectId', $prospectId)
            ->setParameter('cibleId', $cibleId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(ProspectCible $prospectCible, bool $flush = true): void
    {
        $this->getEntityManager()->persist($prospectCible);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
