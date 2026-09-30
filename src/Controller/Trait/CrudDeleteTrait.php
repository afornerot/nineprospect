<?php

namespace App\Controller\Trait;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logique partagée des suppressions admin : validation CSRF + flush + flash
 * générique avec log d'exception (jamais le message brut à l'utilisateur).
 */
trait CrudDeleteTrait
{
    /**
     * @param array{list: string, update?: string, successRoute?: string, successRouteParams?: array, conflictMessage?: string} $context
     *
     * `successRoute` (avec `id`) permet de revenir sur la fiche de l'objet
     * parent au lieu de la liste, ex. suppression d'une étape de pipeline.
     * `successRouteParams` permet de spécifier les paramètres de la route de succès.
     */
    private function deleteEntity(Request $request, object $entity, string|int $id, string $tokenPrefix, string $entityLabel, EntityManagerInterface $em, array $context): Response
    {
        if (!$this->isCsrfTokenValid($tokenPrefix.$id, (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide — suppression non effectuée.');

            return $this->redirectAfterDelete($id, $context['update'] ?? null, $context['list'], $context['redirectParams'] ?? null);
        }

        try {
            $em->remove($entity);
            $em->flush();
        } catch (\Exception $e) {
            $this->crudLogger()->error('Suppression '.$entityLabel.' en échec', ['id' => $id, 'exception' => $e]);
            $this->addFlash('error', $context['conflictMessage'] ?? 'Suppression impossible : erreur technique.');

            return $this->redirectAfterDelete($id, $context['update'] ?? null, $context['list'], $context['redirectParams'] ?? null);
        }

        if (isset($context['successRoute'])) {
            $params = $context['successRouteParams'] ?? ['id' => $id];

            return $this->redirectToRoute($context['successRoute'], $params);
        }

        return $this->redirectToRoute($context['list']);
    }

    private function crudLogger(): LoggerInterface
    {
        $logger = $this->container->get('logger');

        if (!$logger instanceof LoggerInterface) {
            throw new \RuntimeException('Logger indisponible.');
        }

        return $logger;
    }

    private function redirectAfterDelete(int|string $id, ?string $updateRoute, string $listRoute, ?array $redirectParams = null): RedirectResponse
    {
        if (null !== $updateRoute) {
            $params = $redirectParams ?? ['id' => $id];

            return $this->redirectToRoute($updateRoute, $params);
        }

        return $this->redirectToRoute($listRoute);
    }
}
