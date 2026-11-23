<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Repository\ObjectiveRepository;
use App\Repository\ProspectDocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran de gestion Dolibarr : liste des devis/commandes générés par les
 * commerciaux depuis la plus ancienne date d'objectif, avec vérification du
 * rattachement à un prospect (toujours vrai : la synchro ne garde que les
 * docs de tiers liés) et à un produit.
 */
class DolibarrController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private ProspectDocumentRepository $prospectDocuments,
        private ObjectiveRepository $objectives,
    ) {
    }

    #[Route('/admin/dolibarr', name: 'app_admin_dolibarr')]
    public function list(Request $request): Response
    {
        $debut = $this->objectives->createQueryBuilder('o')
            ->select('MIN(o.startDate)')
            ->getQuery()
            ->getSingleScalarResult();

        $debut = null !== $debut ? new \DateTime((string) $debut) : new \DateTime('first day of january this year');

        return $this->renderLayout('dolibarr/list.html.twig', 'Gestion Dolibarr', [
            'debut' => $debut,
            'documents' => $this->prospectDocuments->pourGestionDepuis($debut),
        ]);
    }
}
