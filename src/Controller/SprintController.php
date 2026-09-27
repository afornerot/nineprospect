<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Prospect;
use App\Entity\ProspectSprint;
use App\Entity\Sprint;
use App\Form\SprintType;
use App\Repository\PipelineRepository;
use App\Repository\ProspectRepository;
use App\Repository\SprintRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/vagues')]
class SprintController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private SprintRepository $sprints,
        private ProspectRepository $prospects,
        private PipelineRepository $pipelines,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_vagues')]
    public function list(): Response
    {
        $lignes = [];
        foreach ($this->sprints->findAllOrdered() as $sprint) {
            $lignes[] = [
                'sprint' => $sprint,
                'prospects' => $this->prospects->countBySprint((int) $sprint->getId()),
            ];
        }

        return $this->renderLayout('vagues/list.html.twig', 'Vagues de traitement', [
            'routesubmit' => 'app_user_vagues_submit',
            'routeupdate' => 'app_user_vagues_update',
            'routeprospect' => 'app_user_prospects',
            'lignes' => $lignes,
        ]);
    }

    #[Route('/bulk', name: 'app_user_vagues_bulk')]
    public function bulk(): Response
    {
        $sprints = $this->sprints->findAllOrdered();
        $lastSprint = !empty($sprints) ? $sprints[0] : null;
        $prospectsAll = $this->prospects->findAllWithCurrentSprint();

        return $this->renderLayout('vagues/bulk.html.twig', 'Affecter en masse', [
            'sprints' => $sprints,
            'lastSprint' => $lastSprint,
            'prospectsAll' => $prospectsAll,
        ]);
    }

    #[Route('/bulk/submit', name: 'app_user_vagues_bulk_submit', methods: ['POST'])]
    public function bulkSubmit(Request $request): Response
    {
        $sprintId = $request->request->getInt('sprint_id');
        $prospectIds = $request->request->all('prospect_ids');

        if (0 === $sprintId) {
            $this->addFlash('error', 'Sélectionnez une vague.');

            return $this->redirectToRoute('app_user_vagues_bulk');
        }

        $sprint = $this->sprints->find($sprintId);
        if (!$sprint) {
            $this->addFlash('error', 'Vague introuvable.');

            return $this->redirectToRoute('app_user_vagues_bulk');
        }

        $count = 0;
        foreach ($prospectIds as $prospectId) {
            $prospect = $this->prospects->find((int) $prospectId);
            if (!$prospect) {
                continue;
            }
            $link = new ProspectSprint();
            $link->setProspect($prospect);
            $link->setSprint($sprint);
            $this->em->persist($link);
            $count++;
        }

        $this->em->flush();
        $this->addFlash('success', $count . ' prospect(s) affecté(s) à la vague ' . $sprint->getNumero() . '.');

        return $this->redirectToRoute('app_user_vagues');
    }

    #[Route('/submit', name: 'app_user_vagues_submit')]
    public function submit(Request $request): Response
    {
        $sprint = new Sprint();
        $sprint->setPipeline($this->pipelines->getDefault());
        // Numéro de vague calculé et non modifiable à la création.
        $sprint->setNumero($this->sprints->nextNumero());

        $form = $this->createForm(SprintType::class, $sprint, ['numeroLectureSeule' => true]);
        $form->handleRequest($request);
        // Toujours recalculer : le champ est `readonly` côté navigateur mais
        // rien n'empêche un client malveillant de poster une autre valeur.
        $sprint->setNumero($this->sprints->nextNumero());
        if ($form->isSubmitted() && $form->isValid() && $this->sauverSprint($sprint, $form)) {
            // Idem pipeline : la création ouvre la page de modification, où se
            // fait le rattachement des prospects.
            return $this->redirectToRoute('app_user_vagues_update', ['id' => $sprint->getId()]);
        }

        return $this->renderLayout('vagues/edit.html.twig', 'Nouvelle vague', [
            'routecancel' => 'app_user_vagues',
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_vagues_update')]
    public function update(int $id, Request $request): Response
    {
        $sprint = $this->sprints->find($id);
        if (!$sprint) {
            return $this->redirectToRoute('app_user_vagues');
        }

        $form = $this->createForm(SprintType::class, $sprint, ['afficherProspects' => true]);
        $form->get('prospects')->setData($this->prospectsDe($sprint));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->sauverSprint($sprint, $form)) {
            return $this->redirectToRoute('app_user_vagues');
        }

        $allProspects = $this->prospects->findBy([], ['nom' => 'ASC']);

        return $this->renderLayout('vagues/edit.html.twig', 'Modification = Vague '.$sprint->getNumero(), [
            'routecancel' => 'app_user_vagues',
            'routedelete' => 'app_user_vagues_delete',
            'mode' => 'update',
            'form' => $form,
            'sprint' => $sprint,
            'allProspects' => $allProspects,
        ]);
    }

    /**
     * Enregistrement avec contrôle d'unicité du numéro : une contrainte levée
     * au flush (ou détectée avant) devient une erreur de formulaire (422) au
     * lieu d'une exception 500.
     *
     * @param FormInterface<Sprint> $form
     */
    private function sauverSprint(Sprint $sprint, FormInterface $form): bool
    {
        $existant = $this->sprints->findOneByNumero((int) $sprint->getNumero());
        if (null !== $existant && $existant->getId() !== $sprint->getId()) {
            $form->get('numero')->addError(new FormError('Ce numéro de vague est déjà utilisé.'));

            return false;
        }

        if ($form->has('prospects')) {
            $this->synchroniserProspects($sprint, $form->get('prospects')->getData());
        }

        try {
            $this->em->persist($sprint);
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $form->get('numero')->addError(new FormError('Ce numéro de vague est déjà utilisé.'));

            return false;
        }

        return true;
    }

    /**
     * Ajout/retrait des prospects du multiselect (champ non mappé) : mêmes
     * règles que la sélection de vagues côté formulaire prospect, mais gérées
     * depuis la vague.
     */
    private function synchroniserProspects(Sprint $sprint, mixed $selection): void
    {
        $choisies = [];
        if (is_iterable($selection)) {
            foreach ($selection as $prospect) {
                if ($prospect instanceof Prospect && null !== $prospect->getId()) {
                    $choisies[(int) $prospect->getId()] = $prospect;
                }
            }
        }

        foreach ($sprint->getLiens()->toArray() as $lien) {
            $idProspect = $lien->getProspect()?->getId();
            if (null === $idProspect || !isset($choisies[(int) $idProspect])) {
                $sprint->getLiens()->removeElement($lien);
                $lien->getProspect()?->removeSprint($lien);

                continue;
            }
            unset($choisies[(int) $idProspect]);
        }

        foreach ($choisies as $prospect) {
            $lien = new ProspectSprint();
            $lien->setSprint($sprint);
            $prospect->addSprint($lien);
            $sprint->getLiens()->add($lien);
            $this->em->persist($lien);
        }
    }

    /**
     * Prospects déjà rattachés à la vague (valeur initiale du multiselect).
     *
     * @return array<int, Prospect>
     */
    private function prospectsDe(Sprint $sprint): array
    {
        $prospects = [];
        foreach ($sprint->getLiens() as $lien) {
            $prospect = $lien->getProspect();
            if (null !== $prospect) {
                $prospects[] = $prospect;
            }
        }

        return $prospects;
    }

    #[Route('/delete/{id}', name: 'app_user_vagues_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $sprint = $this->sprints->find($id);
        if (!$sprint) {
            return $this->redirectToRoute('app_user_vagues');
        }

        return $this->deleteEntity($request, $sprint, $id, 'delete-vague', 'vagues', $this->em, [
            'list' => 'app_user_vagues',
            'update' => 'app_user_vagues_update',
            'conflictMessage' => 'Suppression impossible : la vague est encore référencée par des prospects.',
        ]);
    }
}
