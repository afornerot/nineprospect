<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Cible;
use App\Form\CibleType;
use App\Repository\CampagneRepository;
use App\Repository\CibleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/cibles')]
class CibleController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private CibleRepository $cibles,
        private CampagneRepository $campagnes,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_cibles')]
    public function list(): Response
    {
        return $this->renderLayout('cibles/list.html.twig', 'Cibles', [
            'routesubmit' => 'app_user_cibles_submit',
            'routeupdate' => 'app_user_cibles_update',
            'cibles' => $this->cibles->findAllOrdered(),
            'campagnes' => $this->campagnes->findAll(),
        ]);
    }

    #[Route('/submit', name: 'app_user_cibles_submit')]
    public function submit(Request $request): Response
    {
        $cible = new Cible();

        $form = $this->createForm(CibleType::class, $cible);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($cible);
            $this->em->flush();

            return $this->redirectToRoute('app_user_cibles_update', ['id' => $cible->getId()]);
        }

        return $this->renderLayout('cibles/edit.html.twig', 'Nouvelle cible', [
            'routecancel' => 'app_user_cibles',
            'form' => $form,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_cibles_update')]
    public function update(int $id, Request $request): Response
    {
        $cible = $this->cibles->find($id);
        if (!$cible) {
            return $this->redirectToRoute('app_user_cibles');
        }

        $form = $this->createForm(CibleType::class, $cible);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('app_user_cibles');
        }

        return $this->renderLayout('cibles/edit.html.twig', 'Modification = '.$cible->getTitre(), [
            'routecancel' => 'app_user_cibles',
            'routedelete' => 'app_user_cibles_delete',
            'form' => $form,
            'cible' => $cible,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_cibles_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $cible = $this->cibles->find($id);
        if (!$cible) {
            return $this->redirectToRoute('app_user_cibles');
        }

        return $this->deleteEntity($request, $cible, $id, 'delete-cible', 'cibles', $this->em, [
            'list' => 'app_user_cibles',
            'update' => 'app_user_cibles_update',
        ]);
    }
}
