<?php

namespace App\Repository;

use App\Entity\Prospect;
use App\Entity\ProspectSprint;
use App\Enum\PipelineStatut;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Prospect>
 */
class ProspectRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Prospect::class);
    }

    /**
     * Filtres de la liste : campagne, vague, qualification,
     * affectation et recherche plein texte.
     *
     * @param array{
     *     campagne?: int|null,
     *     sprint?: int|null,
     *     contacte?: string|null,
     *     cible?: int|null,
     *     qualifie?: string|null,
     *     userId?: int|null,
     *     recherche?: string|null
     * } $filtres
     *
     * @return array<int, Prospect>
     */
    public function findByFilters(array $filtres = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->leftJoin('p.campagne', 'c')
            ->leftJoin('p.sprints', 'ps')
            ->leftJoin('ps.sprint', 's')
            ->leftJoin('s.pipeline', 'pl')
            ->leftJoin('ps.valeurs', 'v')
            ->leftJoin('v.etape', 've')
            ->leftJoin('p.contacts', 'ct')
            ->leftJoin('p.users', 'u')
            ->leftJoin('p.actions', 'a')
            ->leftJoin('p.prospectCibles', 'pc')
            ->leftJoin('pc.cible', 'ci')
            ->addSelect('c', 'ps', 's', 'pl', 'v', 've', 'ct', 'u', 'a', 'pc', 'ci')
            ->orderBy('p.nom', 'ASC');

        if (isset($filtres['campagne'])) {
            $qb->andWhere('c.id = :campagne')->setParameter('campagne', $filtres['campagne']);
        }

        if (isset($filtres['sprint'])) {
            $qb->andWhere('s.id = :sprint')->setParameter('sprint', $filtres['sprint']);
        }

        if (isset($filtres['userId'])) {
            $qb->andWhere('u.id = :user')->setParameter('user', $filtres['userId']);
        }

        if (isset($filtres['contacte'])) {
            if ('oui' === $filtres['contacte']) {
                $qb->andWhere('p.contacte = :contacte')->setParameter('contacte', true);
            } else {
                // « non » couvre aussi les prospects jamais contactés (NULL) :
                // c'est l'état initial avant le premier clic sur le switch.
                $qb->andWhere('p.contacte IS NULL OR p.contacte = :contacte')
                    ->setParameter('contacte', false);
            }
        }

        if (isset($filtres['cible'])) {
            $qb->andWhere('ci.id = :cible')->setParameter('cible', $filtres['cible']);
        }

        if (isset($filtres['qualifie'])) {
            if ('oui' === $filtres['qualifie']) {
                $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\ProspectCible pc2 WHERE pc2.prospect = p AND pc2.qualifie = true)');
            } elseif ('horscible' === $filtres['qualifie']) {
                $qb->andWhere('EXISTS (SELECT 1 FROM App\Entity\ProspectCible pc2 WHERE pc2.prospect = p AND pc2.qualifie = false)');
            } else {
                $qb->andWhere('NOT EXISTS (SELECT 1 FROM App\Entity\ProspectCible pc2 WHERE pc2.prospect = p AND pc2.qualifie = true)');
            }
        }

        $recherche = trim((string) ($filtres['recherche'] ?? ''));
        if ('' !== $recherche) {
            $qb->andWhere('p.nom LIKE :recherche OR p.ville LIKE :recherche OR ct.email LIKE :recherche OR ct.nomComplet LIKE :recherche')
                ->setParameter('recherche', '%'.$recherche.'%');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Comptage par étape du pipeline d'un pipeline donné, sur les liens
     * prospect↔vague (un prospect présent dans plusieurs vagues compte dans
     * chacune d'elles). Les liens sans valeur sont comptés « non démarré ».
     *
     * @return array<int, array<int, int>> [etapeId][statut] => nombre de liens
     */
    public function countPipeline(int $pipelineId, ?int $sprintId = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('e.id AS etape, v.statut AS valeur, COUNT(ps.id) AS total')
            ->from(ProspectSprint::class, 'ps')
            ->innerJoin('ps.sprint', 's')
            ->innerJoin('s.pipeline', 'p')
            ->innerJoin('p.etapes', 'e')
            ->leftJoin('ps.valeurs', 'v', 'WITH', 'v.etape = e')
            ->where('p.id = :pipeline')
            ->groupBy('e.id')
            ->addGroupBy('v.statut')
            ->setParameter('pipeline', $pipelineId);

        if (null !== $sprintId) {
            $qb->andWhere('s.id = :sprint')->setParameter('sprint', $sprintId);
        }

        $result = [];
        foreach ($qb->getQuery()->getArrayResult() as $ligne) {
            $statut = null === $ligne['valeur'] ? PipelineStatut::NON_DEMARRE : (int) $ligne['valeur'];
            $result[(int) $ligne['etape']][$statut] = (int) $ligne['total'];
        }

        return $result;
    }

    public function countByCampagne(int $campagneId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.campagne = :campagne')
            ->setParameter('campagne', $campagneId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Prospects rattachés à une campagne, triés par nom.
     *
     * @return array<int, Prospect>
     */
    public function findByCampagne(int $campagneId): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.campagne = :campagne')
            ->setParameter('campagne', $campagneId)
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countBySprint(int $sprintId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(DISTINCT p.id)')
            ->innerJoin('p.sprints', 'ps')
            ->where('ps.sprint = :sprint')
            ->setParameter('sprint', $sprintId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countHorsCampagne(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.campagne IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findPrevNextInSprint(int $prospectId, int $sprintId): ?array
    {
        $all = $this->createQueryBuilder('p')
            ->select('p.id')
            ->innerJoin('p.sprints', 'ps')
            ->where('ps.sprint = :sprint')
            ->setParameter('sprint', $sprintId)
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getArrayResult();

        $ids = array_column($all, 'id');
        $current = array_search($prospectId, $ids, false);
        if (false === $current) {
            return null;
        }

        $prev = $current > 0 ? $ids[$current - 1] : null;
        $next = $current < count($ids) - 1 ? $ids[$current + 1] : null;

        return [$prev, $next];
    }

    public function findAllWithSprint(): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id, p.nom, s.id AS sprintId, s.numero AS sprintNumero')
            ->leftJoin('p.sprints', 'ps')
            ->leftJoin('ps.sprint', 's')
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function findAllSprintsWithProspects(): array
    {
        return $this->getEntityManager()->createQueryBuilder()
            ->select('s.id, s.numero, s.libelle')
            ->from(\App\Entity\Sprint::class, 's')
            ->innerJoin('s.liens', 'ps')
            ->groupBy('s.id')
            ->orderBy('s.numero', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    public function findWithoutSprint(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.id NOT IN (
                SELECT IDENTITY(ps.prospect) FROM App\Entity\ProspectSprint ps
            )')
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findWithoutCampagne(): array
    {
        return $this->createQueryBuilder('p')
            ->where('p.campagne IS NULL')
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllWithCurrentSprint(): array
    {
        return $this->createQueryBuilder('p')
            ->select('p.id, p.nom, s.id AS sprintId, s.numero AS sprintNumero')
            ->leftJoin('p.sprints', 'ps')
            ->leftJoin('ps.sprint', 's')
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Prospects non contactés (contacte = false ou NULL). Le switch « contacte »
     * bascule entre true et false, le NULL initial compte comme non contacté.
     */
    public function countNonContacte(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.contacte IS NULL OR p.contacte = :false')
            ->setParameter('false', false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Prospects non qualifiés (qualifie = NULL = « Non » au sens du badge).
     */
    public function countNonQualifie(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('NOT EXISTS (SELECT 1 FROM App\Entity\ProspectCible pc WHERE pc.prospect = p AND pc.qualifie = true)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countContacte(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.contacte = :true')
            ->setParameter('true', true)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countQualifies(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('EXISTS (SELECT 1 FROM App\Entity\ProspectCible pc WHERE pc.prospect = p AND pc.qualifie = true)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countByDepartement(): array
    {
        return $this->createQueryBuilder('p')
            ->select('d.numero, d.nom, COUNT(p.id) as total')
            ->innerJoin('p.departement', 'd')
            ->groupBy('d.id')
            ->orderBy('total', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getArrayResult();
    }

    public function save(Prospect $prospect, bool $flush = true): void
    {
        $this->getEntityManager()->persist($prospect);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Prospects géolocalisés (latitude ET longitude non NULL), avec leur
     * campagne et leur département chargés (utilisés par la vue carte).
     *
     * @return array<int, Prospect>
     */
    public function findGeolocalises(): array
    {
        return $this->createQueryBuilder('p')
            ->leftJoin('p.campagne', 'c')
            ->leftJoin('p.departement', 'd')
            ->addSelect('c', 'd')
            ->where('p.latitude IS NOT NULL')
            ->andWhere('p.longitude IS NOT NULL')
            ->orderBy('p.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function remove(Prospect $prospect, bool $flush = true): void
    {
        $this->getEntityManager()->remove($prospect);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
