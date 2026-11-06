<?php

namespace App\Controller;

use App\Entity\Campagne;
use App\Entity\Produit;
use App\Entity\Contact;
use App\Entity\Prospect;
use App\Entity\ProspectProduit;
use App\Repository\CampagneRepository;
use App\Repository\ProduitRepository;
use App\Repository\ContactRepository;
use App\Repository\ProspectProduitRepository;
use App\Repository\ProspectRepository;
use App\Service\AltchaService;
use App\Service\AnnuaireEntreprises;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use SortDirection;

class PublicProspectController extends AbstractController
{
    public function __construct(
        private CampagneRepository $campagnes,
        private ProduitRepository $produits,
        private ProspectRepository $prospects,
        private ProspectProduitRepository $prospectProduits,
        private ContactRepository $contacts,
        private EntityManagerInterface $em,
        private AltchaService $altcha,
        private AnnuaireEntreprises $annuaire,
        #[Autowire(env: 'ALTCHA_ENABLED')]
        private bool $altchaEnabled,
    ) {
    }

    private function findCampagne(string $campagneSlug): ?Campagne
    {
        $campagne = $this->campagnes->findOneBy(['slug' => $campagneSlug]);
        if (!$campagne) {
            $campagne = $this->campagnes->findOneBy(['externalId' => $campagneSlug]);
        }

        return $campagne;
    }

    private function findProduit(string $produitSlug): ?Produit
    {
        return $this->produits->findOneBy(['slug' => $produitSlug]);
    }

