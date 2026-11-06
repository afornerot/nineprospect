<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Produit;
use App\Entity\ProspectProduit;
use App\Http\ContexteRetour;
use App\Repository\ProduitRepository;
use App\Repository\ProspectProduitRepository;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/produits/bulk')]
class ProduitBulkController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private ProduitRepository $produits,
        private ProspectRepository $prospects,
        private ProspectProduitRepository $prospectProduits,
        private EntityManagerInterface $em,
        protected ContexteRetour $contexteRetour,
    ) {
    }

    /**
     * Affiche la liste des prospects qui n'ont encore aucune Produit, à
     * gauche, et un sélecteur de Produit à droite. Le user ajoute des
     * prospects à la Produit, puis valide pour les rattacher tous en lot.
     */
    #[Route('', name: 'app_user_produits_bulk')]
    public function bulk(Request $request): Response
    {
        $produits = $this->produits->findAllOrdered();
        $produitId = $request->query->getInt('produit', 0);

        // Si un produit est sélectionnée, on affiche les prospects qui
        // n'y sont PAS rattachés. Sinon, on affiche les prospects sans
        // aucun produit (= qui n'ont pas encore été ciblés).
        if ($produitId > 0 && $this->produits->find($produitId) instanceof Produit) {
            $prospectsHors = $this->prospects->findHorsProduit($produitId);
        } else {
            $prospectsHors = $this->prospects->findSansProduit();
        }

        return $this->renderLayout('produits/bulk.html.twig', 'Affecter en masse', [
            'produits' => $produits,
            'produitId' => $produitId,
            'prospectsHors' => $prospectsHors,
            'retour' => $this->contexteRetour->listeDuModule($request, 'app_user_produits'),
        ]);
    }

    /**
     * POST /user/produits/bulk/submit : ajoute la liste de prospects à la
     * Produit sélectionnée, puis redirige vers la liste des Produits.
     */
    #[Route('/submit', name: 'app_user_produits_bulk_submit', methods: ['POST'])]
    public function bulkSubmit(Request $request): Response
    {
        $produitId = $request->request->getInt('produit_id');
        $prospectIds = (array) $request->request->all('prospect_ids');

        if ($produitId <= 0) {
            $this->addFlash('error', 'Sélectionnez un produit.');

            return $this->redirectToRoute('app_user_produits_bulk');
        }

        $produit = $this->produits->find($produitId);
        if (!$produit instanceof Produit) {
            $this->addFlash('error', 'Produit introuvable.');

            return $this->redirectToRoute('app_user_produits_bulk');
        }

        $count = 0;
        foreach ($prospectIds as $prospectId) {
            $prospect = $this->prospects->find((int) $prospectId);
            if (!$prospect) {
                continue;
            }
            // Vérifier qu'aucun ProspectProduit n'existe déjà pour ce couple
            if (null === $this->prospectProduits->findByProspectAndProduit($prospect->getId(), $produitId)) {
                $pc = new ProspectProduit();
                $pc->setProspect($prospect);
                $pc->setProduit($produit);
                $pc->setQualifie(null);
                $this->em->persist($pc);
                ++$count;
            }
        }

        $this->em->flush();
        $this->addFlash('success', sprintf('%d prospect(s) affecté(s) à le produit "%s".', $count, $produit->getTitre()));

        return $this->redirectToRoute('app_user_produits_bulk', ['produit' => $produitId]);
    }
}
