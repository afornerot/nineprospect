<?php

namespace App\Repository;

use App\Entity\ProspectCible;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

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

    /**
     * Prospects qualifiés (« Oui » ou « Hors cible ») par mois et par cible.
     *
     * Une entrée par cible portant au moins une décision datée ; un prospect
     * qualifié sur plusieurs cibles apparaît dans chacune. Les qualifications
     * non datées (antérieures à l'audit) et l'état « Non » sont exclus.
     *
     * @return array{
     *     mois: list<string>,
     *     series: list<array{label: string, data: list<int>}>
     * } « mois » = clés « YYYY-MM » triées, « series[i].data » aligné sur « mois »
     */
    public function countQualificationsParMoisParCible(?int $userId = null): array
    {
        $qb = $this->createQueryBuilder('pc')
            ->select('c.titre AS titre', 'pc.qualifieModifieAt AS decision')
            ->innerJoin('pc.cible', 'c')
            ->andWhere('pc.qualifieModifieAt IS NOT NULL')
            ->andWhere('pc.qualifie IN (:tranches)')
            ->setParameter('tranches', [true, false])
            ->orderBy('c.titre', SortDirection::Ascending);

        if (null !== $userId) {
            $qb->andWhere('pc.qualifieModifiePar = :auteur')
                ->setParameter('auteur', $userId);
        }

        /** @var array<string, array<string, int>> $parCible */
        $parCible = [];
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
            $parCible[$titre][$cle] = ($parCible[$titre][$cle] ?? 0) + 1;
        }

        $cles = array_keys($mois);
        sort($cles);

        $series = [];
        foreach ($parCible as $titre => $compteurs) {
            $donnees = [];
            foreach ($cles as $cle) {
                $donnees[] = $compteurs[$cle] ?? 0;
            }
            $series[] = ['label' => $titre, 'data' => $donnees];
        }

        return ['mois' => $cles, 'series' => $series];
    }
}
