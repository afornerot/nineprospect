<?php

namespace App\EventSubscriber;

use App\Http\ContexteRetour;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mémorise la page affichée après chaque navigation GET réussie : c'est ce
 * qui permet aux écrans de saisie de savoir où revenir sans transporter
 * d'URL dans la query string.
 *
 * - `page`   : toute page HTML consultée en GET, hors formulaires et
 *              endpoints JSON (voir self::ACTIONS_EXCLUES) ;
 * - `listes` : uniquement les actions `list()` / `index()`, c'est-à-dire les
 *              listes, seule source des liens « Retour à la liste ».
 *
 * Le filtre ne peut pas porter sur l'en-tête `Content-Type` : à ce stade du
 * cycle, il n'est pas encore posé (c'est le ResponseListener de Symfony, de
 * priorité égale mais enregistré après, qui l'ajoute). On s'appuie donc sur
 * le format de requête (`_format`) et sur la liste d'actions exclues.
 */
final class MemoriserContexteRetour implements EventSubscriberInterface
{
    /**
     * Actions qui ne doivent jamais devenir une destination de retour :
     * formulaires de saisie (on reviendrait sur le formulaire qu'on vient de
     * valider), endpoints AJAX et fragments.
     */
    private const ACTIONS_EXCLUES = [
        'submit',
        'update',
        'delete',
        'bulk',
        'bulkSubmit',
        'import',
        'importPreview',
        'importExecute',
        'importTemplate',
        'fromAnnuaire',
        'fromAnnuaireCreate',
        'verify',
        'verifyApply',
        'apply',
        'events',
        'csrf',
        'reaffecter',
        'reaffecterCsrf',
        'setDate',
        'cloturer',
        'decloturer',
        'toggleContacte',
        'cycleEtape',
        'cycleCible',
        'linkCible',
        'addCible',
        'contactsPourProspect',
        'profil',
        'create',
    ];

    /** Actions qui désignent une liste. */
    private const ACTIONS_LISTE = ['list', 'index'];

    public function __construct(private readonly ContexteRetour $contexte)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'onResponse'];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$request->isMethod('GET') || $request->isXmlHttpRequest()) {
            return;
        }

        if ('html' !== $request->getRequestFormat('html')) {
            return;
        }

        if (!$this->estPage($event->getResponse())) {
            return;
        }

        $action = $this->action($request);
        if (null === $action || \in_array($action, self::ACTIONS_EXCLUES, true)) {
            return;
        }

        $this->contexte->memoriserPage(
            $request,
            $request->getRequestUri(),
            \in_array($action, self::ACTIONS_LISTE, true),
        );
    }

    /**
     * Une redirection n'est pas une page consultée ; une erreur non plus.
     */
    private function estPage(Response $response): bool
    {
        if ($response instanceof RedirectResponse) {
            return false;
        }

        return Response::HTTP_OK === $response->getStatusCode();
    }

    /** Nom de la méthode de contrôleur (ex. `list`, `show`, `update`). */
    private function action(Request $request): ?string
    {
        $controleur = (string) $request->attributes->get('_controller', '');
        $position = strrpos($controleur, '::');

        return false === $position ? null : substr($controleur, $position + 2);
    }
}
