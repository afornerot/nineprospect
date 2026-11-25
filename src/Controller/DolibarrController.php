<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\ProspectDocument;
use App\Repository\ProspectDocumentRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran de gestion Dolibarr : liste les devis et commandes Dolibarr déjà
 * synchronisés dans nineprospect (documents mémorisés), avec leur prospect
 * et le produit associé ou non — pour décider des associations produit à
 * faire. Les documents Dolibarr non synchronisés ne sont pas listés.
 */
class DolibarrController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private ProspectDocumentRepository $documents,
    ) {
    }

    #[Route('/admin/dolibarr', name: 'app_admin_dolibarr')]
    public function list(): Response
    {
        $lignes = $this->documents->createQueryBuilder('d')
            ->join('d.prospect', 'p')
            ->leftJoin('d.produit', 'pr')
            ->addSelect('p', 'pr')
            ->where('d.type IN (:types)')
            ->setParameter('types', [ProspectDocument::TYPE_DEVIS, ProspectDocument::TYPE_COMMANDE])
            ->orderBy('d.dateDoc', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->renderLayout('dolibarr/list.html.twig', 'Gestion Dolibarr', [
            'lignes' => $lignes,
        ]);
    }
}
