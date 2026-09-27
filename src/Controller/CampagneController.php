<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Campagne;
use App\Entity\Prospect;
use App\Form\CampagneType;
use App\Repository\CampagneRepository;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/campagnes')]
class CampagneController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private CampagneRepository $campagnes,
        private ProspectRepository $prospects,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_campagnes')]
    public function list(): Response
    {
        $lignes = [];
        foreach ($this->campagnes->findAllOrdered() as $campagne) {
            $nbProspects = $this->prospects->countByCampagne((int) $campagne->getId());
            $lignes[] = [
                'campagne' => $campagne,
                'prospects' => $nbProspects,
                'cpl' => $nbProspects > 0 && null !== $campagne->getBudget()
                    ? $campagne->getBudget() / $nbProspects
                    : null,
            ];
        }

        return $this->renderLayout('campagnes/list.html.twig', 'Campagnes', [
            'routesubmit' => 'app_user_campagnes_submit',
            'routeupdate' => 'app_user_campagnes_update',
            'routeprospect' => 'app_user_prospects',
            'lignes' => $lignes,
            'prospectsHorsMeta' => $this->prospects->countHorsCampagne(),
        ]);
    }

    #[Route('/bulk', name: 'app_user_campagnes_bulk')]
    public function bulk(): Response
    {
        $campagnes = $this->campagnes->findAllOrdered();
        $prospectsWithoutCampagne = $this->prospects->findWithoutCampagne();

        return $this->renderLayout('campagnes/bulk.html.twig', 'Affecter en masse', [
            'campagnes' => $campagnes,
            'prospectsWithoutCampagne' => $prospectsWithoutCampagne,
        ]);
    }

    #[Route('/bulk/submit', name: 'app_user_campagnes_bulk_submit', methods: ['POST'])]
    public function bulkSubmit(Request $request): Response
    {
        $campagneId = $request->request->getInt('campagne_id');
        $prospectIds = $request->request->all('prospect_ids');

        if (0 === $campagneId) {
            $this->addFlash('error', 'Sélectionnez une campagne.');

            return $this->redirectToRoute('app_user_campagnes_bulk');
        }

        $campagne = $this->campagnes->find($campagneId);
        if (!$campagne) {
            $this->addFlash('error', 'Campagne introuvable.');

            return $this->redirectToRoute('app_user_campagnes_bulk');
        }

        $count = 0;
        foreach ($prospectIds as $prospectId) {
            $prospect = $this->prospects->find((int) $prospectId);
            if (!$prospect) {
                continue;
            }
            $prospect->setCampagne($campagne);
            $count++;
        }

        $this->em->flush();
        $this->addFlash('success', $count . ' prospect(s) affecté(s) à la campagne "' . $campagne->getLibelle() . '".');

        return $this->redirectToRoute('app_user_campagnes');
    }

    #[Route('/submit', name: 'app_user_campagnes_submit')]
    public function submit(Request $request): Response
    {
        $campagne = new Campagne();

        $form = $this->createForm(CampagneType::class, $campagne);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($campagne);
            $this->em->flush();

            // Idem vagues : la création ouvre la fiche, où se fait le
            // rattachement des prospects.
            return $this->redirectToRoute('app_user_campagnes_update', ['id' => $campagne->getId()]);
        }

        return $this->renderLayout('campagnes/edit.html.twig', 'Nouvelle campagne', [
            'routecancel' => 'app_user_campagnes',
            'mode' => 'submit',
            'form' => $form,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_campagnes_update')]
    public function update(int $id, Request $request): Response
    {
        $campagne = $this->campagnes->find($id);
        if (!$campagne) {
            return $this->redirectToRoute('app_user_campagnes');
        }

        $form = $this->createForm(CampagneType::class, $campagne, ['afficherProspects' => true]);
        $form->get('prospects')->setData($this->prospectsDe($campagne));
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->sauverCampagne($campagne, $form)) {
            return $this->redirectToRoute('app_user_campagnes');
        }

        $allProspects = $this->prospects->findBy([], ['nom' => 'ASC']);
        $campagneProspects = $this->prospectsDe($campagne);

        return $this->renderLayout('campagnes/edit.html.twig', 'Modification = '.$campagne->getLibelle(), [
            'routecancel' => 'app_user_campagnes',
            'routedelete' => 'app_user_campagnes_delete',
            'mode' => 'update',
            'form' => $form,
            'campagne' => $campagne,
            'allProspects' => $allProspects,
            'campagneProspects' => $campagneProspects,
        ]);
    }

    /**
     * Synchronise l'appartenance des prospects à la campagne : la sélection
     * soumise fait foi (ajout = `prospect.campagne = $this campagne`,
     * retrait = `prospect.campagne = null`).
     *
     * @param FormInterface<Campagne> $form
     */
    private function sauverCampagne(Campagne $campagne, FormInterface $form): bool
    {
        $selection = $form->has('prospects') ? $form->get('prospects')->getData() : null;

        $souhaites = [];
        if (is_iterable($selection)) {
            foreach ($selection as $prospect) {
                if ($prospect instanceof Prospect && null !== $prospect->getId()) {
                    $souhaites[(int) $prospect->getId()] = $prospect;
                }
            }
        }

        $actuels = [];
        foreach ($campagne->getId() ? $this->prospects->findByCampagne((int) $campagne->getId()) : [] as $prospect) {
            $actuels[(int) $prospect->getId()] = $prospect;
        }

        // Retraits : présents dans la campagne mais absents de la sélection.
        foreach ($actuels as $idProspect => $prospect) {
            if (!isset($souhaites[$idProspect])) {
                $prospect->setCampagne(null);
            } else {
                unset($souhaites[$idProspect]);
            }
        }

        // Ajouts : présents dans la sélection mais pas encore rattachés à
        // cette campagne. (Les prospects déjà rattachés à une autre campagne
        // sont déplacés vers celle-ci.)
        foreach ($souhaites as $prospect) {
            $prospect->setCampagne($campagne);
        }

        $this->em->persist($campagne);
        $this->em->flush();

        return true;
    }

    /**
     * Prospects actuellement rattachés à la campagne (valeur initiale du
     * multiselect non mappé).
     *
     * @return array<int, Prospect>
     */
    private function prospectsDe(Campagne $campagne): array
    {
        if (null === $campagne->getId()) {
            return [];
        }

        return $this->prospects->findByCampagne((int) $campagne->getId());
    }

    #[Route('/delete/{id}', name: 'app_user_campagnes_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $campagne = $this->campagnes->find($id);
        if (!$campagne) {
            return $this->redirectToRoute('app_user_campagnes');
        }

        return $this->deleteEntity($request, $campagne, $id, 'delete-campagne', 'campagnes', $this->em, [
            'list' => 'app_user_campagnes',
            'update' => 'app_user_campagnes_update',
            'conflictMessage' => 'Suppression impossible : la campagne est encore référencée par des prospects.',
        ]);
    }
}
