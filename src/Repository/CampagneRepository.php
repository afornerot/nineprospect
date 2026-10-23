<?php

namespace App\Repository;

use App\Entity\Campagne;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Campagne>
 */
class CampagneRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Campagne::class);
    }

    /**
     * @return array<int, Campagne>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.nom', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function findOneByExternalId(string $externalId): ?Campagne
    {
        return $this->findOneBy(['externalId' => $externalId]);
    }

    public function generateMissingSlugs(EntityManagerInterface $em): int
    {
        $count = 0;
        $campagnes = $this->findAll();
        foreach ($campagnes as $campagne) {
            if (!$campagne->getSlug()) {
                $slug = $this->generateSlug($campagne->getNom() ?: 'campagne');
                $campagne->setSlug($slug);
                $count++;
            }
        }
        $em->flush();

        return $count;
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug ?: 'campagne';
    }
}
