<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Category;
use App\Form\CategoryType;
use App\Repository\CategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/categories')]
class CategoryController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private CategoryRepository $categories,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_categories')]
    public function list(): Response
    {
        return $this->renderLayout('categories/list.html.twig', 'Catégories', [
            'routesubmit' => 'app_user_categories_submit',
            'routeupdate' => 'app_user_categories_update',
            'categories' => $this->categories->findAllOrdered(),
        ]);
    }

    #[Route('/submit', name: 'app_user_categories_submit')]
    public function submit(Request $request): Response
    {
        $category = new Category();

        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($category);
            $this->em->flush();

            return $this->redirectToRoute('app_user_categories_update', ['id' => $category->getId()]);
        }

        return $this->renderLayout('categories/edit.html.twig', 'Nouvelle catégorie', [
            'routecancel' => 'app_user_categories',
            'form' => $form,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_categories_update')]
    public function update(int $id, Request $request): Response
    {
        $category = $this->categories->find($id);
        if (!$category) {
            return $this->redirectToRoute('app_user_categories');
        }

        $form = $this->createForm(CategoryType::class, $category);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('app_user_categories');
        }

        return $this->renderLayout('categories/edit.html.twig', 'Modification = '.$category->getNom(), [
            'routecancel' => 'app_user_categories',
            'routedelete' => 'app_user_categories_delete',
            'form' => $form,
            'category' => $category,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_categories_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $category = $this->categories->find($id);
        if (!$category) {
            return $this->redirectToRoute('app_user_categories');
        }

        return $this->deleteEntity($request, $category, $id, 'delete-category', 'categories', $this->em, [
            'list' => 'app_user_categories',
            'update' => 'app_user_categories_update',
        ]);
    }
}
