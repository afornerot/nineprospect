<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Groupe;
use App\Form\GroupeType;
use App\Http\ContexteRetour;
use App\Repository\GroupeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GroupeController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private GroupeRepository $groupeRepository,
        private EntityManagerInterface $em,
        protected ContexteRetour $contexteRetour,
    ) {
    }

    #[Route('/admin/groupe', name: 'app_admin_groupe')]
    public function list(): Response
    {
        $groupes = $this->groupeRepository->findAll();

        return $this->renderLayout('groupe/list.html.twig', 'Liste des Groupes', [
            'routesubmit' => 'app_admin_groupe_submit',
            'routeupdate' => 'app_admin_groupe_update',
            'groupes' => $groupes,
        ]);
    }

    #[Route('/admin/groupe/submit', name: 'app_admin_groupe_submit')]
    public function submit(Request $request): Response
    {
        $groupe = new Groupe();

        $form = $this->createForm(GroupeType::class, $groupe, ['mode' => 'submit']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($groupe);
            $this->em->flush();

            return $this->contexteRetour->redirection($request, 'app_admin_groupe');
        }

        $retour = $this->contexteRetour->url($request, 'app_admin_groupe');

        return $this->renderLayout('groupe/edit.html.twig', 'Création Groupe', [
            'retour' => $retour,
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/admin/groupe/update/{id}', name: 'app_admin_groupe_update')]
    public function update(int $id, Request $request): Response
    {
        $groupe = $this->groupeRepository->find($id);
        if (!$groupe) {
            return $this->contexteRetour->redirection($request, 'app_admin_groupe');
        }

        $form = $this->createForm(GroupeType::class, $groupe, ['mode' => 'update']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->contexteRetour->redirection($request, 'app_admin_groupe');
        }

        $retour = $this->contexteRetour->url($request, 'app_admin_groupe');

        return $this->renderLayout('groupe/edit.html.twig', 'Modification Groupe = '.$groupe->getName(), [
            'retour' => $retour,
            'routedelete' => 'app_admin_groupe_delete',
            'mode' => 'update',
            'form' => $form,
        ]);
    }

    #[Route('/admin/groupe/delete/{id}', name: 'app_admin_groupe_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $groupe = $this->groupeRepository->find($id);
        if (!$groupe) {
            return $this->contexteRetour->redirection($request, 'app_admin_groupe');
        }

        return $this->deleteEntity($request, $groupe, $id, 'delete-groupe', 'groupe', $this->em, [
            'list' => 'app_admin_groupe',
            'update' => 'app_admin_groupe_update',
            'conflictMessage' => 'Suppression impossible : ce groupe est vraisemblablement encore rattaché à des utilisateurs.',
        ]);
    }
}
