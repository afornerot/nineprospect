<?php

namespace App\Repository;

use App\Entity\Cible;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Cible>
 */
class CibleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Cible::class);
    }

    /**
     * @return array<int, Cible>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.titre', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function generateMissingSlugs(EntityManagerInterface $em): int
    {
        $count = 0;
        $cibles = $this->findAll();
        foreach ($cibles as $cible) {
            if (!$cible->getSlug()) {
                $slug = $this->generateSlug($cible->getTitre() ?: 'cible');
                $cible->setSlug($slug);
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
        return $slug ?: 'cible';
    }
}
