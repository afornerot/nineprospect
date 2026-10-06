<?php

namespace App\Repository;

use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * @return array<int, Category>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.nom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return array<int, Category>
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
        $categories = $this->findAll();
        foreach ($categories as $category) {
            if (!$category->getSlug()) {
                $category->setSlug($this->generateSlug($category->getNom() ?: 'category'));
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

        return '' === $slug ? 'category' : $slug;
    }
}
