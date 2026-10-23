<?php

namespace App\Repository;

use App\Entity\Departement;
use App\Entity\Prospect;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Departement>
 */
class DepartementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Departement::class);
    }

    /**
     * @return array<int, Departement>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('d')
            ->orderBy('d.numero', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function findOneByNumero(string $numero): ?Departement
    {
        return $this->findOneBy(['numero' => $numero]);
    }

    /**
     * Nombre de prospects par département (avec région).
     * Si un userId est fourni, seuls les prospects affectés à cet utilisateur
     * sont comptés.
     *
     * @return array<int, array{id: int, numero: string|null, nom: string|null, region: string|null, total: int}>
     */
    public function countProspectsGrouped(?int $userId = null): array
    {
        $qb = $this->getEntityManager()->createQueryBuilder()
            ->select('d.id AS id, d.numero AS numero, d.nom AS nom, d.region AS region, COUNT(p.id) AS total')
            ->from(Departement::class, 'd')
            ->leftJoin(Prospect::class, 'p', 'WITH', 'p.departement = d')
            ->groupBy('d.id')
            ->add('orderBy', 'total DESC');

        if (null !== $userId) {
            $qb->innerJoin('p.users', 'cpg_u')
                ->andWhere('cpg_u.id = :cpg_user')
                ->setParameter('cpg_user', $userId);
        }

        $lignes = $qb->getQuery()->getArrayResult();

        $result = [];

        foreach ($lignes as $ligne) {
            $result[] = [
                'id' => (int) $ligne['id'],
                'numero' => isset($ligne['numero']) ? (string) $ligne['numero'] : null,
                'nom' => isset($ligne['nom']) ? (string) $ligne['nom'] : null,
                'region' => isset($ligne['region']) ? (string) $ligne['region'] : null,
                'total' => (int) $ligne['total'],
            ];
        }

        return $result;
    }

    public function countProspectsSansDepartement(): int
    {
        return (int) $this->getEntityManager()
            ->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(Prospect::class, 'p')
            ->where('p.departement IS NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }
}
