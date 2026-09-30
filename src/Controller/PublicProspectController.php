<?php

namespace App\Controller;

use App\Entity\Campagne;
use App\Entity\Cible;
use App\Entity\Prospect;
use App\Entity\ProspectCible;
use App\Repository\CampagneRepository;
use App\Repository\CibleRepository;
use App\Repository\ProspectCibleRepository;
use App\Repository\ProspectRepository;
use App\Service\AltchaService;
use App\Service\AnnuaireEntreprises;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class PublicProspectController extends AbstractController
{
    public function __construct(
        private CampagneRepository $campagnes,
        private CibleRepository $cibles,
        private ProspectRepository $prospects,
        private ProspectCibleRepository $prospectCibles,
        private EntityManagerInterface $em,
        private AltchaService $altcha,
        private AnnuaireEntreprises $annuaire,
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

    private function findCible(string $cibleSlug): ?Cible
    {
        return $this->cibles->findOneBy(['slug' => $cibleSlug]);
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

    #[Route('/altcha/verify', name: 'app_altcha_verify', methods: ['POST'])]
    public function altchaVerify(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);

        if (!$payload) {
            return new JsonResponse(['error' => 'Invalid payload'], 400);
        }

        try {
            $verified = $this->altcha->verifySolution($payload);
            return new JsonResponse(['success' => $verified]);
        } catch (\Exception $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }
    }

    #[Route('/contact/{campagneSlug}/{cibleSlug}', name: 'app_public_contact', methods: ['GET', 'POST'])]
    public function contact(string $campagneSlug, string $cibleSlug, Request $request): Response
    {
        $campagne = $this->findCampagne($campagneSlug);
        $cible = $this->findCible($cibleSlug);

        if (!$campagne || !$cible) {
            return $this->render('public/error.html.twig', [
                'message' => 'La page demandée est introuvable.',
            ]);
        }

        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        $allCibles = $this->cibles->findBy([], ['titre' => 'ASC']);

        if ($request->isMethod('POST')) {
            $altchaPayload = [
                'challenge' => $request->request->get('altcha-challenge'),
                'salt' => $request->request->get('altcha-salt'),
                'signature' => $request->request->get('altcha-signature'),
                'solution' => $request->request->get('altcha-solution'),
            ];

            if (!$this->altcha->verifySolution($altchaPayload)) {
                return new Response('Altcha invalide', 400);
            }

            $typePersonne = $request->request->get('typePersonne');
            $email = $request->request->get('email');
            $telephone = $request->request->get('telephone');
            $cibleId = $request->request->get('cible');

            $cibleEntity = $this->cibles->find($cibleId);
            if (!$cibleEntity) {
                return new Response('Cible invalide', 400);
            }

            $prospect = new Prospect();
            $prospect->setCampagne($campagne);
            $prospect->setContacte(false);

            if ('morale' === $typePersonne) {
                $siren = $request->request->get('siren');
                $prospect->setNom($request->request->get('raisonSociale') ?: 'Entreprise ' . $siren);
                $prospect->setSiren($siren);
                $prospect->setSiret($request->request->get('siret'));
                $prospect->setNaf($request->request->get('naf'));
                $prospect->setAdresse($request->request->get('adresse'));
                $prospect->setCodePostal($request->request->get('codePostal'));
                $prospect->setVille($request->request->get('ville'));
            } else {
                $nom = $request->request->get('nom');
                $prenom = $request->request->get('prenom');
                $prospect->setNom($nom . ' ' . $prenom);
            }

            $prospect->setEmail($email);
            $prospect->setTelephone($telephone);

            $this->em->persist($prospect);
            $this->em->flush();

            $session->set('prospect_id', $prospect->getId());

            $existingLink = $this->prospectCibles->findOneBy([
                'prospect' => $prospect,
                'cible' => $cibleEntity,
            ]);

            if (!$existingLink) {
                $prospectCible = new ProspectCible();
                $prospectCible->setProspect($prospect);
                $prospectCible->setCible($cibleEntity);
                $prospectCible->setQualifie(null);
                $this->em->persist($prospectCible);
                $this->em->flush();
            }

            return $this->redirectToRoute('app_public_contact_success', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => $cibleSlug,
            ]);
        }

        return $this->render('public/contact.html.twig', [
            'campagne' => $campagne,
            'cible' => $cible,
            'cibles' => $allCibles,
        ]);
    }

    #[Route('/contact/{campagneSlug}/{cibleSlug}/success', name: 'app_public_contact_success', methods: ['GET'])]
    public function success(string $campagneSlug, string $cibleSlug, Request $request): Response
    {
        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        if (!$prospectId) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => $cibleSlug,
            ]);
        }

        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            $session->remove('prospect_id');
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => $cibleSlug,
            ]);
        }

        $campagne = $this->findCampagne($campagneSlug);
        $cible = $this->findCible($cibleSlug);

        if (!$campagne || !$cible) {
            return $this->render('public/error.html.twig', [
                'message' => 'La page demandée est introuvable.',
            ]);
        }

        $prospectCible = $this->prospectCibles->findOneBy([
            'prospect' => $prospect,
            'cible' => $cible,
        ]);

        $allCibles = $this->cibles->findBy([], ['titre' => 'ASC']);
        $selectedCibleIds = array_map(
            fn($pc) => $pc->getCible()?->getId(),
            $prospect->getProspectCibles()->toArray()
        );
        $availableCibles = array_filter($allCibles, fn($c) => !in_array($c->getId(), $selectedCibleIds));

        return $this->render('public/contact_success.html.twig', [
            'prospect' => $prospect,
            'prospectCible' => $prospectCible,
            'campagne' => $campagne,
            'cible' => $cible,
            'availableCibles' => $availableCibles,
            'redirectUrl' => 'https://www.cadoles.com',
        ]);
    }

    #[Route('/contact/{campagneSlug}/{cibleSlug}/add-cible', name: 'app_public_add_cible', methods: ['POST'])]
    public function addCible(string $campagneSlug, string $cibleSlug, Request $request): Response
    {
        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        if (!$prospectId) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => $cibleSlug,
            ]);
        }

        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            $session->remove('prospect_id');
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => $cibleSlug,
            ]);
        }

        $cibleId = $request->request->get('cible');
        $cible = $this->cibles->find($cibleId);

        if (!$cible) {
            return $this->redirectToRoute('app_public_contact_success', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => $cibleSlug,
            ]);
        }

        $existingLink = $this->prospectCibles->findOneBy([
            'prospect' => $prospect,
            'cible' => $cible,
        ]);

        if (!$existingLink) {
            $prospectCible = new ProspectCible();
            $prospectCible->setProspect($prospect);
            $prospectCible->setCible($cible);
            $prospectCible->setQualifie(null);
            $this->em->persist($prospectCible);
            $this->em->flush();
        }

        return $this->redirectToRoute('app_public_contact_success', [
            'campagneSlug' => $campagneSlug,
            'cibleSlug' => $cible->getSlug() ?? (string) $cible->getId(),
        ]);
    }

    #[Route('/annuaire/search', name: 'app_public_annuaire_search', methods: ['GET'])]
    public function searchAnnuaire(Request $request): JsonResponse
    {
        $siren = $request->query->get('siren', '');
        $siren = preg_replace('/\D+/', '', $siren) ?? '';

        if (9 !== \strlen($siren)) {
            return new JsonResponse(['error' => 'SIREN invalide'], 400);
        }

        $result = $this->annuaire->searchBySiren($siren);

        if (null === $result) {
            return new JsonResponse(['error' => 'Entreprise non trouvée'], 404);
        }

        $normalized = $this->annuaire->normalize($result);

        return new JsonResponse([
            'ok' => true,
            'data' => $normalized,
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
}
