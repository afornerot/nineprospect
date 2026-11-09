<?php

namespace App\Service;

use App\Entity\Pipeline;
use App\Entity\User;
use App\Enum\PipelineStatut;
use App\Enum\QualificationProduit;
use App\Repository\ActionRepository;
use App\Repository\ContactRepository;
use App\Repository\PipelineRepository;
use App\Repository\ProspectProduitRepository;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Chiffres du tableau de bord, dans un seul endroit : l'écran HTML
 * (`DashboardController`) et l'API REST (`DashboardApiController`) lisent
 * exactement les mêmes compteurs.
 *
 * Les paramètres de filtre sont ceux de la page : `utilisateur`, `pipeline`,
 * `sprint`, `produit`, `cible`.
 *
 * N'y figurent que les **compteurs** : les listes d'actions détaillées
 * (retard / aujourd'hui / à venir) restent propres à l'écran.
 */
class DashboardStats
{
    public function __construct(
        private ProspectRepository $prospects,
        private ContactRepository $contacts,
        private ActionRepository $actions,
        private ProspectProduitRepository $prospectProduits,
        private PipelineRepository $pipelines,
        private UserRepository $users,
    ) {
    }

    /**
     * @return array{
     *   kpi: array{prospects: int, contacts: int, actionsEnRetard: int, actionsAujourdhui: int, actionsAVenir: int, nonContacte: int, nonQualifie: int},
     *   etapes: list<string>,
     *   donneesPipeline: list<array{label: string, data: list<int>}>,
     *   filtrePipeline: int|null,
     *   filtreSprint: int|null,
     *   filtreProduit: int|null,
     *   filtreCible: int|null,
     *   utilisateurFiltre: int|null,
     *   moisQualifications: list<string>,
     *   seriesQualifications: list<array{label: string, data: list<int>}>,
     *   moisContactes: list<string>,
     *   seriesContactes: list<array{label: string, data: list<int>}>,
     *   moisEtapes: list<string>,
     *   seriesEtapes: list<array{label: string, data: list<int>}>,
     *   moisRealisees: list<string>,
     *   seriesRealisees: list<array{label: string, data: list<int>}>
     * }
     */
    public function collect(Request $request): array
    {
        $filtrePipeline = $this->pipelineProduit($request);
        $filtreSprint = $this->lireParamEntier($request, 'sprint');
        $filtreProduit = $this->lireParamEntier($request, 'produit');
        $filtreCible = $this->lireParamEntier($request, 'cible');

        $user = $this->paramUtilisateur($request);
        $userId = $user?->getId();

        $etapes = null !== $filtrePipeline ? $filtrePipeline->getEtapes()->toArray() : [];
        $labels = [];
        foreach ($etapes as $etape) {
            $labels[] = (string) $etape->getNom();
        }

        $compteurs = null !== $filtrePipeline
            ? $this->prospects->countPipeline((int) $filtrePipeline->getId(), $filtreSprint, $userId, $filtreProduit, $filtreCible)
            : [];

        $donneesPipeline = [];
        foreach (PipelineStatut::LABELS as $label => $valeur) {
            $serie = [];
            foreach ($etapes as $etape) {
                $serie[] = $compteurs[(int) $etape->getId()][$valeur] ?? 0;
            }
            $donneesPipeline[] = ['label' => $label, 'data' => $serie];
        }

        $qualifications = $this->prospectProduits->countQualificationsParMoisParProduit($userId);
        $contactes = $this->prospects->countContactesParMois($userId);
        $etapesStatuts = $this->pipelines->countStatutsEtapeParMoisParLibelle($userId);
        $realisees = $this->actions->countRealiseesParMois($userId);

        return [
            'kpi' => [
                'prospects' => $this->compteurProspects($user),
                'contacts' => $this->compteurContacts($user),
                'actionsEnRetard' => $this->actions->countDue($user),
                'actionsAujourdhui' => $this->actions->countToday($user),
                'actionsAVenir' => $this->actions->countUpcoming($user),
                'nonContacte' => $this->compteurNonContacte($user),
                'nonQualifie' => $this->compteurNonQualifie($user),
            ],
            'etapes' => $labels,
            'donneesPipeline' => $donneesPipeline,
            'filtrePipeline' => null !== $filtrePipeline ? (int) $filtrePipeline->getId() : null,
            'filtreSprint' => $filtreSprint,
            'filtreProduit' => $filtreProduit,
            'filtreCible' => $filtreCible,
            'utilisateurFiltre' => $userId,
            'moisQualifications' => $qualifications['mois'],
            'seriesQualifications' => $qualifications['series'],
            'moisContactes' => $contactes['mois'],
            'seriesContactes' => $contactes['series'],
            'moisEtapes' => $etapesStatuts['mois'],
            'seriesEtapes' => $etapesStatuts['series'],
            'moisRealisees' => $realisees['mois'],
            'seriesRealisees' => $realisees['series'],
        ];
    }

    /**
     * Pipeline retenu pour le graphe : celui demandé, à défaut le pipeline par
     * défaut.
     */
    public function pipelineProduit(Request $request): ?Pipeline
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
    public function lireParamEntier(Request $request, string $nom): ?int
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
     * Utilisateur ciblé par le filtre « Affecté à ».
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
            ->where('NOT EXISTS (SELECT 1 FROM App\Entity\ProspectProduit pc2 WHERE pc2.prospect = p AND pc2.qualifie IN (:tranchees))')
            ->setParameter('tranchees', QualificationProduit::tranchees());
        if (null !== $user) {
            $qb->innerJoin('p.users', 'nq_u')->andWhere('nq_u.id = :nq_uId')->setParameter('nq_uId', $user);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
