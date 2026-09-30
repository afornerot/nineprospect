<?php

namespace App\Controller;

use App\Entity\Campagne;
use App\Entity\Cible;
use App\Entity\Contact;
use App\Entity\Prospect;
use App\Entity\ProspectCible;
use App\Repository\CampagneRepository;
use App\Repository\CibleRepository;
use App\Repository\ContactRepository;
use App\Repository\ProspectCibleRepository;
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

class PublicProspectController extends AbstractController
{
    public function __construct(
        private CampagneRepository $campagnes,
        private CibleRepository $cibles,
        private ProspectRepository $prospects,
        private ProspectCibleRepository $prospectCibles,
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
            $cibleId = $request->request->get('cible');

            $cibleEntity = $this->cibles->find($cibleId);
            if (!$cibleEntity) {
                return new Response('Cible invalide', 400);
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
            'altchaEnabled' => $this->altchaEnabled,
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
            'allCibles' => $allCibles,
            'availableCibles' => $availableCibles,
            'redirectUrl' => 'https://www.cadoles.com',
            'show_docs' => $request->query->getBoolean('show_docs'),
        ]);
    }

    #[Route('/contact/{campagneSlug}/select-cible', name: 'app_public_select_cible', methods: ['POST'])]
    public function selectCible(string $campagneSlug, Request $request): Response
    {
        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        if (!$prospectId) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => '',
            ]);
        }

        $cibleId = $request->request->get('cibleId');
        $cible = $this->cibles->find($cibleId);

        if (!$cible) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => '',
            ]);
        }

        return $this->redirectToRoute('app_public_contact_success', [
            'campagneSlug' => $campagneSlug,
            'cibleSlug' => $cible->getSlug() ?? (string) $cible->getId(),
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

    #[Route('/contact/check-link/{prospectId}/{cibleId}', name: 'app_public_check_link', methods: ['GET'])]
    public function checkLink(int $prospectId, int $cibleId): JsonResponse
    {
        $prospectCible = $this->prospectCibles->findOneBy([
            'prospect' => $prospectId,
            'cible' => $cibleId,
        ]);

        return new JsonResponse(['linked' => $prospectCible !== null]);
    }

    #[Route('/contact/{campagneSlug}/link-cible', name: 'app_public_link_cible', methods: ['POST'])]
    public function linkCible(string $campagneSlug, Request $request): Response
    {
        $session = $request->getSession();
        $prospectId = $session->get('prospect_id');

        if (!$prospectId) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => '',
            ]);
        }

        $cibleId = $request->request->get('cibleId');
        $cible = $this->cibles->find($cibleId);

        if (!$cible) {
            return $this->redirectToRoute('app_public_contact_success', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => '',
            ]);
        }

        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            return $this->redirectToRoute('app_public_contact', [
                'campagneSlug' => $campagneSlug,
                'cibleSlug' => '',
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
        ] + ['show_docs' => true]);
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

    #[Route('/annuaire/cible/{id}', name: 'app_public_annuaire_cible', methods: ['GET'])]
    public function getCible(int $id): JsonResponse
    {
        $cible = $this->cibles->find($id);

        if (!$cible) {
            return new JsonResponse(['error' => 'Cible non trouvée'], 404);
        }

        return new JsonResponse([
            'id' => $cible->getId(),
            'titre' => $cible->getTitre(),
            'description' => $cible->getDescription(),
            'slug' => $cible->getSlug() ?? (string) $cible->getId(),
        ]);
    }
}
