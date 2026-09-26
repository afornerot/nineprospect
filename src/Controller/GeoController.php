<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Repository\DepartementRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user')]
class GeoController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private DepartementRepository $departements,
    ) {
    }

    #[Route('/geographie', name: 'app_user_geographie')]
    public function index(): Response
    {
        $lignes = $this->departements->countProspectsGrouped();

        return $this->renderLayout('geo/index.html.twig', 'Répartition géographique', [
            'lignes' => array_values(array_filter($lignes, static fn (array $ligne) => $ligne['total'] > 0)),
            'sansDepartement' => $this->departements->countProspectsSansDepartement(),
            'total' => array_sum(array_column($lignes, 'total')),
            'totalDepartements' => count($lignes),
        ]);
    }
}
