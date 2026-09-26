<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Action;
use App\Repository\ActionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/agenda')]
class AgendaController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(private ActionRepository $actions)
    {
    }

    #[Route('', name: 'app_user_agenda')]
    public function index(): Response
    {
        return $this->renderLayout('agenda/index.html.twig', 'Agenda', []);
    }

    /**
     * Endpoint JSON consommé par FullCalendar via fetch.
     * Query params `start` / `end` au format ISO (YYYY-MM-DD).
     */
    #[Route('/events', name: 'app_user_agenda_events', methods: ['GET'])]
    public function events(Request $request): JsonResponse
    {
        try {
            $start = new \DateTime((string) $request->query->get('start', date('Y-m-01')));
            $end = new \DateTime((string) $request->query->get('end', date('Y-m-t')));
        } catch (\Exception) {
            return new JsonResponse(['error' => 'Paramètres start/end invalides.'], 400);
        }

        $events = [];
        foreach ($this->actions->findForAgendaRange($start, $end) as $action) {
            $events[] = $this->actionToEvent($action);
        }

        return new JsonResponse($events);
    }

    /**
     * Renvoie le token CSRF pour déplacer une action dans l'agenda.
     * Le token est lié à la session, donc il faut une session active.
     */
    #[Route('/csrf/{id}', name: 'app_user_agenda_csrf', methods: ['GET'])]
    public function csrf(int $id): JsonResponse
    {
        $action = $this->actions->find($id);
        if (!$action) {
            return new JsonResponse(['ok' => false, 'error' => 'Action introuvable.'], 404);
        }

        return new JsonResponse([
            'token' => $this->container->get('security.csrf.token_manager')
                ->getToken('set-date-action'.$id)->getValue(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function actionToEvent(Action $a): array
    {
        $realisee = null !== $a->getDateRealisee();
        // Date affichée dans l'agenda : priorité à la date prévue (modifiée
        // par l'utilisateur), sinon la date de réalisation.
        $date = $a->getDatePrevue() ?? $a->getDateRealisee();
        $couleur = $realisee ? '#198754' : '#fd7e14'; // vert si faite, orange si à faire

        $titre = ($a->getTypeAction() ?: 'Action');
        if ($prospect = $a->getProspect()) {
            $titre .= ' — '.$prospect->getNom();
        }

        // Redirection vers l'agenda centrée sur la date de l'action : quand
        // l'utilisateur modifie l'action, il revient au bon endroit de
        // l'agenda même s'il a changé la date.
        $redirectParams = [];
        if (null !== $date) {
            $redirectParams['date'] = $date->format('Y-m-d');
        }

        return [
            'id' => $a->getId(),
            'title' => $titre,
            'start' => $date?->format('Y-m-d'),
            'allDay' => true,
            'color' => $couleur,
            'url' => $this->generateUrl('app_user_actions_update', [
                'id' => $a->getId(),
                'redirect' => $this->generateUrl('app_user_agenda', $redirectParams),
            ]),
            'extendedProps' => [
                'contact' => $a->getContact()?->getNomComplet(),
                'aFairePar' => $a->getAFairePar()?->getUsername(),
                'realisePar' => $a->getRealisePar()?->getUsername(),
                'resultat' => $a->getResultat(),
                'realisee' => $realisee,
            ],
        ];
    }
}
