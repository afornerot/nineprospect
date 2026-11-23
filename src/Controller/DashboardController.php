<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Objective;
use App\Entity\ProspectDocument;
use App\Repository\ActionRepository;
use App\Repository\CibleRepository;
use App\Repository\ObjectiveRepository;
use App\Repository\PipelineRepository;
use App\Repository\PipelineValeurRepository;
use App\Repository\ProduitRepository;
use App\Repository\ProspectDocumentRepository;
use App\Repository\ProspectProduitRepository;
use App\Repository\SprintRepository;
use App\Repository\UserRepository;
use App\Service\DashboardStats;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user')]
class DashboardController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private DashboardStats $stats,
        private ActionRepository $actions,
        private SprintRepository $sprints,
        private PipelineRepository $pipelines,
        private UserRepository $users,
        private ProduitRepository $produits,
        private CibleRepository $cibles,
        private ObjectiveRepository $objectives,
        private ProspectProduitRepository $prospectProduits,
        private PipelineValeurRepository $pipelineValeurs,
        private ProspectDocumentRepository $prospectDocuments,
    ) {
    }

    #[Route('/dashboard', name: 'app_user_dashboard')]
    public function index(Request $request): Response
    {
        // Chiffres partagés avec l'API REST (voir DashboardStats).
        $donnees = $this->stats->collect($request);
        $user = null !== $donnees['utilisateurFiltre'] ? $this->users->find($donnees['utilisateurFiltre']) : null;

        // Réalisé vs objectif : une ligne par période + produit, une colonne
        // par type (voir index.html.twig). Le filtre utilisateur s'applique
        // au réalisé.
        // - prospects_qualifies : liens ProspectProduit passés « Oui » ;
        // - rdv : étapes « RDV » des vagues du produit passées à Réalisé (Oui).
        $rows = [];
        $objectives = $this->objectives->findAll();
        usort($objectives, fn (Objective $a, Objective $b): int => [$a->getStartDate()->format('Y-m-d'), $a->getProduit()?->getTitre() ?? '']
            <=> [$b->getStartDate()->format('Y-m-d'), $b->getProduit()?->getTitre() ?? '']);

        foreach ($objectives as $objective) {
            $cle = $objective->getStartDate()->format('Y-m-d').'|'
                .$objective->getEndDate()->format('Y-m-d').'|'
                .($objective->getProduit()?->getId() ?? 0);
            $rows[$cle] ??= ['objective' => $objective, 'cells' => []];
            $rows[$cle]['cells'][$objective->getType()] = [
                'objective' => $objective,
                'realise' => $this->realise($objective, $user?->getId()),
            ];
        }

        // Total sur tous les objectifs : réalisé et cible additionnés par type.
        // `montant_commandes` est additionné en €, les autres en unités.
        $totaux = [];
        foreach ($rows as $row) {
            foreach ($row['cells'] as $type => $cell) {
                $totaux[$type] ??= ['realise' => 0, 'target' => 0.0];
                $totaux[$type]['realise'] += $cell['realise'];
                $totaux[$type]['target'] += $cell['objective']->getTarget();
            }
        }

        return $this->renderLayout('dashboard/index.html.twig', 'Tableau de bord', $donnees + [
            'pipelines' => $this->pipelines->findAllOrdered(),
            'sprints' => $this->sprints->findAllOrdered(),
            'produits' => $this->produits->findAllOrdered(),
            'cibles' => $this->cibles->findAllOrdered(),
            // Listes détaillées : propres à l'écran, hors API.
            'retard' => $this->actions->findDue(null, $user),
            'aujourdhui' => $this->actions->findToday(null, $user),
            'avenir' => $this->actions->findUpcoming(null, 50, $user),
            'routeProspect' => 'app_user_prospects_show',
            'utilisateurs' => $this->users->findAll(),
            'objectifsQualifies' => array_values($rows),
            'objectifsTotaux' => $totaux,
        ]);
    }

    /**
     * Réalisé d'un objectif selon son type, sur sa période et son produit.
     * Type jours sans source branchée : renvoie 0.
     */
    private function realise(Objective $objective, ?int $auteurId): int|float
    {
        $debut = $objective->getStartDate();
        $fin = $objective->getEndDate();
        $produitId = $objective->getProduit()?->getId();

        return match ($objective->getType()) {
            Objective::TYPE_PROSPECTS_QUALIFIES => $this->prospectProduits->countQualifiesOuiParPeriode($debut, $fin, $produitId, $auteurId),
            Objective::TYPE_RDV => $this->pipelineValeurs->countEtapesRealiseesParPeriode('RDV', $debut, $fin, $produitId, $auteurId),
            Objective::TYPE_PROPOSITIONS => $this->prospectDocuments->countParTypeEtPeriode(ProspectDocument::TYPE_DEVIS, $debut, $fin, $produitId),
            Objective::TYPE_COMMANDES => $this->prospectDocuments->countParTypeEtPeriode(ProspectDocument::TYPE_COMMANDE, $debut, $fin, $produitId),
            Objective::TYPE_MONTANT_COMMANDES => $this->prospectDocuments->sommeMontantParTypeEtPeriode(ProspectDocument::TYPE_COMMANDE, $debut, $fin, $produitId),
            default => 0,
        };
    }
}
