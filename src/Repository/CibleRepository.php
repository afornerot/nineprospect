<?php

namespace App\Repository;

use App\Entity\Cible;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

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
            ->orderBy('c.nom', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, Cible>
     */
    public function findAllActives(): array
    {
        return $this->findAllOrdered();
    }

    /**
     * Backfill des slugs manquants (idempotent).
     */
    public function generateMissingSlugs(EntityManagerInterface $em): int
    {
        $count = 0;
        $cibles = $this->findAll();
        foreach ($cibles as $cible) {
            if (!$cible->getSlug()) {
                $cible->setSlug($this->generateSlug($cible->getNom() ?: 'cible'));
                ++$count;
            }
        }
        $em->flush();

        return $count;
    }

    private function generateSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return '' === $slug ? 'cible' : $slug;
    }
}
