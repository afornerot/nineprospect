<?php

namespace App\Controller;

use App\Enum\PipelineStatut;
use App\Repository\CibleRepository;
use App\Repository\PipelineRepository;
use App\Repository\ProduitRepository;
use App\Repository\SprintRepository;
use App\Repository\UserRepository;
use App\Service\DashboardStats;
use OpenApi\Attributes as OA;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * API REST des chiffres du tableau de bord.
 *
 * Authentification : en-tête `X-API-SECRET` (valeur de `APP_SECRET`), portée
 * par le firewall `api` (voir `ApiKeyAuthenticator`) et le rôle `ROLE_MCP`.
 *
 * Les compteurs sont exactement ceux de `/user/dashboard` : les deux écrans
 * lisent `DashboardStats`. Seuls les chiffres sont exposés — les listes
 * détaillées d'actions (retard / aujourd'hui / à venir) restent sur l'écran.
 */
#[OA\Tag(name: 'Tableau de bord')]
#[Route('/v1/api/dashboard')]
#[IsGranted('ROLE_MCP')]
class DashboardApiController extends AbstractController
{
    public function __construct(
        private DashboardStats $stats,
        private PipelineRepository $pipelines,
        private SprintRepository $sprints,
        private ProduitRepository $produits,
        private CibleRepository $cibles,
        private UserRepository $users,
    ) {
    }

    /**
     * Chiffres complets du tableau de bord.
     *
     * Filtres facultatifs, identiques à ceux de la page :
     * `utilisateur`, `pipeline`, `sprint`, `produit`, `cible`.
     */
    #[Route('', name: 'app_api_dashboard', methods: ['GET'])]
    public function index(Request $request): JsonResponse
    {
        $donnees = $this->stats->collect($request);

        return new JsonResponse([
            'kpi' => $donnees['kpi'],
            'filtres' => [
                'utilisateur' => $donnees['utilisateurFiltre'],
                'pipeline' => $donnees['filtrePipeline'],
                'sprint' => $donnees['filtreSprint'],
                'produit' => $donnees['filtreProduit'],
                'cible' => $donnees['filtreCible'],
            ],
            'pipeline' => [
                'etapes' => $donnees['etapes'],
                // Une série par statut, alignée sur `etapes`.
                'statuts' => PipelineStatut::LABELS,
                'series' => $donnees['donneesPipeline'],
            ],
            'contactes' => [
                'mois' => $donnees['moisContactes'],
                'series' => $donnees['seriesContactes'],
            ],
            'qualifications' => [
                'mois' => $donnees['moisQualifications'],
                'series' => $donnees['seriesQualifications'],
            ],
            'etapes' => [
                'mois' => $donnees['moisEtapes'],
                'series' => $donnees['seriesEtapes'],
            ],
            'actionsRealisees' => [
                'mois' => $donnees['moisRealisees'],
                'series' => $donnees['seriesRealisees'],
            ],
        ]);
    }

    /**
     * Listes de référence utilisées par les filtres (identifiants + libellés),
     * pour construire une requête filtrée sans lire l'interface.
     */
    #[Route('/filtres', name: 'app_api_dashboard_filtres', methods: ['GET'])]
    public function filtres(): JsonResponse
    {
        $collecte = static fn (array $entites, callable $mapper): array => array_map($mapper, $entites);

        return new JsonResponse([
            'utilisateurs' => $collecte($this->users->findAll(), static fn ($u): array => [
                'id' => $u->getId(),
                'username' => $u->getUsername(),
            ]),
            'pipelines' => $collecte($this->pipelines->findAllOrdered(), static fn ($p): array => [
                'id' => $p->getId(),
                'nom' => (string) $p,
            ]),
            'sprints' => $collecte($this->sprints->findAllOrdered(), static fn ($s): array => [
                'id' => $s->getId(),
                'libelle' => (string) $s,
            ]),
            'produits' => $collecte($this->produits->findAllOrdered(), static fn ($p): array => [
                'id' => $p->getId(),
                'titre' => $p->getTitre(),
            ]),
            'cibles' => $collecte($this->cibles->findAllOrdered(), static fn ($c): array => [
                'id' => $c->getId(),
                'nom' => $c->getNom(),
            ]),
        ]);
    }
}
