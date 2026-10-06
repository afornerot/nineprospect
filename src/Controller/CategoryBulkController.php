<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/categories/bulk')]
class CategoryBulkController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private CategoryRepository $categories,
        private ProspectRepository $prospects,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Affiche la liste des prospects sans aucune catégorie, à gauche, et
     * un sélecteur de catégorie à droite. Le user ajoute des prospects à
     * la catégorie, puis valide pour les rattacher tous en lot.
     */
    #[Route('', name: 'app_user_categories_bulk')]
    public function bulk(Request $request): Response
    {
        $categories = $this->categories->findAllOrdered();
        $categorieId = $request->query->getInt('categorie', 0);

        // Si une catégorie est sélectionnée, on affiche les prospects
        // qui n'en font PAS partie. Sinon, les prospects sans aucune
        // catégorie (= qui n'ont pas encore été catégorisés).
        if ($categorieId > 0 && $this->categories->find($categorieId) instanceof Category) {
            $prospectsHors = $this->prospects->findHorsCategorie($categorieId);
        } else {
            $prospectsHors = $this->prospects->findSansCategorie();
        }

        return $this->renderLayout('categories/bulk.html.twig', 'Affecter en masse', [
            'categories' => $categories,
            'categorieId' => $categorieId,
            'prospectsHors' => $prospectsHors,
            'routecancel' => 'app_user_categories',
        ]);
    }

    /**
     * POST /user/categories/bulk/submit : ajoute la liste de prospects
     * à la catégorie sélectionnée, puis redirige vers la liste des
     * catégories.
     */
    #[Route('/submit', name: 'app_user_categories_bulk_submit', methods: ['POST'])]
    public function bulkSubmit(Request $request): Response
    {
        $categorieId = $request->request->getInt('categorie_id');
        $prospectIds = (array) $request->request->all('prospect_ids');

        if ($categorieId <= 0) {
            $this->addFlash('error', 'Sélectionnez une catégorie.');

            return $this->redirectToRoute('app_user_categories_bulk');
        }

        $categorie = $this->categories->find($categorieId);
        if (!$categorie instanceof Category) {
            $this->addFlash('error', 'Catégorie introuvable.');

            return $this->redirectToRoute('app_user_categories_bulk');
        }

        $count = 0;
        foreach ($prospectIds as $prospectId) {
            $prospect = $this->prospects->find((int) $prospectId);
            if (!$prospect) {
                continue;
            }
            if (!$prospect->getCategories()->contains($categorie)) {
                $prospect->addCategory($categorie);
                ++$count;
            }
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('%d prospect(s) affecté(s) à la catégorie "%s".', $count, $categorie->getNom()));

        return $this->redirectToRoute('app_user_categories_bulk', ['categorie' => $categorieId]);
    }
}
