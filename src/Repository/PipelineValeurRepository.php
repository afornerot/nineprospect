<?php

namespace App\Repository;

use App\Entity\PipelineValeur;
use App\Enum\PipelineStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class PipelineValeurRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PipelineValeur::class);
    }

    /**
     * Nombre d'étapes réalisées (statut Oui → Réalisé) sur la période, dans
     * les vagues associées au produit donné (ou tous).
     *
     * @param string $etapeNom nom de l'étape visée, ex. 'RDV'
     */
    public function countEtapesRealiseesParPeriode(string $etapeNom, ?\DateTimeInterface $debut, ?\DateTimeInterface $fin, ?int $produitId, ?int $auteurId): int
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->innerJoin('v.etape', 'e')
            ->innerJoin('v.prospectSprint', 'ps')
            ->innerJoin('ps.sprint', 's')
            ->andWhere('LOWER(e.nom) = :etape')
            ->setParameter('etape', mb_strtolower($etapeNom))
            ->andWhere('v.statut = :realise')
            ->setParameter('realise', PipelineStatut::REALISE);

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

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
