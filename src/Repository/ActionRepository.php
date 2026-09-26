<?php

namespace App\Repository;

use App\Entity\Action;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Action>
 */
class ActionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Action::class);
    }

    /**
     * Actions planifiées en retard ou échues aujourd'hui, avec prospect + contact.
     *
     * @return array<int, Action>
     */
    public function findDue(?\DateTime $jour = null): array
    {
        $jour ??= new \DateTime('today');

        return $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('a.datePrevue IS NOT NULL')
            ->andWhere('a.datePrevue <= :jour')
            ->setParameter('jour', $jour->format('Y-m-d'))
            ->orderBy('a.datePrevue', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Actions planifiées à venir (ou sans échéance fixée).
     * Sans date prévue, l'action reste à traiter mais sans échéance :
     * elle apparaît dans l'onglet « À venir » pour ne pas être invisible.
     *
     * @return array<int, Action>
     */
    public function findUpcoming(?\DateTime $jour = null, int $limit = 50): array
    {
        $jour ??= new \DateTime('today');

        return $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('(a.datePrevue IS NULL OR a.datePrevue > :jour)')
            ->setParameter('jour', $jour->format('Y-m-d'))
            ->orderBy('a.datePrevue', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Historique des actions déjà réalisées (date de réalisation non null).
     *
     * @return array<int, Action>
     */
    public function findDone(int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->where('a.dateRealisee IS NOT NULL')
            ->orderBy('a.dateRealisee', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countDue(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('a.datePrevue IS NOT NULL')
            ->andWhere('a.datePrevue <= :jour')
            ->setParameter('jour', (new \DateTime('today'))->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUpcoming(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('(a.datePrevue IS NULL OR a.datePrevue > :jour)')
            ->setParameter('jour', (new \DateTime('today'))->format('Y-m-d'))
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countDone(): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.dateRealisee IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Actions visibles sur l'agenda dans une fenêtre de dates.
     * Inclut :
     *  - les planifiées à venir dans la fenêtre (datePrevue ∈ [start, end[)
     *  - les en retard depuis le début (datePrevue < start ET non réalisée)
     *  - les réalisées dans la fenêtre (dateRealisee ∈ [start, end[)
     *
     * @return array<int, Action>
     */
    public function findForAgendaRange(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->leftJoin('a.aFairePar', 'u')->addSelect('u')
            ->where('(a.datePrevue IS NOT NULL AND a.datePrevue < :end AND (a.dateRealisee IS NULL OR a.dateRealisee >= :start))')
            ->orWhere('(a.dateRealisee IS NOT NULL AND a.dateRealisee >= :start AND a.dateRealisee < :end)')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'))
            ->orderBy('a.datePrevue', 'ASC')
            ->addOrderBy('a.dateRealisee', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
