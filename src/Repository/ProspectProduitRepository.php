<?php

namespace App\Repository;

use App\Entity\ProspectProduit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<ProspectProduit>
 */
class ProspectProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProspectProduit::class);
    }

    public function findByProspectAndProduit(int $prospectId, int $produitId): ?ProspectProduit
    {
        return $this->createQueryBuilder('pc')
            ->where('pc.prospect = :prospectId')
            ->andWhere('pc.produit = :produitId')
            ->setParameter('prospectId', $prospectId)
            ->setParameter('produitId', $produitId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(ProspectProduit $prospectProduit, bool $flush = true): void
    {
        $this->getEntityManager()->persist($prospectProduit);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Prospects qualifiés (« Oui » ou « Hors produit ») par mois et par produit.
     *
     * Une entrée par produit portant au moins une décision datée ; un prospect
     * qualifié sur plusieurs produits apparaît dans chacune. Les qualifications
     * non datées (antérieures à l'audit) et l'état « Non » sont exclus.
     *
     * @return array{
     *     mois: list<string>,
     *     series: list<array{label: string, data: list<int>}>
     * } « mois » = clés « YYYY-MM » triées, « series[i].data » aligné sur « mois »
     */
    public function countQualificationsParMoisParProduit(?int $userId = null): array
    {
        $qb = $this->createQueryBuilder('pc')
            ->select('c.titre AS titre', 'pc.qualifieModifieAt AS decision')
            ->innerJoin('pc.produit', 'c')
            ->andWhere('pc.qualifieModifieAt IS NOT NULL')
            ->andWhere('pc.qualifie IN (:tranches)')
            ->setParameter('tranches', [true, false])
            ->orderBy('c.titre', SortDirection::Ascending);

        if (null !== $userId) {
            $qb->andWhere('pc.qualifieModifiePar = :auteur')
                ->setParameter('auteur', $userId);
        }

        /** @var array<string, array<string, int>> $parProduit */
        $parProduit = [];
        /** @var array<string, true> $mois */
        $mois = [];

        foreach ($qb->getQuery()->getArrayResult() as $ligne) {
            $decision = $ligne['decision'];
            if (!$decision instanceof \DateTimeInterface) {
                $decision = new \DateTime((string) $decision);
            }

            $cle = $decision->format('Y-m');
            $titre = (string) $ligne['titre'];
            $mois[$cle] = true;
            $parProduit[$titre][$cle] = ($parProduit[$titre][$cle] ?? 0) + 1;
        }

        $cles = array_keys($mois);
        sort($cles);

        $series = [];
        foreach ($parProduit as $titre => $compteurs) {
            $donnees = [];
            foreach ($cles as $cle) {
                $donnees[] = $compteurs[$cle] ?? 0;
            }
            $series[] = ['label' => $titre, 'data' => $donnees];
        }

        return ['mois' => $cles, 'series' => $series];
    }
}
