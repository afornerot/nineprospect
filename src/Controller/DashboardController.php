<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Pipeline;
use App\Entity\User;
use App\Enum\PipelineStatut;
use App\Repository\ActionRepository;
use App\Repository\CampagneRepository;
use App\Repository\CategoryRepository;
use App\Repository\CibleRepository;
use App\Repository\ContactRepository;
use App\Repository\DepartementRepository;
use App\Repository\PipelineRepository;
use App\Repository\ProspectRepository;
use App\Repository\SprintRepository;
use App\Repository\UserRepository;
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
        private UserRepository $users,
        private CibleRepository $cibles,
        private CategoryRepository $categories,
    ) {
    }

    #[Route('/dashboard', name: 'app_user_dashboard')]
    public function index(Request $request): Response
    {
        $filtrePipeline = $this->pipelineCible($request);
        $filtreSprint = $this->lireParamEntier($request, 'sprint');
        $filtreCible = $this->lireParamEntier($request, 'cible');
        $filtreCategorie = $this->lireParamEntier($request, 'categorie');

        $user = $this->paramUtilisateur($request);
        $userId = $user?->getId();

        $etapes = null !== $filtrePipeline ? $filtrePipeline->getEtapes()->toArray() : [];
        $labels = [];
        foreach ($etapes as $etape) {
            $labels[] = (string) $etape->getNom();
        }

        $compteurs = null !== $filtrePipeline
            ? $this->prospects->countPipeline((int) $filtrePipeline->getId(), $filtreSprint, $userId, $filtreCible, $filtreCategorie)
            : [];

        $donneesPipeline = [];
        foreach (PipelineStatut::LABELS as $label => $valeur) {
            $serie = [];
            foreach ($etapes as $etape) {
                $serie[] = $compteurs[(int) $etape->getId()][$valeur] ?? 0;
            }
            $donneesPipeline[] = ['label' => $label, 'data' => $serie];
        }

        $kpiProspects = $this->compteurProspects($user);
        $kpiContacts = $this->compteurContacts($user);

        $campagnes = [];
        foreach ($this->campagnes->findAllOrdered() as $campagne) {
            $campagnes['noms'][] = $campagne->getLibelle();
            $campagnes['totaux'][] = $this->prospects->countByCampagne((int) $campagne->getId(), $userId);
        }

        $geo = array_slice(array_values(array_filter(
            null !== $userId
                ? $this->departements->countProspectsGrouped($userId)
                : $this->departements->countProspectsGrouped(),
            static fn (array $ligne) => $ligne['total'] > 0
        )), 0, 10);

        return $this->renderLayout('dashboard/index.html.twig', 'Tableau de bord', [
            'kpi' => [
                'prospects' => $kpiProspects,
                'contacts' => $kpiContacts,
                'actionsEnRetard' => $this->actions->countDue($user),
                'actionsAujourdhui' => $this->actions->countToday($user),
                'actionsAVenir' => $this->actions->countUpcoming($user),
                'nonContacte' => $this->compteurNonContacte($user),
                'nonQualifie' => $this->compteurNonQualifie($user),
            ],
            'etapes' => $labels,
            'donneesPipeline' => $donneesPipeline,
            'pipelines' => $this->pipelines->findAllOrdered(),
            'filtrePipeline' => null !== $filtrePipeline ? (int) $filtrePipeline->getId() : null,
            'sprints' => $this->sprints->findAllOrdered(),
            'filtreSprint' => $filtreSprint,
            'cibles' => $this->cibles->findAllOrdered(),
            'filtreCible' => $filtreCible,
            'categories' => $this->categories->findAllOrdered(),
            'filtreCategorie' => $filtreCategorie,
            'campagnes' => $campagnes,
            'geo' => $geo,
            'retard' => $this->actions->findDue(null, $user),
            'aujourdhui' => $this->actions->findToday(null, $user),
            'avenir' => $this->actions->findUpcoming(null, 50, $user),
            'routeProspect' => 'app_user_prospects_show',
            'utilisateurs' => $this->users->findAll(),
            'utilisateurFiltre' => $userId,
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
     * Entier de requête tolérant aux valeurs vides ou non numériques.
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

    /**
     * Utilisateur ciblé par le filtre « Affecté à » (?utilisateur=).
     */
    private function paramUtilisateur(Request $request): ?User
    {
        $userId = $this->lireParamEntier($request, 'utilisateur');
        if (null === $userId) {
            return null;
        }

        $user = $this->users->find($userId);

        return $user instanceof User ? $user : null;
    }

    // --- Compteurs par utilisateur ---

    private function compteurProspects(?User $user): int
    {
        $qb = $this->prospects->createQueryBuilder('p')->select('COUNT(DISTINCT p.id)');
        if (null !== $user) {
            $qb->innerJoin('p.users', 'cu')->andWhere('cu.id = :cuId')->setParameter('cuId', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function compteurContacts(?User $user): int
    {
        $qb = $this->contacts->createQueryBuilder('c')->select('COUNT(DISTINCT c.id)');
        if (null !== $user) {
            $qb->innerJoin('c.prospect', 'c_p')
                ->innerJoin('c_p.users', 'c_u')
                ->andWhere('c_u.id = :c_uId')
                ->setParameter('c_uId', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function compteurNonContacte(?User $user): int
    {
        $qb = $this->prospects->createQueryBuilder('p')->select('COUNT(p.id)')
            ->where('p.contacte IS NULL OR p.contacte = :false')
            ->setParameter('false', false);
        if (null !== $user) {
            $qb->innerJoin('p.users', 'nc_u')->andWhere('nc_u.id = :nc_uId')->setParameter('nc_uId', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    private function compteurNonQualifie(?User $user): int
    {
        $qb = $this->prospects->createQueryBuilder('p')->select('COUNT(p.id)')
            ->where('NOT EXISTS (SELECT 1 FROM App\Entity\ProspectCible pc2 WHERE pc2.prospect = p AND pc2.qualifie = true)');
        if (null !== $user) {
            $qb->innerJoin('p.users', 'nq_u')->andWhere('nq_u.id = :nq_uId')->setParameter('nq_uId', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