    #[Route('/altcha/request', name: 'app_altcha_request', methods: ['GET'])]
    public function altchaRequest(): JsonResponse
    {
        try {
            $challenge = $this->altcha->requestChallenge();
            return new JsonResponse($challenge);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/altcha/verify', name: 'app_altcha_verify', methods: ['POST', 'OPTIONS'])]
    public function altchaVerify(Request $request): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return new Response('', 204, [
                'Access-Control-Allow-Origin' => '*',
                'Access-Control-Allow-Methods' => 'POST, OPTIONS',
                'Access-Control-Allow-Headers' => 'Content-Type, *',
            ]);
        }

        try {
            $content = $request->getContent();
            $payload = json_decode($content, true);

            if (!$payload) {
                return new JsonResponse(['error' => 'Invalid JSON'], 400);
            }

            $result = $this->altcha->verifySolution($payload);

            $response = [
                'success' => true,
                'verified' => true,
                'error' => null,
                'spam' => false,
            ];

            if ($result['data']) {
                $response['data'] = $result['data'];
            }

            return new JsonResponse($response, 200, [
                'Access-Control-Allow-Origin' => '*',
            ]);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/contact/{campagneSlug}/{produitSlug}', name: 'app_public_contact', methods: ['GET', 'POST'])]
    public function contact(string $campagneSlug, string $produitSlug, Request $request): Response
    {
        $campagne = $this->findCampagne($campagneSlug);
        $produit = $this->findProduit($produitSlug);

        if (!$campagne || !$produit) {
            return $this->render('public/error.html.twig', [
                'message' => 'La page demandée est introuvable.',
            ]);
        }

        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        $allProduits = $this->produits->findBy([], ['titre' => SortDirection::Ascending]);

        if ($request->isMethod('POST')) {
            if ($this->altchaEnabled) {
                $altchaPayload = [
                    'challenge' => $request->request->get('altcha-challenge'),
                    'salt' => $request->request->get('altcha-salt'),
                    'signature' => $request->request->get('altcha-signature'),
                    'solution' => $request->request->get('altcha-solution'),
                ];

                if (!$this->altcha->verifySolution($altchaPayload)) {
                    return new Response('Altcha invalide', 400);
                }
            }

            $typePersonne = $request->request->get('typePersonne');
            $email = $request->request->get('email');
            $telephone = $request->request->get('telephone');
            $produitId = $request->request->get('produit');

            $produitEntity = $this->produits->find($produitId);
            if (!$produitEntity) {
                return new Response('Produit invalide', 400);
            }

            $prospect = null;
            $nom = null;

            if ('morale' === $typePersonne) {
                $siren = $request->request->get('siren');
                $nom = $request->request->get('raisonSociale') ?: 'Entreprise ' . $siren;

                if ($siren) {
                    $prospect = $this->prospects->findOneBy(['siren' => $siren]);
                }

                if (!$prospect) {
                    $prospect = $this->prospects->findOneBy(['nom' => $nom]);
                }
            } else {
                $nom = trim($request->request->get('nom') . ' ' . $request->request->get('prenom'));
                $prospect = $this->prospects->findOneBy(['nom' => $nom]);
            }

            if (!$prospect) {
                $prospect = $this->prospects->findOneBy(['email' => $email]);
            }

            if (!$prospect) {
                $prospect = new Prospect();
                $prospect->setCampagne($campagne);
                $prospect->setContacte(false);
            }

            if ('morale' === $typePersonne) {
                $siren = $request->request->get('siren');
                $nom = $request->request->get('raisonSociale') ?: 'Entreprise ' . $siren;
                $prospect->setNom($nom);
                $prospect->setSiren($siren);
                $prospect->setSiret($request->request->get('siret'));
                $prospect->setNaf($request->request->get('naf'));
                $prospect->setAdresse($request->request->get('adresse'));
                $prospect->setCodePostal($request->request->get('codePostal'));
                $prospect->setVille($request->request->get('ville'));

                $contactNom = $request->request->get('contactNom');
                $contactPrenom = $request->request->get('contactPrenom');
            } else {
                $nom = trim($request->request->get('nom') . ' ' . $request->request->get('prenom'));
                $prospect->setNom($nom);

                $contactNom = $request->request->get('nom');
                $contactPrenom = $request->request->get('prenom');
            }

            $prospect->setEmail($email);
            $prospect->setTelephone($telephone);

            $this->em->persist($prospect);
            $this->em->flush();

            $contact = $this->contacts->findOneBy(['prospect' => $prospect, 'email' => $email]);
            if (!$contact) {
                $contact = new Contact();
                $contact->setProspect($prospect);
                $contact->setEmail($email);
                $contact->setEstPrincipal(true);
            }
            $contact->setNom($contactNom);
            $contact->setPrenom($contactPrenom);
            $contact->setNomComplet(trim(($contactPrenom ?: '') . ' ' . ($contactNom ?: '')));
            $contact->setTelephone($telephone);

            $this->em->persist($contact);
            $this->em->flush();

            $session->set('prospect_id', $prospect->getId());

            $existingLink = $this->prospectProduits->findOneBy([
                'prospect' => $prospect,
                'produit' => $produitEntity,
            ]);

            if (!$existingLink) {
                $prospectProduit = new ProspectProduit();
                $prospectProduit->setProspect($prospect);
                $prospectProduit->setProduit($produitEntity);
                $prospectProduit->setQualifie(null);
                $this->em->persist($prospectProduit);
                $this->em->flush();
            }

            return $this->redirectToRoute('app_public_contact_success', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => $produitSlug,
            ]);
        }

        return $this->render('public/contact.html.twig', [
            'campagne' => $campagne,
            'produit' => $produit,
            'produits' => $allProduits,
            'altchaEnabled' => $this->altchaEnabled,
        ]);
    }

    #[Route('/contact/{campagneSlug}/{produitSlug}/success', name: 'app_public_contact_success', methods: ['GET', 'POST'])]
    public function success(string $campagneSlug, string $produitSlug, Request $request): Response
    {
        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        if (!$prospectId) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => $produitSlug,
            ]);
        }

        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            $session->remove('prospect_id');
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => $produitSlug,
            ]);
        }

        $produit = $this->findProduit($produitSlug);
        if (!$produit) {
            return $this->render('public/error.html.twig', [
                'message' => 'Produit non trouvée.',
            ]);
        }

        // Handle POST: link prospect to produit
        if ($request->isMethod('POST') && $request->request->has('linkProduit')) {
            $existingLink = $this->prospectProduits->findOneBy([
                'prospect' => $prospect,
                'produit' => $produit,
            ]);

            if (!$existingLink) {
                $prospectProduit = new ProspectProduit();
                $prospectProduit->setProspect($prospect);
                $prospectProduit->setProduit($produit);
                $prospectProduit->setQualifie(null);
                $this->em->persist($prospectProduit);
                $this->em->flush();
            }

            return $this->redirectToRoute('app_public_contact_success', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => $produitSlug,
            ] + ['show_docs' => true]);
        }

        $prospectProduit = $this->prospectProduits->findOneBy([
            'prospect' => $prospect,
            'produit' => $produit,
        ]);

        $allProduits = $this->produits->findBy([], ['titre' => SortDirection::Ascending]);

        return $this->render('public/contact_success.html.twig', [
            'prospect' => $prospect,
            'prospectProduit' => $prospectProduit,
            'campagne' => $this->findCampagne($campagneSlug),
            'produit' => $produit,
            'allProduits' => $allProduits,
            'redirectUrl' => 'https://www.cadoles.com',
            'show_docs' => $request->query->getBoolean('show_docs') || $prospectProduit !== null,
        ]);
    }

    #[Route('/contact/{campagneSlug}/select-produit', name: 'app_public_select_produit', methods: ['POST'])]
    public function selectProduit(string $campagneSlug, Request $request): Response
    {
        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        if (!$prospectId) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => '',
            ]);
        }

        $produitId = $request->request->get('produitId');
        $produit = $this->produits->find($produitId);

        if (!$produit) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => '',
            ]);
        }

        return $this->redirectToRoute('app_public_contact_success', [
            'campagneSlug' => $campagneSlug,
            'produitSlug' => $produit->getSlug() ?? (string) $produit->getId(),
        ]);
    }

    #[Route('/contact/{campagneSlug}/{produitSlug}/add-produit', name: 'app_public_add_produit', methods: ['POST'])]
    public function addProduit(string $campagneSlug, string $produitSlug, Request $request): Response
    {
        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        if (!$prospectId) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => $produitSlug,
            ]);
        }

        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            $session->remove('prospect_id');
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => $produitSlug,
            ]);
        }

        $produitId = $request->request->get('produit');
        $produit = $this->produits->find($produitId);

        if (!$produit) {
            return $this->redirectToRoute('app_public_contact_success', [
                'campagneSlug' => $campagneSlug,
                'produitSlug' => $produitSlug,
            ]);
        }

        $existingLink = $this->prospectProduits->findOneBy([
            'prospect' => $prospect,
            'produit' => $produit,
        ]);

        if (!$existingLink) {
            $prospectProduit = new ProspectProduit();
            $prospectProduit->setProspect($prospect);
            $prospectProduit->setProduit($produit);
            $prospectProduit->setQualifie(null);
            $this->em->persist($prospectProduit);
            $this->em->flush();
        }

        return $this->redirectToRoute('app_public_contact_success', [
            'campagneSlug' => $campagneSlug,
            'produitSlug' => $produit->getSlug() ?? (string) $produit->getId(),
        ]);
    }

    #[Route('/annuaire/search-by-name', name: 'app_public_annuaire_search_by_name', methods: ['GET'])]
    public function searchByName(Request $request): JsonResponse
    {
        $query = $request->query->get('q', '');

        if (\strlen($query) < 3) {
            return new JsonResponse([]);
        }

        $response = $this->annuaire->search($query);
        $results = [];
        foreach ($response['results'] as $row) {
            $data = $this->annuaire->normalize($row);
            $results[] = [
                'nom' => $data['nom'],
                'siren' => $data['siren'],
                'adresse' => $data['adresse'],
                'codePostal' => $data['code_postal'],
                'ville' => $data['ville'],
                'naf' => $data['naf'],
            ];
        }

        return new JsonResponse($results);
    }

    #[Route('/annuaire/produit/{id}', name: 'app_public_annuaire_produit', methods: ['GET'])]
    public function getProduit(int $id): JsonResponse
    {
        $produit = $this->produits->find($id);

        if (!$produit) {
            return new JsonResponse(['error' => 'Produit non trouvée'], 404);
        }

        return new JsonResponse([
            'id' => $produit->getId(),
            'titre' => $produit->getTitre(),
            'description' => $produit->getDescription(),
            'slug' => $produit->getSlug() ?? (string) $produit->getId(),
        ]);
    }
}
