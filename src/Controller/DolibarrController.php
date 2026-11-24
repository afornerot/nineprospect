<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Objective;
use App\Entity\ProspectDocument;
use App\Repository\ObjectiveRepository;
use App\Repository\ProspectDocumentRepository;
use App\Repository\UserRepository;
use App\Service\DolibarrApi;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran de gestion Dolibarr : interroge Dolibarr pour tous les devis et
 * commandes créés par les commerciaux depuis la plus ancienne date
 * d'objectif, puis les rapproche des documents mémorisés dans nineprospect
 * pour repérer ceux qui ne remontent pas (tiers non lié, produit non
 * associé).
 */
class DolibarrController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private DolibarrApi $dolibarr,
        private ProspectDocumentRepository $prospectDocuments,
        private ObjectiveRepository $objectives,
        private UserRepository $users,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('/admin/dolibarr', name: 'app_admin_dolibarr')]
    public function list(Request $request): Response
    {
        if (!$this->dolibarr->isConfigured()) {
            $this->addFlash('warning', 'Intégration Dolibarr désactivée (DOLIBARR_URL absent).');

            return $this->redirectToRoute('app_user_dashboard');
        }
        $debut = $this->objectives->createQueryBuilder('o')
            ->select('MIN(o.startDate)')
            ->getQuery()
            ->getSingleScalarResult();

        $debut = null !== $debut ? new \DateTime((string) $debut) : new \DateTime('first day of january this year');

        // Commerciaux déclarés avec leur id Dolibarr.
        $auteurs = [];
        $logins = [];
        foreach ($this->users->findBy(['commercial' => true]) as $commercial) {
            if (null !== $commercial->getDolibarrUserId()) {
                $auteurs[] = $commercial->getDolibarrUserId();
                $logins[$commercial->getDolibarrUserId()] = $commercial->getUsername();
            }
        }

        // Documents mémorisés dans nineprospect, indexés par id Dolibarr.
        $documentsDolibarr = [];
        foreach ($this->em->createQueryBuilder()
            ->select('d')
            ->from(ProspectDocument::class, 'd')
            ->where('d.type IN (:types)')
            ->setParameter('types', [ProspectDocument::TYPE_DEVIS, ProspectDocument::TYPE_COMMANDE])
            ->getQuery()
            ->getResult() as $doc) {
            $documentsDolibarr[$doc->getDolibarrId()] = $doc;
        }

        // Rapprochement : pour chaque doc Dolibarr des commerciaux, sait-on
        // le rattacher à un prospect nineprospect (et un produit) ?
        $lignes = [];
        foreach ($this->dolibarr->documentsCommerciauxDepuis($auteurs, $debut) as $docApi) {
            $local = $documentsDolibarr[$docApi['id']] ?? null;
            $lignes[] = [
                'doc' => $docApi,
                'createur' => $logins[$docApi['auteur']] ?? ('id '.$docApi['auteur']),
                'statutLabel' => $docApi['statutLabel'],
                'statutCouleur' => $docApi['statutCouleur'],
                'prospect' => $local?->getProspect(),
                'produit' => $local?->getProduit(),
            ];
        }

        // Tiers Dolibarr absents de nineprospect : récupère leur nom via
        // l'API, un appel par tiers (en cache pendant la requête).
        $tiersNoms = [];
        $tiersUrls = [];
        foreach ($lignes as $ligne) {
            $socid = $ligne['doc']['socid'];
            if (null === $ligne['prospect'] && '' !== $socid && !isset($tiersNoms[$socid])) {
                $tiers = $this->dolibarr->findById($socid);
                $tiersNoms[$socid] = \is_array($tiers) ? ($tiers['nom'] ?? null) : null;
                $tiersUrls[$socid] = $this->dolibarr->cardUrl($socid);
            }
        }

        return $this->renderLayout('dolibarr/list.html.twig', 'Gestion Dolibarr', [
            'debut' => $debut,
            'lignes' => $lignes,
            'tiersNoms' => $tiersNoms,
            'tiersUrls' => $tiersUrls,
            'nbAbsents' => \count(array_filter($lignes, fn (array $ligne): bool => null === $ligne['prospect'])),
        ]);
    }
}
