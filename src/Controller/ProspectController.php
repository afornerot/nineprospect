<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\PipelineEtape;
use App\Entity\Prospect;
use App\Entity\ProspectSprint;
use App\Entity\Sprint;
use App\Enum\PipelineStatut;
use App\Form\ProspectType;
use App\Repository\ActionTypeDefautRepository;
use App\Repository\CampagneRepository;
use App\Repository\DepartementRepository;
use App\Repository\CibleRepository;
use App\Repository\ProspectCibleRepository;
use App\Repository\PipelineRepository;
use App\Repository\ProspectRepository;
use App\Repository\SprintRepository;
use App\Repository\UserRepository;
use App\Service\AdresseApi;
use App\Service\AnnuaireEntreprises;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/prospects')]
class ProspectController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private ProspectRepository $prospects,
        private CampagneRepository $campagnes,
        private SprintRepository $sprints,
        private PipelineRepository $pipelines,
        private DepartementRepository $departements,
        private UserRepository $users,
        private EntityManagerInterface $em,
        private CibleRepository $cibles,
        private ProspectCibleRepository $prospectCibles,
        #[Autowire('%mapboxPublicToken%')]
        private string $mapboxPublicToken,
        private AnnuaireEntreprises $annuaire,
        private AdresseApi $adresseApi,
        private ActionTypeDefautRepository $types,
    ) {
    }

    #[Route('', name: 'app_user_prospects')]
    public function list(Request $request): Response
    {
        $filtres = $this->lireFiltres($request);

        return $this->renderLayout('prospects/list.html.twig', 'Prospects', [
            'routesubmit' => 'app_user_prospects_submit',
            'routeupdate' => 'app_user_prospects_update',
            'routeshow' => 'app_user_prospects_show',
            'prospects' => $this->prospects->findByFilters($filtres),
            'filtres' => $filtres,
            'campagnes' => $this->campagnes->findAllOrdered(),
            'sprints' => $this->sprints->findAllOrdered(),
            'utilisateurs' => $this->users->findAll(),
            'cibles' => $this->cibles->findAllOrdered(),
        ]);
    }

    #[Route('/show/{id}', name: 'app_user_prospects_show')]
    public function show(int $id): Response
    {
        $prospect = $this->prospects->find($id);
        if (!$prospect) {
            $this->addFlash('error', 'Prospect introuvable.');

            return $this->redirectToRoute('app_user_prospects');
        }

        $vagueCourante = $prospect->vagueCourante();
        $prevNext = null;
        if (null !== $vagueCourante && null !== $vagueCourante->getSprint()) {
            $prevNext = $this->prospects->findPrevNextInSprint($id, $vagueCourante->getSprint()->getId());
        }

        return $this->renderLayout('prospects/show.html.twig', $prospect->getNom() ?? 'Prospect', [
            'prospect' => $prospect,
            'routeupdate' => 'app_user_prospects_update',
            'mapboxToken' => $this->mapboxPublicToken,
            'typesSuggestions' => $this->types->findActifsOrdonnes(),
            'prevNext' => $prevNext,
        ]);
    }

    /**
     * GET /user/prospects/verify/{id}
     * Interroge l'API Annuaire des Entreprises sur la base du nom + CP
     * du prospect, et retourne une modale Bootstrap avec la liste (toujours)
     * des résultats trouvés. L'utilisateur choisit ensuite lequel appliquer.
     *
     * Le contenu retourné est un fragment HTML destiné à être inséré dans
     * la fiche prospect (pas une page entière).
     */
    #[Route('/verify/{id}', name: 'app_user_prospects_verify', methods: ['GET'])]
    public function verify(int $id, Request $request): Response
    {
        $prospect = $this->prospects->find($id);
        if (!$prospect) {
            return new Response('Prospect introuvable.', 404);
        }

        // Critère de recherche : priorité au query param `q` (saisi
        // directement dans la modale). Aucun filtre auto par code postal :
        // le critère est utilisé tel quel.
        $q = trim((string) $request->query->get('q', ''));
        if ('' === $q) {
            $q = (string) ($prospect->getNom() ?? '');
        }
        $response = '' === $q ? ['results' => [], 'error' => null] : $this->annuaire->search($q);

        $rows = [];
        foreach ($response['results'] as $raw) {
            $rows[] = $this->annuaire->normalize($raw) + ['raw' => $raw];
        }

        $csrf = $this->container->get('security.csrf.token_manager')
            ->getToken('verify-prospect'.$id)->getValue();

        $redirect = $this->generateUrl('app_user_prospects_show', ['id' => $id]);

        return $this->render('prospects/_verify_modal.html.twig', [
            'prospect' => $prospect,
            'rows' => $rows,
            'apiError' => $response['error'],
            'csrf' => $csrf,
            'redirect' => $redirect,
            'applyUrl' => $this->generateUrl('app_user_prospects_verify_apply', ['id' => $id]),
            'searchUrl' => $this->generateUrl('app_user_prospects_verify', ['id' => $id]),
            'query' => $q,
        ]);
    }

    /**
     * POST /user/prospects/verify/{id}/apply
     * Applique les données de l'entreprise sélectionnée au prospect :
     *  - SIREN, SIRET, NAF, adresse, CP, ville, pays = FR
     *  - rcsRm + numTva calculés localement
     *  - latitude/longitude : depuis l'API si présents, sinon re-géocodage
     *
     * Réponse JSON :
     *   {ok, redirect} en cas de succès
     *   {ok:false, error} en cas d'erreur (CSRF, prospect introuvable, SIREN invalide)
     */
    #[Route('/verify/{id}/apply', name: 'app_user_prospects_verify_apply', methods: ['POST'])]
    public function verifyApply(int $id, Request $request): JsonResponse
    {
        $prospect = $this->prospects->find($id);
        if (!$prospect) {
            return new JsonResponse(['ok' => false, 'error' => 'Prospect introuvable.'], 404);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['ok' => false, 'error' => 'Body JSON invalide.'], 400);
        }

        $csrf = (string) ($body['csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('verify-prospect'.$id, $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        $siren = (string) preg_replace('/\D+/', '', (string) ($body['siren'] ?? ''));
        if (9 !== \strlen($siren)) {
            return new JsonResponse(['ok' => false, 'error' => 'SIREN invalide.'], 422);
        }

        // Re-cherche par SIREN exact côté serveur (jamais de confiance
        // aveugle au payload client).
        $row = $this->annuaire->searchBySiren($siren);
        if (null === $row) {
            return new JsonResponse(['ok' => false, 'error' => 'SIREN introuvable côté API.'], 422);
        }

        $this->applyAnnuaire($prospect, $row);
        $this->em->flush();

        $redirect = (string) ($body['redirect'] ?? $this->generateUrl('app_user_prospects_show', ['id' => $id]));

        return new JsonResponse(['ok' => true, 'redirect' => $redirect]);
    }

    /**
     * Page « Créer un prospect depuis l'Annuaire des Entreprises ».
     * Permet de chercher une entreprise par nom/SIREN, et de générer un
     * nouveau prospect avec ses données (sans modifier un prospect existant).
     */
    #[Route('/from-annuaire', name: 'app_user_prospects_from_annuaire', methods: ['GET'])]
    public function fromAnnuaire(Request $request): Response
    {
        $q = trim((string) $request->query->get('q', ''));
        $rows = [];
        $apiError = null;
        if ('' !== $q) {
            $response = $this->annuaire->search($q);
            $apiError = $response['error'];
            foreach ($response['results'] as $raw) {
                $rows[] = $this->annuaire->normalize($raw) + ['raw' => $raw];
            }
        }

        $csrf = $this->container->get('security.csrf.token_manager')
            ->getToken('from-annuaire-create')->getValue();

        return $this->renderLayout('prospects/from_annuaire.html.twig', 'Créer un prospect depuis l\'Annuaire des Entreprises', [
            'query' => $q,
            'rows' => $rows,
            'apiError' => $apiError,
            'csrf' => $csrf,
            'createUrl' => $this->generateUrl('app_user_prospects_from_annuaire_create'),
            'routeList' => 'app_user_prospects',
        ]);
    }

    /**
     * Crée un nouveau prospect à partir d'un résultat de l'Annuaire des
     * Entreprises (depuis la page dédiée /user/prospects/from-annuaire).
     * Body JSON : {siren, csrf_token}.
     *
     * Réponse JSON : {ok, redirect} vers la fiche update du nouveau prospect.
     */
    #[Route('/from-annuaire/create', name: 'app_user_prospects_from_annuaire_create', methods: ['POST'])]
    public function fromAnnuaireCreate(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['ok' => false, 'error' => 'Body JSON invalide.'], 400);
        }

        $csrf = (string) ($body['csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('from-annuaire-create', $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        $siren = (string) preg_replace('/\D+/', '', (string) ($body['siren'] ?? ''));
        if (9 !== \strlen($siren)) {
            return new JsonResponse(['ok' => false, 'error' => 'SIREN invalide.'], 422);
        }

        $row = $this->annuaire->searchBySiren($siren);
        if (null === $row) {
            return new JsonResponse(['ok' => false, 'error' => 'SIREN introuvable côté API.'], 422);
        }

        // Refuse la création si un prospect a déjà ce SIREN.
        $existant = $this->prospects->findOneBy(['siren' => $siren]);
        if (null !== $existant) {
            $existingId = $existant->getId();

            return new JsonResponse([
                'ok' => false,
                'error' => sprintf('Un prospect existe déjà avec ce SIREN (id %d).', $existingId ?? 0),
                'existingId' => $existingId,
                'existingRedirect' => $existingId ? $this->generateUrl('app_user_prospects_show', ['id' => $existingId]) : null,
            ], 409);
        }

        $prospect = new Prospect();
        $prospect->setNom((string) ($this->annuaire->normalize($row)['nom'] ?? ''));
        $prospect->setCleEntreprise('annuaire:'.$siren.':'.uniqid());
        $this->applyAnnuaire($prospect, $row);
        $this->em->persist($prospect);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'redirect' => $this->generateUrl('app_user_prospects_update', ['id' => $prospect->getId()]),
        ]);
    }

    /**
     * Écrase les champs du prospect avec les valeurs normalisées de l'API.
     * Recalcule lat/lon via API Adresse si l'API n'en a pas fourni.
     *
     * @param array<string, mixed> $row
     */
    private function applyAnnuaire(Prospect $prospect, array $row): void
    {
        $data = $this->annuaire->normalize($row);
        $prospect->setNom((string) ($data['nom'] ?? ''));
        $prospect->setSiren($data['siren']);
        $prospect->setSiret($data['siret']);
        $prospect->setNaf($data['naf']);
        $prospect->setAdresse($data['adresse']);
        $prospect->setCodePostal($data['code_postal']);
        $prospect->setVille($data['ville']);
        $prospect->setPays($data['pays']);
        $prospect->setRcsRm($this->annuaire->buildRcs($data['siren'], $data['dept']));
        $prospect->setNumTva($this->annuaire->computeTva($data['siren']));

        // Coordonnées GPS : privilégie l'API si fournies, sinon re-géocode.
        if (null !== $data['lat'] && null !== $data['lon']) {
            $prospect->setLatitude($data['lat']);
            $prospect->setLongitude($data['lon']);
        } else {
            $coords = $this->adresseApi->geocode(
                (string) ($data['adresse'] ?? ''),
                (string) ($data['code_postal'] ?? ''),
                (string) ($data['ville'] ?? ''),
            );
            if (null !== $coords) {
                $prospect->setLatitude($coords['lat']);
                $prospect->setLongitude($coords['lon']);
            }
        }
    }

    #[Route('/submit', name: 'app_user_prospects_submit')]
    public function submit(Request $request): Response
    {
        $prospect = new Prospect();
        $etapes = $this->etapesCible($request, $prospect);

        $form = $this->createForm(ProspectType::class, $prospect, ['mode' => 'submit', 'etapes' => $etapes, 'prospect_id' => 0]);
        $this->initialiserFormulaire($form, $prospect, $etapes);
        $redirect = $this->resolveRedirect($request);
        $form->get('redirect')->setData($redirect);
        $form->handleRequest($request);
        $this->appliquerContacteEtDate($form);
        if ($form->isSubmitted() && $form->isValid() && $this->appliquerVaguesEtPipeline($prospect, $form, $etapes)) {
            $prospect->setCleEntreprise('saisie:'.uniqid());
            $this->em->persist($prospect);
            $this->em->flush();

            // Après création, le comportement historique envoie vers la fiche
            // d'update pour finaliser (ajout d'étapes pipeline, etc.). Si un
            // redirect explicite est passé, on le respecte.
            if ('' !== trim((string) $request->query->get('redirect', ''))) {
                return $this->redirectTo($redirect, $prospect->getId());
            }

            return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospect->getId()]);
        }

        return $this->renderLayout('prospects/edit.html.twig', 'Nouveau prospect', [
            'routecancel' => 'app_user_prospects',
            'mode' => 'submit',
            'form' => $form,
            'etapes' => $etapes,
            'vagueCourante' => $prospect->vagueCourante(),
            'redirect' => $redirect,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_prospects_update')]
    public function update(int $id, Request $request): Response
    {
        $prospect = $this->prospects->find($id);
        if (!$prospect) {
            return $this->redirectToRoute('app_user_prospects');
        }

        $etapes = $this->etapesCible($request, $prospect);

        $form = $this->createForm(ProspectType::class, $prospect, ['mode' => 'update', 'etapes' => $etapes, 'prospect_id' => $prospect->getId()]);
        $this->initialiserFormulaire($form, $prospect, $etapes);
        $redirect = $this->resolveRedirect($request, $prospect);
        $form->get('redirect')->setData($redirect);
        $form->handleRequest($request);
        if ('' === $form->get('logo')->getData()) {
            $prospect->setLogo(null);
        }
        $this->appliquerContacteEtDate($form);
        $this->appliquerEmailDepuisContactPrincipal($prospect, $form);
        $this->appliquerTelephoneDepuisContactPrincipal($prospect, $form);
        $this->appliquerLinkedinDepuisContactPrincipal($prospect, $form);
        if ($form->isSubmitted() && $form->isValid() && $this->appliquerVaguesEtPipeline($prospect, $form, $etapes)) {
            $this->em->flush();

            return $this->redirectTo($redirect, $prospect->getId());
        }

        return $this->renderLayout('prospects/edit.html.twig', 'Modification = '.$prospect->getNom(), [
            'routecancel' => 'app_user_prospects',
            'routedelete' => 'app_user_prospects_delete',
            'routeshow' => 'app_user_prospects_show',
            'routeCibleDelete' => 'app_user_prospects_cibles_delete',
            'mode' => 'update',
            'form' => $form,
            'etapes' => $etapes,
            'prospect' => $prospect,
            'vagueCourante' => $prospect->vagueCourante(),
            'redirect' => $redirect,
            'formCibles' => $this->formCibles($prospect),
            'formAjoutCible' => $this->formAjoutCible($prospect),
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_prospects_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $prospect = $this->prospects->find($id);
        if (!$prospect) {
            return $this->redirectToRoute('app_user_prospects');
        }

        return $this->deleteEntity($request, $prospect, $id, 'delete-prospect', 'prospects', $this->em, [
            'list' => 'app_user_prospects',
            'update' => 'app_user_prospects_update',
        ]);
    }

    #[Route('/toggle-contacte/{id}', name: 'app_user_prospects_toggle_contacte', methods: ['POST'])]
    public function toggleContacte(int $id, Request $request): JsonResponse
    {
        $prospect = $this->prospects->find($id);
        if (!$prospect) {
            return new JsonResponse(['ok' => false, 'error' => 'Prospect introuvable.'], 404);
        }

        if (!$this->isCsrfTokenValid('toggle-contacte-prospect'.$id, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        // Bascule booléenne simple : null est représenté comme « Non contacté »
        // (false) au premier clic ; ensuite true ↔ false. Pas de cycle à 3
        // valeurs : un switch on/off ne distingue pas 3 états.
        $actuel = $prospect->getContacte();
        $prospect->setContacte(true === $actuel ? false : true);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'contacte' => $prospect->getContacte(),
            'datePremierContact' => $prospect->getDatePremierContact()?->format('Y-m-d'),
        ]);
    }

    /**
     * Libellé UI du booléen de qualification :
     *   null  → « Non »       (état initial, sans décision)
     *   true  → « Oui »       (lead qualifié)
     *   false → « Hors cible » (refusé par scoring)
     */
    private function qualifieLabel(?bool $valeur): string
    {
        return match ($valeur) {
            true => 'Oui',
            false => 'Hors cible',
            default => 'Non',
        };
    }

    private function qualifieColor(?bool $valeur): string
    {
        return match ($valeur) {
            true => 'success',
            false => 'danger',
            default => 'secondary',
        };
    }

    #[Route('/toggle-cible/{prospectId}/{cibleId}', name: 'app_user_prospects_toggle_cible', methods: ['POST'])]
    public function toggleCible(int $prospectId, int $cibleId, Request $request): JsonResponse
    {
        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            return new JsonResponse(['ok' => false, 'error' => 'Prospect introuvable.'], 404);
        }

        $cible = $this->cibles->find($cibleId);
        if (!$cible) {
            return new JsonResponse(['ok' => false, 'error' => 'Cible introuvable.'], 404);
        }

        if (!$this->isCsrfTokenValid('toggle-cible-prospect-'.$prospectId.'-'.$cibleId, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        $prospectCible = $this->prospectCibles->findByProspectAndCible($prospectId, $cibleId);
        if (!$prospectCible) {
            $prospectCible = new \App\Entity\ProspectCible();
            $prospectCible->setProspect($prospect);
            $prospectCible->setCible($cible);
            $prospectCible->setQualifie(true);
            $this->em->persist($prospectCible);
        } else {
            $actuel = $prospectCible->getQualifie();
            if (null === $actuel) {
                $prospectCible->setQualifie(true);
            } elseif (true === $actuel) {
                $prospectCible->setQualifie(false);
            } else {
                $prospectCible->setQualifie(null);
            }
        }
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'qualifie' => $prospectCible->getQualifie(),
            'label' => $this->qualifieLabel($prospectCible->getQualifie()),
            'color' => $this->qualifieColor($prospectCible->getQualifie()),
        ]);
    }

    #[Route('/add-cible/{prospectId}/{cibleId}', name: 'app_user_prospects_add_cible', methods: ['POST'])]
    public function addCible(int $prospectId, int $cibleId, Request $request): JsonResponse
    {
        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            return new JsonResponse(['ok' => false, 'error' => 'Prospect introuvable.'], 404);
        }

        $cible = $this->cibles->find($cibleId);
        if (!$cible) {
            return new JsonResponse(['ok' => false, 'error' => 'Cible introuvable.'], 404);
        }

        if (!$this->isCsrfTokenValid('add-cible-prospect-'.$prospectId.'-'.$cibleId, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        $prospectCible = $this->prospectCibles->findByProspectAndCible($prospectId, $cibleId);
        if ($prospectCible) {
            return new JsonResponse(['ok' => false, 'error' => 'Cible déjà liée à ce prospect.'], 409);
        }

        $prospectCible = new \App\Entity\ProspectCible();
        $prospectCible->setProspect($prospect);
        $prospectCible->setCible($cible);
        $prospectCible->setQualifie(null);
        $this->em->persist($prospectCible);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'qualifie' => $prospectCible->getQualifie(),
            'label' => $this->qualifieLabel($prospectCible->getQualifie()),
            'color' => $this->qualifieColor($prospectCible->getQualifie()),
            'cibleId' => $cibleId,
            'cibleNom' => $cible->getTitre(),
        ]);
    }

    #[Route('/cycle-etape', name: 'app_user_prospects_cycle_etape', methods: ['POST'])]
    public function cycleEtape(Request $request): JsonResponse
    {
        $prospectId = (int) $request->request->get('prospect', 0);
        $etapeId = (int) $request->request->get('etape', 0);

        if (!$this->isCsrfTokenValid('cycle-etape-prospect-'.$prospectId, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        $prospect = $this->prospects->find($prospectId);
        if (null === $prospect) {
            return new JsonResponse(['ok' => false, 'error' => 'Prospect introuvable.'], 404);
        }

        $lien = $prospect->vagueCourante();
        if (null === $lien) {
            return new JsonResponse(['ok' => false, 'error' => 'Aucune vague courante.'], 409);
        }

        $etape = null;
        foreach ($lien->getSprint()?->getPipeline()?->getEtapes() ?? [] as $e) {
            if ($e->getId() === $etapeId) {
                $etape = $e;
                break;
            }
        }
        if (null === $etape) {
            return new JsonResponse(['ok' => false, 'error' => 'Étape hors pipeline.'], 404);
        }

        $actuel = $lien->statutPour($etape);
        $suivant = PipelineStatut::suivant($actuel);
        $lien->definirStatut($etape, $suivant);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'statut' => $suivant,
            'label' => PipelineStatut::label($suivant),
            'color' => $this->pipelineColor($suivant),
            'date' => $lien->datePour($etape)?->format('d/m/Y'),
        ]);
    }

    private function pipelineColor(int $valeur): string
    {
        return match ($valeur) {
            PipelineStatut::OUI => 'success',
            PipelineStatut::RELANCER => 'warning',
            PipelineStatut::NON => 'danger',
            PipelineStatut::EN_ATTENTE => 'secondary',
            PipelineStatut::A_QUALIFIER => 'info',
            default => 'light',
        };
    }

    /**
     * Étapes du pipeline cible : celles de la vague sélectionnée dans la
     * requête (saisie) ou, à défaut, celles de la vague courante du prospect.
     * À défaut de vague du tout, on retombe sur le pipeline par défaut si la
     * requête porte des champs pipeline (permet d'afficher le message
     * « sélectionnez au moins une vague »). Aucune vague => aucun champ.
     *
     * @return array<int, PipelineEtape>
     */
    private function etapesCible(Request $request, Prospect $prospect): array
    {
        $selection = $this->selectionSprints($request);
        $pipeline = null;

        if (null !== $selection && [] !== $selection) {
            $plusHaute = null;
            foreach ($selection as $idSprint) {
                $sprint = $this->sprints->find($idSprint);
                if (null === $sprint) {
                    continue;
                }
                if (null === $plusHaute || (int) $sprint->getNumero() > (int) $plusHaute->getNumero()) {
                    $plusHaute = $sprint;
                }
            }
            $pipeline = $plusHaute?->getPipeline() ?? $this->pipelines->getDefault();
        }

        if (null === $pipeline) {
            $lien = $prospect->vagueCourante();
            $pipeline = null !== $lien ? ($lien->getSprint()?->getPipeline() ?? $this->pipelines->getDefault()) : null;
        }

        if (null === $pipeline && $this->saisiePipeline($request)) {
            $pipeline = $this->pipelines->getDefault();
        }

        return null === $pipeline ? [] : $pipeline->getEtapes()->toArray();
    }

    /**
     * La requête porte-t-elle des champs de pipeline (statut ou date) ?
     */
    private function saisiePipeline(Request $request): bool
    {
        if (!$request->isMethod('POST')) {
            return false;
        }

        $donnees = $request->request->all();
        if (!isset($donnees['prospect']) || !is_array($donnees['prospect'])) {
            return false;
        }

        foreach (array_keys($donnees['prospect']) as $champ) {
            if (preg_match('/^(etape|dateEtape)\d+$/', (string) $champ)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Identifiants de vagues soumis dans le formulaire (null si la requête
     * ne porte pas la saisie du formulaire prospect).
     *
     * @return array<int>|null
     */
    private function selectionSprints(Request $request): ?array
    {
        if (!$request->isMethod('POST')) {
            return null;
        }

        $donnees = $request->request->all();
        if (!isset($donnees['prospect']) || !is_array($donnees['prospect'])) {
            return null;
        }

        $ids = [];
        $brut = $donnees['prospect']['sprints'] ?? null;
        if (is_iterable($brut)) {
            foreach ($brut as $id) {
                $entier = filter_var($id, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
                if (null !== $entier) {
                    $ids[] = $entier;
                }
            }
        }

        return $ids;
    }

    /**
     * Valeurs initiales du formulaire : vagues sélectionnées et statuts du
     * pipeline de la vague courante (champs non mappés sur Prospect).
     *
     * @param FormInterface<Prospect>   $form
     * @param array<int, PipelineEtape> $etapes
     */
    private function initialiserFormulaire(FormInterface $form, Prospect $prospect, array $etapes): void
    {
        $sprints = [];
        foreach ($prospect->getSprints() as $lien) {
            if (null !== $lien->getSprint()) {
                $sprints[] = $lien->getSprint();
            }
        }
        $form->get('sprints')->setData($sprints);

        $courant = $prospect->vagueCourante();
        foreach ($etapes as $etape) {
            $form->get('etape'.$etape->getId())->setData(null !== $courant ? $courant->statutPour($etape) : PipelineStatut::NON_DEMARRE);
            $form->get('dateEtape'.$etape->getId())->setData(null !== $courant ? $courant->datePour($etape) : null);
        }
    }

    /**
     * Applique la sélection de vagues puis les valeurs du pipeline sur la
     * vague courante. Renvoie false (avec une erreur de formulaire) si un
     * statut est renseigné alors qu'aucune vague n'est sélectionnée.
     *
     * @param FormInterface<Prospect>   $form
     * @param array<int, PipelineEtape> $etapes
     */
    private function appliquerVaguesEtPipeline(Prospect $prospect, FormInterface $form, array $etapes): bool
    {
        $this->synchroniserVagues($prospect, $form->get('sprints')->getData());

        $renseigne = false;
        foreach ($etapes as $etape) {
            $statut = (int) $form->get('etape'.$etape->getId())->getData();
            $date = $form->get('dateEtape'.$etape->getId())->getData();
            $renseigne = $renseigne || $statut > PipelineStatut::NON_DEMARRE || null !== $date;
        }

        $lien = $prospect->vagueCourante();
        if (null === $lien) {
            if ($renseigne && [] !== $etapes) {
                $form->get('etape'.$etapes[0]->getId())->addError(new FormError('Sélectionnez au moins une vague de traitement pour renseigner le pipeline.'));
            }

            return !$renseigne;
        }

        // Garde-fou : les champs ont été construits pour le pipeline de la
        // vague soumise ; s'il diffère de celle retenue, on n'écrit rien.
        $pipelineLien = $lien->getSprint()?->getPipeline();
        if (null !== $pipelineLien && [] !== $etapes && null !== $etapes[0]->getPipeline() && $pipelineLien->getId() !== $etapes[0]->getPipeline()->getId()) {
            return true;
        }

        foreach ($etapes as $etape) {
            $statut = (int) $form->get('etape'.$etape->getId())->getData();
            $date = $form->get('dateEtape'.$etape->getId())->getData();
            $lien->definirValeur($etape, $statut, $date);
        }

        return true;
    }

    /**
     * Recrée ou retire les liens prospect↔vague selon la sélection du formulaire
     * (orphanRemoval de la collection s'occupe des suppressions au flush).
     */
    private function synchroniserVagues(Prospect $prospect, mixed $selection): void
    {
        $choisies = [];
        if (is_iterable($selection)) {
            foreach ($selection as $sprint) {
                if ($sprint instanceof Sprint && null !== $sprint->getId()) {
                    $choisies[(int) $sprint->getId()] = $sprint;
                }
            }
        }

        foreach ($prospect->getSprints()->toArray() as $lien) {
            $idSprint = $lien->getSprint()?->getId();
            if (null === $idSprint || !isset($choisies[(int) $idSprint])) {
                $prospect->removeSprint($lien);

                continue;
            }
            unset($choisies[(int) $idSprint]);
        }

        foreach ($choisies as $sprint) {
            $lien = new ProspectSprint();
            $lien->setSprint($sprint);
            $prospect->addSprint($lien);
        }
    }

    /**
     * @return array{
     *     campagne: int|null,
     *     sprint: int|null,
     *     userId: int|null,
     *     contacte: string|null,
     *     cible: int|null,
     *     qualifie: string|null,
     *     recherche: string
     * }
     */
    private function lireFiltres(Request $request): array
    {
        $contacte = trim((string) $request->query->get('contacte', ''));
        if (!\in_array($contacte, ['', 'oui', 'non'], true)) {
            $contacte = '';
        }

        $qualifie = trim((string) $request->query->get('qualifie', ''));
        if (!\in_array($qualifie, ['', 'oui', 'non', 'horscible'], true)) {
            $qualifie = '';
        }

        return [
            'campagne' => $this->paramEntier($request, 'campagne'),
            'sprint' => $this->paramEntier($request, 'sprint'),
            'userId' => $this->paramEntier($request, 'utilisateur'),
            'contacte' => '' === $contacte ? null : $contacte,
            'cible' => $this->paramEntier($request, 'cible'),
            'qualifie' => '' === $qualifie ? null : $qualifie,
            'recherche' => $this->paramTexte($request, 'q'),
        ];
    }

    /**
     * Entier de requête tolérant aux valeurs vides (« Toutes » = '') ou non
     * numériques : renvoie null au lieu de lever une exception.
     */
    private function paramEntier(Request $request, string $nom): ?int
    {
        $valeur = $this->paramTexte($request, $nom);
        if ('' === $valeur) {
            return null;
        }

        $entier = filter_var($valeur, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);

        return null === $entier ? null : $entier;
    }

    private function paramTexte(Request $request, string $nom): string
    {
        $valeur = $request->query->all()[$nom] ?? null;
        if (!is_scalar($valeur)) {
            return '';
        }

        return trim((string) $valeur);
    }

    /**
     * Détermine l'URL de retour (query param redirect, ou fallback liste).
     */
    private function resolveRedirect(Request $request, ?Prospect $prospect = null): string
    {
        $requested = trim((string) $request->query->get('redirect', ''));
        if ('' !== $requested) {
            return $requested;
        }

        return $this->generateUrl('app_user_prospects');
    }

    /**
     * Redirige vers l'URL stockée. Pour un prospect venant d'être créé, ?id=X
     * dans l'URL est remplacé par l'id réel (cas submit depuis la fiche d'un
     * autre prospect, peu probable, mais sécurisé).
     */
    private function redirectTo(string $url, ?int $prospectId): RedirectResponse
    {
        if ('' === $url) {
            $url = $this->generateUrl('app_user_prospects');
        }
        // Refuse tout chemin non-interne (sécurité open-redirect).
        if (!str_starts_with($url, '/') && !str_starts_with($url, 'http')) {
            $url = $this->generateUrl('app_user_prospects');
        }

        return new RedirectResponse($url);
    }

    /**
     * Synchronise datePremierContact avec contacte lors de la soumission :
     *  - contacte = true  + date vide → pose aujourd'hui
     *  - contacte = false              → vide la date
     * (Le setter entité fait la même chose via setContacte ; mais ici, on
     * agit directement sur les valeurs du formulaire sans repasser par le
     * setter, pour préserver une date explicitement saisie par l'utilisateur.)
     */
    /**
     * Synchronise datePremierContact avec contacte lors de la soumission :
     *  - contacte = true  + date vide → pose aujourd'hui
     *  - contacte = false              → vide la date
     *
     * @param FormInterface<Prospect> $form
     */
    private function appliquerContacteEtDate(FormInterface $form): void
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return;
        }

        $contacte = $form->get('contacte')->getData();
        $date = $form->get('datePremierContact')->getData();
        $data = $form->getData();
        if (true === $contacte && null === $date) {
            $data->setDatePremierContact(new \DateTime());
        } elseif (false === $contacte) {
            $data->setDatePremierContact(null);
        }
    }

    /**
     * Initialise l'email du prospect avec celui du contact principal si le champ
     * est vide. Concerne uniquement l'update : à la création, le prospect n'a
     * pas encore de contacts.
     *
     * @param FormInterface<Prospect> $form
     */
    private function appliquerEmailDepuisContactPrincipal(Prospect $prospect, FormInterface $form): void
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return;
        }

        $data = $form->getData();
        if (null !== $data->getEmail() && '' !== trim($data->getEmail())) {
            return;
        }

        $principal = $prospect->getContactPrincipal();
        if (null !== $principal && null !== $principal->getEmail() && '' !== trim((string) $principal->getEmail())) {
            $data->setEmail($principal->getEmail());
        }
    }

    /**
     * Initialise le téléphone du prospect avec celui du contact principal
     * (préférence : `telephone` formaté, à défaut `telephoneBrut`) si le champ
     * est vide.
     *
     * @param FormInterface<Prospect> $form
     */
    private function appliquerTelephoneDepuisContactPrincipal(Prospect $prospect, FormInterface $form): void
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return;
        }

        $data = $form->getData();
        if (null !== $data->getTelephone() && '' !== trim($data->getTelephone())) {
            return;
        }

        $principal = $prospect->getContactPrincipal();
        if (null === $principal) {
            return;
        }

        $tel = $principal->getTelephone() ?: $principal->getTelephoneBrut();
        if (null !== $tel && '' !== trim($tel)) {
            $data->setTelephone($tel);
        }
    }

    /**
     * Initialise le LinkedIn du prospect avec celui du contact principal si
     * le champ est vide.
     *
     * @param FormInterface<Prospect> $form
     */
    private function appliquerLinkedinDepuisContactPrincipal(Prospect $prospect, FormInterface $form): void
    {
        if (!$form->isSubmitted() || !$form->isValid()) {
            return;
        }

        $data = $form->getData();
        if (null !== $data->getLinkedinUrl() && '' !== trim($data->getLinkedinUrl())) {
            return;
        }

        $principal = $prospect->getContactPrincipal();
        if (null === $principal) {
            return;
        }

        $url = $principal->getLinkedinUrl();
        if (null !== $url && '' !== trim($url)) {
            $data->setLinkedinUrl($url);
        }
    }

    #[Route('/{prospectId}/cibles/submit', name: 'app_user_prospects_cibles_submit', methods: ['POST'])]
    public function cibleSubmit(int $prospectId, Request $request): Response
    {
        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
        }

        $prospectCible = new \App\Entity\ProspectCible();
        $prospectCible->setProspect($prospect);

        $form = $this->createForm(\App\Form\ProspectCibleType::class, $prospectCible);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($prospect->getProspectCibles() as $existing) {
                if ($existing->getCible()?->getId() === $prospectCible->getCible()?->getId()) {
                    $this->addFlash('error', 'Cette cible est déjà associée.');

                    return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
                }
            }

            $this->em->persist($prospectCible);
            $this->em->flush();

            return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
        }

        return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
    }

    #[Route('/{prospectId}/cibles/update/{linkId}', name: 'app_user_prospects_cibles_update', methods: ['POST'])]
    public function cibleUpdate(int $prospectId, int $linkId, Request $request): Response
    {
        $prospect = $this->prospects->find($prospectId);
        if (!$prospect) {
            return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
        }

        $prospectCible = $this->em->find(\App\Entity\ProspectCible::class, $linkId);
        if (!$prospectCible) {
            return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
        }

        $form = $this->createForm(\App\Form\ProspectCibleType::class, $prospectCible);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
        }

        return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
    }

    #[Route('/{prospectId}/cibles/delete/{linkId}', name: 'app_user_prospects_cibles_delete', methods: ['POST'])]
    public function cibleDelete(int $prospectId, int $linkId, Request $request): Response
    {
        $prospectCible = $this->em->find(\App\Entity\ProspectCible::class, $linkId);
        if (!$prospectCible) {
            return $this->redirectToRoute('app_user_prospects_update', ['id' => $prospectId]);
        }

        return $this->deleteEntity($request, $prospectCible, $linkId, 'prospect-cible', 'cible', $this->em, [
            'list' => 'app_user_prospects_update',
            'update' => 'app_user_prospects_cibles_update',
            'successRoute' => 'app_user_prospects_update',
            'successRouteParams' => ['id' => $prospectId],
            'redirectParams' => ['id' => $prospectId],
        ]);
    }

    /**
     * @return array<int, FormView>
     */
    private function formCibles(Prospect $prospect, array $formCiblesSoumis = []): array
    {
        $formCibles = [];
        foreach ($prospect->getProspectCibles() as $prospectCible) {
            $linkId = (int) $prospectCible->getId();
            $cibleId = $prospectCible->getCible()?->getId();
            $formCibles[$linkId] = ($formCiblesSoumis[$linkId] ?? $this->createForm(\App\Form\ProspectCibleType::class, $prospectCible, [
                'action' => $this->generateUrl('app_user_prospects_cibles_update', ['prospectId' => $prospect->getId(), 'linkId' => $linkId]),
                'prospect' => $prospect,
                'excluded_cible_id' => $cibleId,
            ]))->createView();
        }

        return $formCibles;
    }

    private function formAjoutCible(Prospect $prospect): FormView
    {
        $prospectCible = new \App\Entity\ProspectCible();
        $prospectCible->setProspect($prospect);

        return $this->createForm(\App\Form\ProspectCibleType::class, $prospectCible, [
            'action' => $this->generateUrl('app_user_prospects_cibles_submit', ['prospectId' => $prospect->getId()]),
            'prospect' => $prospect,
        ])->createView();
    }
}
