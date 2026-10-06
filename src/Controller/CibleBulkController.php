<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Cible;
use App\Entity\ProspectCible;
use App\Repository\CibleRepository;
use App\Repository\ProspectCibleRepository;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/cibles/bulk')]
class CibleBulkController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private CibleRepository $cibles,
        private ProspectRepository $prospects,
        private ProspectCibleRepository $prospectCibles,
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * Affiche la liste des prospects qui n'ont encore aucune Cible, à
     * gauche, et un sélecteur de Cible à droite. Le user ajoute des
     * prospects à la Cible, puis valide pour les rattacher tous en lot.
     */
    #[Route('', name: 'app_user_cibles_bulk')]
    public function bulk(Request $request): Response
    {
        $cibles = $this->cibles->findAllOrdered();
        $cibleId = $request->query->getInt('cible', 0);

        // Si une cible est sélectionnée, on affiche les prospects qui
        // n'y sont PAS rattachés. Sinon, on affiche les prospects sans
        // aucune cible (= qui n'ont pas encore été ciblés).
        if ($cibleId > 0 && $this->cibles->find($cibleId) instanceof Cible) {
            $prospectsHors = $this->prospects->findHorsCible($cibleId);
        } else {
            $prospectsHors = $this->prospects->findSansCible();
        }

        return $this->renderLayout('cibles/bulk.html.twig', 'Affecter en masse', [
            'cibles' => $cibles,
            'cibleId' => $cibleId,
            'prospectsHors' => $prospectsHors,
            'routecancel' => 'app_user_cibles',
        ]);
    }

    /**
     * POST /user/cibles/bulk/submit : ajoute la liste de prospects à la
     * Cible sélectionnée, puis redirige vers la liste des Cibles.
     */
    #[Route('/submit', name: 'app_user_cibles_bulk_submit', methods: ['POST'])]
    public function bulkSubmit(Request $request): Response
    {
        $cibleId = $request->request->getInt('cible_id');
        $prospectIds = (array) $request->request->all('prospect_ids');

        if ($cibleId <= 0) {
            $this->addFlash('error', 'Sélectionnez une cible.');

            return $this->redirectToRoute('app_user_cibles_bulk');
        }

        $cible = $this->cibles->find($cibleId);
        if (!$cible instanceof Cible) {
            $this->addFlash('error', 'Cible introuvable.');

            return $this->redirectToRoute('app_user_cibles_bulk');
        }

        $count = 0;
        foreach ($prospectIds as $prospectId) {
            $prospect = $this->prospects->find((int) $prospectId);
            if (!$prospect) {
                continue;
            }
            // Vérifier qu'aucun ProspectCible n'existe déjà pour ce couple
            if (null === $this->prospectCibles->findByProspectAndCible($prospect->getId(), $cibleId)) {
                $pc = new ProspectCible();
                $pc->setProspect($prospect);
                $pc->setCible($cible);
                $pc->setQualifie(null);
                $this->em->persist($pc);
                ++$count;
            }
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('%d prospect(s) affecté(s) à la cible "%s".', $count, $cible->getTitre()));

        return $this->redirectToRoute('app_user_cibles_bulk', ['cible' => $cibleId]);
    }
}
