<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Action;
use App\Entity\User;
use App\Form\ActionType;
use App\Form\BulkActionType;
use App\Repository\ActionRepository;
use App\Repository\ActionTypeDefautRepository;
use App\Repository\ContactRepository;
use App\Repository\ProspectRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/user/actions')]
class ActionController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private ActionRepository $actions,
        private ProspectRepository $prospects,
        private ContactRepository $contacts,
        private ActionTypeDefautRepository $types,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_actions')]
    public function list(Request $request): Response
    {
        $vue = (string) $request->query->get('vue', 'avenir');

        return $this->renderLayout('actions/list.html.twig', 'Actions', [
            'routesubmit' => 'app_user_actions_submit',
            'routeupdate' => 'app_user_actions_update',
            'routeprospect' => 'app_user_prospects_show',
            'vue' => $vue,
            'compteurRetard' => $this->actions->countDue(),
            'compteurUpcoming' => $this->actions->countUpcoming(),
            'compteurDone' => $this->actions->countDone(),
            'retard' => $this->actions->findDue(),
            'avenir' => $this->actions->findUpcoming(),
            'faites' => $this->actions->findDone(),
            'typesSuggestions' => $this->types->findActifsOrdonnes(),
        ]);
    }

    #[Route('/submit', name: 'app_user_actions_submit')]
    public function submit(Request $request, #[CurrentUser] ?User $user = null): Response
    {
        $action = new Action();

        $prospectId = $request->query->getInt('prospect');
        if (0 !== $prospectId) {
            $prospect = $this->prospects->find($prospectId);
            if ($prospect) {
                $action->setProspect($prospect);
            }
        }

        // Init via ?date=YYYY-MM-DD (clic sur une date de l'agenda).
        $dateStr = (string) $request->query->get('date', '');
        if ('' !== $dateStr) {
            try {
                $action->setDatePrevue(new \DateTime($dateStr));
            } catch (\Exception) {
                // Date invalide : on ignore, le user devra la saisir à la main.
            }
        }

        // Init par défaut : affecté à l'utilisateur courant (type laissé vide).
        if (null !== $user && null === $action->getAFairePar()) {
            $action->setAFairePar($user);
        }

        $form = $this->createForm(ActionType::class, $action, ['prospectFiltre' => null !== $action->getProspect() ? $action->getProspect()->getId() : null]);
        $redirect = $this->resolveRedirect($request);
        $form->get('redirect')->setData($redirect);
        $form->handleRequest($request);
        if ($form->isSubmitted()) {
            if (null !== $user && null === $action->getAFairePar()) {
                $action->setAFairePar($user);
            }
        }
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($action);
            $this->em->flush();

            return $this->redirectTo($redirect);
        }

        return $this->renderLayout('actions/edit.html.twig', 'Nouvelle action', [
            'mode' => 'submit',
            'form' => $form,
            'typesSuggestions' => $this->types->findActifsOrdonnes(),
            'redirect' => $redirect,
        ]);
    }

    #[Route('/bulk', name: 'app_user_actions_bulk')]
    public function bulk(Request $request): Response
    {
        $sprints = $this->prospects->findAllSprintsWithProspects();
        $allProspects = $this->prospects->findAllWithSprint();

        return $this->renderLayout('actions/bulk.html.twig', 'Actions en masse', [
            'sprints' => $sprints,
            'allProspects' => $allProspects,
            'typesSuggestions' => $this->types->findActifsOrdonnes(),
        ]);
    }

    #[Route('/bulk/submit', name: 'app_user_actions_bulk_submit', methods: ['POST'])]
    public function bulkSubmit(Request $request, #[CurrentUser] ?User $user = null): Response
    {
        $json = $request->request->get('prospects_data', '{}');
        $prospectsData = json_decode($json, true) ?: [];

        if (empty($prospectsData)) {
            $this->addFlash('error', 'Aucun prospect sélectionné.');

            return $this->redirectToRoute('app_user_actions_bulk');
        }

        $count = 0;
        foreach ($prospectsData as $prospectId => $data) {
            $prospect = $this->prospects->find((int) $prospectId);
            if (!$prospect) {
                continue;
            }

            $action = new Action();
            $action->setProspect($prospect);
            $action->setDatePrevue(new \DateTime($data['date']));
            $action->setTypeAction($data['type']);
            if (null !== $user) {
                $action->setAFairePar($user);
            }
            $this->em->persist($action);
            $count++;
        }

        $this->em->flush();
        $this->addFlash('success', $count . ' action(s) créée(s).');

        return $this->redirectToRoute('app_user_actions');
    }

    #[Route('/update/{id}', name: 'app_user_actions_update')]
    public function update(int $id, Request $request): Response
    {
        $action = $this->actions->find($id);
        if (!$action) {
            return $this->redirectToRoute('app_user_actions');
        }

        $form = $this->createForm(ActionType::class, $action, ['prospectFiltre' => null !== $action->getProspect() ? $action->getProspect()->getId() : null]);
        $redirect = $this->resolveRedirect($request, $action);
        $form->get('redirect')->setData($redirect);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            // Si le redirect cible l'agenda, on régénère l'URL avec la date
            // actuelle de l'action (mise à jour si l'user a modifié datePrevue
            // ou dateRealisee pendant la soumission). Sans cela, le retour
            // pointerait sur l'ancienne date.
            if (str_contains($redirect, '/user/agenda')) {
                $dateCible = $action->getDatePrevue() ?? $action->getDateRealisee();
                if (null !== $dateCible) {
                    $redirect = $this->generateUrl('app_user_agenda', ['date' => $dateCible->format('Y-m-d')]);
                }
            }

            return $this->redirectTo($redirect);
        }

        return $this->renderLayout('actions/edit.html.twig', 'Modification de l\'action', [
            'routedelete' => 'app_user_actions_delete',
            'mode' => 'update',
            'form' => $form,
            'action' => $action,
            'typesSuggestions' => $this->types->findActifsOrdonnes(),
            'redirect' => $redirect,
        ]);
    }

    #[Route('/contacts', name: 'app_user_actions_contacts', methods: ['GET'])]
    public function contactsPourProspect(Request $request): JsonResponse
    {
        $prospectId = $request->query->getInt('prospect');
        if (0 === $prospectId) {
            return new JsonResponse([]);
        }

        $items = [];
        foreach ($this->contacts->findByProspect($prospectId) as $contact) {
            $items[] = [
                'id' => $contact->getId(),
                'text' => $contact->getNomComplet(),
            ];
        }

        return new JsonResponse($items);
    }

    /**
     * Endpoint AJAX pour déplacer une action dans l'agenda. Met à jour
     * `datePrevue` (et `dateRealisee` si l'action est déjà clôturée : la
     * date glissée devient la date de réalisation effective).
     *
     * Le body est JSON : `{"date":"YYYY-MM-DD","csrf_token":"..."}`.
     * Réponse JSON : `{ok,date}` ou `{ok:false,error}`.
     */
    #[Route('/set-date/{id}', name: 'app_user_actions_set_date', methods: ['POST'])]
    public function setDate(int $id, Request $request): JsonResponse
    {
        $action = $this->actions->find($id);
        if (!$action) {
            return new JsonResponse(['ok' => false, 'error' => 'Action introuvable.'], 404);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!\is_array($body)) {
            return new JsonResponse(['ok' => false, 'error' => 'Body JSON invalide.'], 400);
        }

        $csrf = (string) ($body['csrf_token'] ?? '');
        if (!$this->isCsrfTokenValid('set-date-action'.$id, $csrf)) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        $dateStr = (string) ($body['date'] ?? '');
        try {
            $date = new \DateTime($dateStr);
        } catch (\Exception) {
            return new JsonResponse(['ok' => false, 'error' => 'Date invalide.'], 422);
        }

        if (null !== $action->getDateRealisee()) {
            // Action déjà clôturée : la date glissée devient la date de
            // réalisation effective (déplacer dans le calendrier réajuste
            // l'historique).
            $action->setDateRealisee($date);
        } else {
            $action->setDatePrevue($date);
        }
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'date' => $date->format('Y-m-d')]);
    }

    #[Route('/delete/{id}', name: 'app_user_actions_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $action = $this->actions->find($id);
        if (!$action) {
            return $this->redirectToRoute('app_user_actions');
        }

        $redirect = $this->resolveRedirect($request, $action);

        if (!$this->isCsrfTokenValid('delete-action'.$id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide — suppression non effectuée.');

            return $this->redirectTo($redirect);
        }

        try {
            $this->em->remove($action);
            $this->em->flush();
        } catch (\Exception $e) {
            $this->addFlash('error', 'Suppression impossible : erreur technique.');

            return $this->redirectTo($redirect);
        }

        return $this->redirectTo($redirect);
    }

    #[Route('/cloturer/{id}', name: 'app_user_actions_cloturer', methods: ['POST'])]
    public function cloturer(int $id, Request $request, #[CurrentUser] ?User $user = null): JsonResponse
    {
        $action = $this->actions->find($id);
        if (!$action) {
            return new JsonResponse(['ok' => false, 'error' => 'Action introuvable.'], 404);
        }

        if (!$this->isCsrfTokenValid('cloturer-action'.$id, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        if ($action->estRealisee()) {
            return new JsonResponse(['ok' => false, 'error' => 'Cette action est déjà clôturée.'], 409);
        }

        $resultat = $request->request->get('resultat');
        $dateRealiseeStr = (string) $request->request->get('dateRealisee', '');
        $dateSuivanteStr = (string) $request->request->get('datePrevueSuivante', '');
        $typeSuivant = (string) $request->request->get('typeSuivant', '');
        $realiseParId = $request->request->get('realisePar');

        $dateRealisee = '' === $dateRealiseeStr ? new \DateTime() : new \DateTime($dateRealiseeStr);
        $action->setDateRealisee($dateRealisee);
        if (null !== $resultat && '' !== trim((string) $resultat)) {
            $action->setResultat((string) $resultat);
        }
        // « Réalisé par » : user courant par défaut, surchargeable via le formulaire.
        if (null !== $realiseParId && '' !== $realiseParId) {
            $realiseur = $this->em->find(User::class, (int) $realiseParId);
            if ($realiseur instanceof User) {
                $action->setRealisePar($realiseur);
            }
        } elseif (null !== $user) {
            $action->setRealisePar($user);
        }

        $planifiee = false;
        if ('' !== $dateSuivanteStr) {
            // Si une date de prochaine action est saisie, le type est obligatoire.
            if ('' === $typeSuivant) {
                return new JsonResponse([
                    'ok' => false,
                    'error' => 'Renseignez un type pour la prochaine action.',
                ], 422);
            }
            $suivante = new Action();
            $suivante->setProspect($action->getProspect());
            $suivante->setContact($action->getContact());
            $suivante->setDatePrevue(new \DateTime($dateSuivanteStr));
            $suivante->setTypeAction($typeSuivant);
            // L'utilisateur courant hérite de l'affectation par défaut.
            if (null !== $user) {
                $suivante->setAFairePar($user);
            }
            $this->em->persist($suivante);
            $planifiee = true;
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'planifiee' => $planifiee,
            'compteurs' => $this->compteurs(),
        ]);
    }

    #[Route('/decloturer/{id}', name: 'app_user_actions_decloturer', methods: ['POST'])]
    public function decloturer(int $id, Request $request): JsonResponse
    {
        $action = $this->actions->find($id);
        if (!$action) {
            return new JsonResponse(['ok' => false, 'error' => 'Action introuvable.'], 404);
        }

        if (!$this->isCsrfTokenValid('decloturer-action'.$id, (string) $request->request->get('_csrf_token'))) {
            return new JsonResponse(['ok' => false, 'error' => 'Token CSRF invalide.'], 403);
        }

        if (!$action->estRealisee()) {
            return new JsonResponse(['ok' => false, 'error' => 'Cette action n\'est pas clôturée.'], 409);
        }

        $action->setDateRealisee(null);
        $action->setRealisePar(null);
        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'compteurs' => $this->compteurs(),
        ]);
    }

    /**
     * @return array{retard:int,avenir:int,faites:int}
     */
    private function compteurs(): array
    {
        return [
            'retard' => $this->actions->countDue(),
            'avenir' => $this->actions->countUpcoming(),
            'faites' => $this->actions->countDone(),
        ];
    }

    /**
     * Détermine l'URL de retour :
     *  - priorité au query param `redirect` (URL absolue ou chemin interne),
     *  - sinon, si on a une action liée à un prospect → fiche prospect,
     *  - sinon, liste des actions.
     */
    private function resolveRedirect(Request $request, ?Action $action = null): string
    {
        $requested = trim((string) ($request->query->get('redirect') ?? $request->request->get('redirect', '')));
        if ('' === $requested && null !== $action && null !== $action->getProspect()) {
            $requested = $this->generateUrl('app_user_prospects_show', ['id' => $action->getProspect()->getId()]);
        }
        if ('' === $requested) {
            $requested = $this->generateUrl('app_user_actions');
        }

        return $requested;
    }

    /**
     * Redirige vers l'URL stockée (query param `redirect` ou fiche prospect).
     * Refuse tout chemin non-interne (sécurité open-redirect).
     */
    private function redirectTo(string $url): RedirectResponse
    {
        if ('' === $url || (!str_starts_with($url, '/') && !str_starts_with($url, 'http'))) {
            $url = $this->generateUrl('app_user_actions');
        }

        return new RedirectResponse($url);
    }
}
