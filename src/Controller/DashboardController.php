<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Pipeline;
use App\Enum\PipelineStatut;
use App\Repository\ActionRepository;
use App\Repository\CampagneRepository;
use App\Repository\ContactRepository;
use App\Repository\DepartementRepository;
use App\Repository\PipelineRepository;
use App\Repository\ProspectRepository;
use App\Repository\SprintRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user')]
class DashboardController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private ProspectRepository $prospects,
        private ContactRepository $contacts,
        private ActionRepository $actions,
        private CampagneRepository $campagnes,
        private DepartementRepository $departements,
        private SprintRepository $sprints,
        private PipelineRepository $pipelines,
    ) {
    }

    #[Route('/dashboard', name: 'app_user_dashboard')]
    public function index(Request $request): Response
    {
        $filtrePipeline = $this->pipelineCible($request);
        $filtreSprint = $this->lireParamEntier($request, 'sprint');

        $etapes = null !== $filtrePipeline ? $filtrePipeline->getEtapes()->toArray() : [];
        $labels = [];
        foreach ($etapes as $etape) {
            $labels[] = (string) $etape->getNom();
        }

        $compteurs = null !== $filtrePipeline
            ? $this->prospects->countPipeline((int) $filtrePipeline->getId(), $filtreSprint)
            : [];

        $donneesPipeline = [];
        foreach (PipelineStatut::LABELS as $label => $valeur) {
            $serie = [];
            foreach ($etapes as $etape) {
                $serie[] = $compteurs[(int) $etape->getId()][$valeur] ?? 0;
            }
            $donneesPipeline[] = ['label' => $label, 'data' => $serie];
        }

        $campagnes = [];
        foreach ($this->campagnes->findAllOrdered() as $campagne) {
            $campagnes['noms'][] = $campagne->getLibelle();
            $campagnes['totaux'][] = $this->prospects->countByCampagne((int) $campagne->getId());
        }

        $geo = array_slice(array_values(array_filter($this->departements->countProspectsGrouped(), static fn (array $ligne) => $ligne['total'] > 0)), 0, 10);

        return $this->renderLayout('dashboard/index.html.twig', 'Tableau de bord', [
            'kpi' => [
                'prospects' => (int) $this->prospects->count([]),
                'contacts' => (int) $this->contacts->count([]),
                'actionsEnRetard' => $this->actions->countDue(),
                'actionsAVenir' => $this->actions->countUpcoming(),
                'nonContacte' => $this->prospects->countNonContacte(),
                'nonQualifie' => $this->prospects->countNonQualifie(),
            ],
            'etapes' => $labels,
            'donneesPipeline' => $donneesPipeline,
            'pipelines' => $this->pipelines->findAllOrdered(),
            'filtrePipeline' => null !== $filtrePipeline ? (int) $filtrePipeline->getId() : null,
            'sprints' => $this->sprints->findAllOrdered(),
            'filtreSprint' => $filtreSprint,
            'campagnes' => $campagnes,
            'geo' => $geo,
            'retard' => $this->actions->findDue(),
            'avenir' => $this->actions->findUpcoming(),
            'routeProspect' => 'app_user_prospects_show',
        ]);
    }

    /**
     * Pipeline sélectionné pour le graphe (paramètre « pipeline », à défaut
     * le pipeline par défaut).
     */
    private function pipelineCible(Request $request): ?Pipeline
    {
        $id = $this->lireParamEntier($request, 'pipeline');
        if (null !== $id) {
            $pipeline = $this->pipelines->find($id);
            if (null !== $pipeline) {
                return $pipeline;
            }
        }

        return $this->pipelines->getDefault();
    }

    /**
     * Entier de requête tolérant aux valeurs vides (« Toutes » = '') ou non
     * numériques : renvoie null au lieu de lever une exception.
     */
    private function lireParamEntier(Request $request, string $nom): ?int
    {
        $valeur = $request->query->all()[$nom] ?? null;
        if (!is_scalar($valeur)) {
            return null;
        }

        $texte = trim((string) $valeur);
        if ('' === $texte) {
            return null;
        }

        return filter_var($texte, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
    }
}
