<?php

namespace App\Service;

use App\Entity\Objective;
use App\Entity\ProspectDocument;
use App\Repository\ObjectiveRepository;
use App\Repository\PipelineValeurRepository;
use App\Repository\ProspectDocumentRepository;
use App\Repository\ProspectProduitRepository;

/**
 * Réalisé vs objectif du tableau de bord : une ligne par période + produit,
 * une cellule par type d'objectif, et le total sur l'ensemble. Lu
 * exactement pareil par l'écran HTML (`DashboardController`) et l'API REST
 * (`DashboardApiController`).
 *
 * Réalisé selon le type :
 * - prospects_qualifies : liens ProspectProduit passés « Oui » ;
 * - rdv : étapes « RDV » des vagues du produit passées à Réalisé (Oui) ;
 * - propositions / commandes : documents Dolibarr synchronisés du type ;
 * - montant_commandes : somme HT des commandes Dolibarr (en €) ;
 * - jours : sans source branchée, renvoie 0.
 *
 * Le filtre utilisateur s'applique au réalisé (pas aux cibles).
 */
class ObjectifsStats
{
    public function __construct(
        private ObjectiveRepository $objectives,
        private ProspectProduitRepository $prospectProduits,
        private PipelineValeurRepository $pipelineValeurs,
        private ProspectDocumentRepository $prospectDocuments,
    ) {
    }

    /**
     * Lignes du tableau et totaux par type.
     *
     * @return array{
     *   lignes: list<array{
     *     periodeDebut: string, periodeFin: string,
     *     produit: array{id: int|null, titre: string},
     *     cellules: array<string, array{realise: int|float, target: float}>
     *   }>,
     *   totaux: array<string, array{realise: int|float, target: float}>
     * }
     */
    public function collect(?int $auteurId): array
    {
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
                'realise' => $this->realise($objective, $auteurId),
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

        $lignes = [];
        foreach ($rows as $row) {
            $cells = [];
            foreach ($row['cells'] as $type => $cell) {
                $cells[$type] = [
                    'realise' => $cell['realise'],
                    'target' => $cell['objective']->getTarget(),
                ];
            }
            $objective = $row['objective'];
            $lignes[] = [
                'periodeDebut' => $objective->getStartDate()->format('Y-m-d'),
                'periodeFin' => $objective->getEndDate()->format('Y-m-d'),
                'produit' => [
                    'id' => $objective->getProduit()?->getId(),
                    'titre' => $objective->getProduit()?->getTitre() ?? 'Tous les produits',
                ],
                'cellules' => $cells,
            ];
        }

        return ['lignes' => $lignes, 'totaux' => $totaux];
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
