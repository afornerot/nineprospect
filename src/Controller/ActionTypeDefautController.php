<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\ActionTypeDefaut;
use App\Form\ActionTypeDefautType;
use App\Repository\ActionTypeDefautRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/action-types')]
class ActionTypeDefautController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private ActionTypeDefautRepository $types,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_action_types')]
    public function list(): Response
    {
        return $this->renderLayout('action_types/list.html.twig', "Types d'action", [
            'routesubmit' => 'app_user_action_types_submit',
            'routeupdate' => 'app_user_action_types_update',
            'types' => $this->types->findAllOrdonnes(),
        ]);
    }

    #[Route('/submit', name: 'app_user_action_types_submit')]
    public function submit(Request $request): Response
    {
        $type = new ActionTypeDefaut();
        $type->setOrdre(1 + (int) ($this->types->createQueryBuilder('t')->select('MAX(t.ordre)')->getQuery()->getSingleScalarResult() ?? 0));

        $form = $this->createForm(ActionTypeDefautType::class, $type);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($type);
            $this->em->flush();

            return $this->redirectToRoute('app_user_action_types');
        }

        return $this->renderLayout('action_types/edit.html.twig', "Nouveau type d'action", [
            'routecancel' => 'app_user_action_types',
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_action_types_update')]
    public function update(int $id, Request $request): Response
    {
        $type = $this->types->find($id);
        if (!$type) {
            return $this->redirectToRoute('app_user_action_types');
        }

        $form = $this->createForm(ActionTypeDefautType::class, $type);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('app_user_action_types');
        }

        return $this->renderLayout('action_types/edit.html.twig', 'Modification = '.$type, [
            'routecancel' => 'app_user_action_types',
            'routedelete' => 'app_user_action_types_delete',
            'mode' => 'update',
            'form' => $form,
            'type' => $type,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_action_types_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $type = $this->types->find($id);
        if (!$type) {
            return $this->redirectToRoute('app_user_action_types');
        }

        return $this->deleteEntity($request, $type, $id, 'delete-action-type', 'action_types', $this->em, [
            'list' => 'app_user_action_types',
            'update' => 'app_user_action_types_update',
        ]);
    }
}
