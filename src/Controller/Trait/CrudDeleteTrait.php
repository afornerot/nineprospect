<?php

namespace App\Controller\Trait;

use App\Http\ContexteRetour;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logique partagée des suppressions admin : validation CSRF + flush + flash
 * générique avec log d'exception (jamais le message brut à l'utilisateur).
 *
 * La destination de retour est celle du contexte de navigation courant
 * ({@see ContexteRetour}) : après suppression on revient sur la page d'où
 * l'utilisateur a déclenché l'action, jamais sur un formulaire.
 */
trait CrudDeleteTrait
{
    /** Injecté par le constructeur du contrôleur hôte. */
    protected ContexteRetour $contexteRetour;

    /**
     * @param array{list: string, update?: string, successRoute?: string, successRouteParams?: array, conflictMessage?: string} $context
     *
     * `successRoute` (avec ses paramètres) permet de revenir sur la fiche de
     * l'objet parent au lieu de la liste, ex. suppression d'une étape de
     * pipeline. `list` est la route de repli quand aucun contexte n'est
     * disponible (session vide, accès direct à l'URL).
     */
    private function deleteEntity(Request $request, object $entity, string|int $id, string $tokenPrefix, string $entityLabel, EntityManagerInterface $em, array $context): Response
    {
        if (!$this->isCsrfTokenValid($tokenPrefix.$id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide — suppression non effectuée.');

            return $this->redirectAfterDelete($request, $context['list']);
        }

        try {
            $em->remove($entity);
            $em->flush();
        } catch (\Exception $e) {
            $this->crudLogger()->error('Suppression '.$entityLabel.' en échec', ['id' => $id, 'exception' => $e]);
            $this->addFlash('error', $context['conflictMessage'] ?? 'Suppression impossible : erreur technique.');

            return $this->redirectAfterDelete($request, $context['list']);
        }

        if (isset($context['successRoute'])) {
            return $this->redirectToRoute($context['successRoute'], $context['successRouteParams'] ?? ['id' => $id]);
        }

        return $this->redirectAfterDelete($request, $context['list']);
    }

    private function crudLogger(): LoggerInterface
    {
        $logger = $this->container->get('logger');

        if (!$logger instanceof LoggerInterface) {
            throw new \RuntimeException('Logger indisponible.');
        }

        return $logger;
    }

    /**
     * Retour après suppression : contexte de navigation, sinon liste du module.
     */
    private function redirectAfterDelete(Request $request, string $listRoute): RedirectResponse
    {
        return $this->contexteRetour->redirection($request, $listRoute);
    }
}
