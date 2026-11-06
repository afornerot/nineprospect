<?php

namespace App\Repository;

use App\Entity\Produit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SortDirection;

/**
 * @extends ServiceEntityRepository<Produit>
 */
class ProduitRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Produit::class);
    }

    /**
     * @return array<int, Produit>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.titre', SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function generateMissingSlugs(EntityManagerInterface $em): int
    {
        $count = 0;
        $produits = $this->findAll();
        foreach ($produits as $produit) {
            if (!$produit->getSlug()) {
                $slug = $this->generateSlug($produit->getTitre() ?: 'produit');
                $produit->setSlug($slug);
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
        return $slug ?: 'produit';
    }
}
