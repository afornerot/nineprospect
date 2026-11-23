<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Objective;
use App\Entity\Produit;
use App\Form\ObjectiveType;
use App\Http\ContexteRetour;
use App\Repository\ObjectiveRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ObjectiveController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private ObjectiveRepository $objectiveRepository,
        private EntityManagerInterface $em,
        protected ContexteRetour $contexteRetour,
    ) {
    }

    #[Route('/admin/objective', name: 'app_admin_objective')]
    public function list(): Response
    {
        $objectives = $this->objectiveRepository->createQueryBuilder('o')
            ->leftJoin('o.produit', 'p')->addSelect('p')
            ->orderBy('o.startDate', 'ASC')
            ->addOrderBy('o.endDate', 'ASC')
            ->addOrderBy('p.titre', 'ASC')
            ->getQuery()
            ->getResult();

        // Regroupement par tranche de dates + produit : une ligne par
        // tranche, une colonne par type d'objectif (voir list.html.twig).
        $rows = [];
        foreach ($objectives as $objective) {
            $cle = $objective->getStartDate()->format('Y-m-d').'|'
                .$objective->getEndDate()->format('Y-m-d').'|'
                .($objective->getProduit()?->getId() ?? 0);
            $rows[$cle] ??= ['objective' => $objective, 'cells' => []];
            $rows[$cle]['cells'][$objective->getType()] = $objective;
        }

        return $this->renderLayout('objective/list.html.twig', 'Liste des Objectifs', [
            'routesubmit' => 'app_admin_objective_submit',
            'routeupdate' => 'app_admin_objective_update',
            'rows' => array_values($rows),
        ]);
    }

    #[Route('/admin/objective/submit', name: 'app_admin_objective_submit')]
    public function submit(Request $request): Response
    {
        $objective = new Objective();

        // Pré-remplissage depuis la liste (clic sur une cellule vide de la
        // tranche) : ?start=...&end=...&produit=...&type=...
        if ($request->isMethod('GET')) {
            $start = \DateTime::createFromFormat('Y-m-d', (string) $request->query->get('start'));
            $end = \DateTime::createFromFormat('Y-m-d', (string) $request->query->get('end'));
            if ($start) {
                $objective->setStartDate($start);
            }
            if ($end) {
                $objective->setEndDate($end);
            }
            $produitId = $request->query->get('produit');
            if ($produitId && ctype_digit((string) $produitId)) {
                $objective->setProduit($this->em->find(Produit::class, (int) $produitId));
            }
            $type = (string) $request->query->get('type');
            if (isset(Objective::TYPES[$type])) {
                $objective->setType($type);
            }
        }

        $form = $this->createForm(ObjectiveType::class, $objective, ['mode' => 'submit']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($objective);
            $this->em->flush();

            return $this->contexteRetour->redirectionVersListe($request, 'app_admin_objective');
        }

        $retour = $this->contexteRetour->listeDuModule($request, 'app_admin_objective');

        return $this->renderLayout('objective/edit.html.twig', 'Création Objectif', [
            'retour' => $retour,
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/admin/objective/update/{id}', name: 'app_admin_objective_update')]
    public function update(int $id, Request $request): Response
    {
        $objective = $this->objectiveRepository->find($id);
        if (!$objective) {
            return $this->contexteRetour->redirectionVersListe($request, 'app_admin_objective');
        }

        $form = $this->createForm(ObjectiveType::class, $objective, ['mode' => 'update']);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->contexteRetour->redirectionVersListe($request, 'app_admin_objective');
        }

        $retour = $this->contexteRetour->listeDuModule($request, 'app_admin_objective');

        return $this->renderLayout('objective/edit.html.twig', 'Modification Objectif', [
            'retour' => $retour,
            'routedelete' => 'app_admin_objective_delete',
            'mode' => 'update',
            'form' => $form,
        ]);
    }

    #[Route('/admin/objective/delete/{id}', name: 'app_admin_objective_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $objective = $this->objectiveRepository->find($id);
        if (!$objective) {
            return $this->contexteRetour->redirectionVersListe($request, 'app_admin_objective');
        }

        return $this->deleteEntity($request, $objective, $id, 'delete-objective', 'objective', $this->em, [
            'list' => 'app_admin_objective',
            'update' => 'app_admin_objective_update',
        ]);
    }
}
