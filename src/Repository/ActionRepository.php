<?php

namespace App\Repository;

use App\Entity\Action;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

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
     * Actions planifiées en retard (échéance strictement antérieure à aujourd'hui),
     * avec prospect + contact. Les actions dues aujourd'hui relèvent de
     * {@see findToday()}.
     *
     * @return array<int, Action>
     */
    public function findDue(?\DateTime $jour = null, ?User $user = null): array
    {
        $jour ??= new \DateTime('today');

        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('a.datePrevue IS NOT NULL')
            ->andWhere('a.datePrevue < :jour')
            ->setParameter('jour', $jour->format('Y-m-d'))
            ->orderBy('a.datePrevue', SortDirection::Ascending);

        $this->appliquerFiltreUtilisateur($qb, $user);

        return $qb->getQuery()->getResult();
    }

    /**
     * Actions planifiées à faire aujourd'hui (datePrevue = aujourd'hui).
     *
     * @return array<int, Action>
     */
    public function findToday(?\DateTime $jour = null, ?User $user = null): array
    {
        $jour ??= new \DateTime('today');

        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('a.datePrevue = :jour')
            ->setParameter('jour', $jour->format('Y-m-d'))
            ->orderBy('a.datePrevue', SortDirection::Ascending);

        $this->appliquerFiltreUtilisateur($qb, $user);

        return $qb->getQuery()->getResult();
    }

    /**
     * Actions planifiées à venir (ou sans échéance fixée).
     * Sans date prévue, l'action reste à traiter mais sans échéance :
     * elle apparaît dans l'onglet « À venir » pour ne pas être invisible.
     *
     * @return array<int, Action>
     */
    public function findUpcoming(?\DateTime $jour = null, int $limit = 50, ?User $user = null): array
    {
        $jour ??= new \DateTime('today');

        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('(a.datePrevue IS NULL OR a.datePrevue > :jour)')
            ->setParameter('jour', $jour->format('Y-m-d'))
            ->orderBy('a.datePrevue', SortDirection::Ascending)
            ->setMaxResults($limit);

        $this->appliquerFiltreUtilisateur($qb, $user);

        return $qb->getQuery()->getResult();
    }

    /**
     * Historique des actions déjà réalisées (date de réalisation non null).
     *
     * @return array<int, Action>
     */
    public function findDone(int $limit = 100, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->where('a.dateRealisee IS NOT NULL')
            ->orderBy('a.dateRealisee', SortDirection::Descending)
            ->setMaxResults($limit);

        $this->appliquerFiltreUtilisateur($qb, $user);

        return $qb->getQuery()->getResult();
    }

    public function countDue(?User $user = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('a.datePrevue IS NOT NULL')
            ->andWhere('a.datePrevue < :jour')
            ->setParameter('jour', (new \DateTime('today'))->format('Y-m-d'));

        $this->appliquerFiltreUtilisateur($qb, $user);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countToday(?User $user = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('a.datePrevue = :jour')
            ->setParameter('jour', (new \DateTime('today'))->format('Y-m-d'));

        $this->appliquerFiltreUtilisateur($qb, $user);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countUpcoming(?User $user = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.dateRealisee IS NULL')
            ->andWhere('(a.datePrevue IS NULL OR a.datePrevue > :jour)')
            ->setParameter('jour', (new \DateTime('today'))->format('Y-m-d'));

        $this->appliquerFiltreUtilisateur($qb, $user);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countDone(?User $user = null): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.dateRealisee IS NOT NULL');

        $this->appliquerFiltreUtilisateur($qb, $user);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Actions visibles sur l'agenda dans une fenêtre de dates.
     * Inclut :
     *  - les planifiées à venir dans la fenêtre (datePrevue ∈ [start, end[)
     *  - les en retard depuis le début (datePrevue < start ET non réalisée)
     *  - les réalisées dans la fenêtre (dateRealisee ∈ [start, end[)
     *
     * Si un utilisateur est fourni, seules les actions qui lui sont affectées
     * (`aFairePar`) sont renvoyées : c'est l'agenda personnel.
     *
     * @return array<int, Action>
     */
    public function findForAgendaRange(\DateTimeInterface $start, \DateTimeInterface $end, ?User $user = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->leftJoin('a.prospect', 'p')->addSelect('p')
            ->leftJoin('a.contact', 'c')->addSelect('c')
            ->leftJoin('a.aFairePar', 'u')->addSelect('u')
            ->where('(a.datePrevue IS NOT NULL AND a.datePrevue < :end AND (a.dateRealisee IS NULL OR a.dateRealisee >= :start))')
            ->orWhere('(a.dateRealisee IS NOT NULL AND a.dateRealisee >= :start AND a.dateRealisee < :end)')
            ->setParameter('start', $start->format('Y-m-d'))
            ->setParameter('end', $end->format('Y-m-d'));

        if (null !== $user) {
            $qb->andWhere('a.aFairePar = :user')
                ->setParameter('user', $user);
        }

        return $qb
            ->orderBy('a.datePrevue', SortDirection::Ascending)
            ->addOrderBy('a.dateRealisee', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    // --- Helpers ---

    /**
     * Ajoute au query builder le filtre par utilisateur : action affectée
     * directement (`aFairePar`) ou via l'affection du prospect (`prospect.users`).
     */
    private function appliquerFiltreUtilisateur(mixed $qb, ?User $user): void
    {
        if (null === $user) {
            return;
        }

        $qb->andWhere('(a.aFairePar = :userFiltre OR EXISTS (SELECT 1 FROM App\Entity\Prospect p2 INNER JOIN p2.users u2 WHERE p2 = a.prospect AND u2.id = :userFiltre))')
            ->setParameter('userFiltre', $user);
    }
}