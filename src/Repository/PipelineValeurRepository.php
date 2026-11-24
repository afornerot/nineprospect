<?php

namespace App\Repository;

use App\Entity\PipelineValeur;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PipelineValeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PipelineValeur::class);
    }

    /**
     * Nombre d'étapes nommées sur la période, dans les vagues associées au
     * produit donné (ou tous). `$statut` : statut ou liste de statuts
     * PipelineStatut ; null = tous statuts confondus.
     *
     * @param string $etapeNom nom de l'étape visée, ex. 'RDV'
     * @param int|list<int>|null $statut filtre de statut, null = tous
     */
    public function countEtapesParPeriode(string $etapeNom, ?\DateTimeInterface $debut, ?\DateTimeInterface $fin, ?int $produitId, ?int $auteurId, int|array|null $statut = null): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->innerJoin('v.etape', 'e')
            ->innerJoin('v.prospectSprint', 'ps')
            ->innerJoin('ps.sprint', 's')
            ->andWhere('LOWER(e.nom) = :etape')
            ->setParameter('etape', mb_strtolower($etapeNom));

        if (null !== $debut) {
            $qb->andWhere('v.date >= :debut')->setParameter('debut', $debut);
        }
        if (null !== $fin) {
            // Jour de fin inclus ; immuable pour ne pas modifier l'entité source.
            $finExclue = \DateTimeImmutable::createFromInterface($fin)->modify('+1 day');
            $qb->andWhere('v.date < :finExclue')->setParameter('finExclue', $finExclue);
        }
        if (null !== $produitId) {
            $qb->andWhere('s.produit = :produit')->setParameter('produit', $produitId);
        }
        if (null !== $auteurId) {
            $qb->andWhere('v.modifiePar = :auteur')->setParameter('auteur', $auteurId);
        }

        if (null !== $statut) {
            $qb->andWhere('v.statut IN (:statut)')
                ->setParameter('statut', (array) $statut);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
