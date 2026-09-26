<?php

namespace App\Repository;

use App\Entity\ActionTypeDefaut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ActionTypeDefaut>
 */
class ActionTypeDefautRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ActionTypeDefaut::class);
    }

    /**
     * Types actifs triés par ordre ascendant puis libellé.
     *
     * @return list<ActionTypeDefaut>
     */
    public function findActifsOrdonnes(): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('t.ordre', 'ASC')
            ->addOrderBy('t.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Premier type actif (utilisé comme helper d'init du champ libre
     * {@see Action::$typeAction}).
     */
    public function premierActif(): ?ActionTypeDefaut
    {
        return $this->createQueryBuilder('t')
            ->where('t.actif = :actif')
            ->setParameter('actif', true)
            ->orderBy('t.ordre', 'ASC')
            ->addOrderBy('t.libelle', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Liste complète (actifs + inactifs) triée pour l'écran CRUD.
     *
     * @return list<ActionTypeDefaut>
     */
    public function findAllOrdonnes(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.ordre', 'ASC')
            ->addOrderBy('t.libelle', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
