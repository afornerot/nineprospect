<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Repository\ActionRepository;
use App\Repository\CibleRepository;
use App\Repository\PipelineRepository;
use App\Repository\ProduitRepository;
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
    ) {
    }

    #[Route('/dashboard', name: 'app_user_dashboard')]
    public function index(Request $request): Response
    {
        // Chiffres partagés avec l'API REST (voir DashboardStats).
        $donnees = $this->stats->collect($request);
        $user = null !== $donnees['utilisateurFiltre'] ? $this->users->find($donnees['utilisateurFiltre']) : null;

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
        ]);
    }
}
