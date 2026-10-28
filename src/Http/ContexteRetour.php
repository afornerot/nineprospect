<?php

namespace App\Http;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Contexte de navigation mémorisé en session.
 *
 * Aucune URL de retour ne circule dans la query string : la session retient
 *  - `page`   : la dernière page affichable consultée (liste, fiche, agenda…) ;
 *  - `listes` : la dernière URL de chaque liste, indexée par son chemin.
 *
 * C'est ce qui permet à un formulaire ouvert depuis une fiche prospect de
 * revenir à la liste filtrée d'origine après trois niveaux de navigation, et
 * à deux onglets ouverts sur deux listes différentes de ne pas se marcher
 * dessus (l'indexation par chemin isole les sections).
 */
final class ContexteRetour
{
    private const CLE = '_retour';

    /** Plafond de sécurité : une URL mémorisée ne doit pas gonfler la session. */
    private const LONGUEUR_MAX = 2048;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * Mémorise la page rendue. `$liste` marque les pages de type liste, seules
     * à alimenter `listes`.
     */
    public function memoriserPage(Request $request, string $url, bool $liste): void
    {
        $url = self::assainir($url);
        if (null === $url || !$request->hasSession()) {
            return;
        }

        $session = $request->getSession();
        $contexte = $session->get(self::CLE);
        $contexte = \is_array($contexte) ? $contexte : [];

        $contexte['page'] = $url;

        if ($liste) {
            $listes = $contexte['listes'] ?? [];
            $listes = \is_array($listes) ? $listes : [];
            $listes[self::chemin($url)] = $url;
            $contexte['listes'] = $listes;
        }

        $session->set(self::CLE, $contexte);
    }

    /** Dernière page affichable consultée (liste, fiche, agenda…). */
    public function page(Request $request): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $contexte = $request->getSession()->get(self::CLE);

        return \is_array($contexte) ? self::assainir($contexte['page'] ?? null) : null;
    }

    /** Dernière URL connue de la liste désignée par son chemin. */
    public function liste(Request $request, string $chemin): ?string
    {
        if (!$request->hasSession()) {
            return null;
        }

        $contexte = $request->getSession()->get(self::CLE);
        if (!\is_array($contexte) || !\is_array($contexte['listes'] ?? null)) {
            return null;
        }

        $url = $contexte['listes'][$chemin] ?? null;
        if (!\is_string($url) || self::chemin($url) !== $chemin) {
            return null;
        }

        return self::assainir($url);
    }

    /**
     * URL de retour d'un écran de saisie : la page précédente, sinon la route
     * de repli. Jamais l'URL courante (on ne renvoie pas un formulaire sur
     * lui-même).
     *
     * @param array<string, mixed> $params
     */
    public function url(Request $request, string $routeFallback, array $params = [], ?string $ancre = null): string
    {
        $page = $this->page($request);
        if (null !== $page && $page !== $request->getRequestUri()) {
            return self::avecAncre($page, $ancre);
        }

        return self::avecAncre($this->urlGenerator->generate($routeFallback, $params), $ancre);
    }

    /**
     * URL de retour vers une liste précise : l'URL mémorisée pour ce chemin
     * si elle existe, sinon la route de repli. C'est le « Retour à la liste »
     * d'une fiche, qui ne doit jamais ramener dans une autre section.
     *
     * @param array<string, mixed> $params
     */
    public function listeOu(Request $request, string $chemin, string $routeFallback, array $params = [], ?string $ancre = null): string
    {
        return self::avecAncre(
            $this->liste($request, $chemin) ?? $this->urlGenerator->generate($routeFallback, $params),
            $ancre,
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    public function redirection(Request $request, string $routeFallback, array $params = [], ?string $ancre = null): RedirectResponse
    {
        return new RedirectResponse($this->url($request, $routeFallback, $params, $ancre));
    }

    /**
     * Redirige vers une URL déjà calculée (champ caché `retour` d'un
     * formulaire), après assainissement. Retombe sur la route de repli si
     * l'URL n'est pas un chemin interne.
     *
     * @param array<string, mixed> $params
     */
    public function redirectionVers(mixed $url, string $routeFallback, array $params = []): RedirectResponse
    {
        return new RedirectResponse(
            self::assainir($url) ?? $this->urlGenerator->generate($routeFallback, $params)
        );
    }

    /**
     * N'accepte qu'un chemin interne absolu : pas de schéma, pas d'hôte, pas
     * de caractère de contrôle, pas de fragment. Renvoie null pour tout le
     * reste (protection open-redirect et header injection).
     */
    public static function assainir(mixed $url): ?string
    {
        if (!\is_string($url)) {
            return null;
        }

        $url = trim($url);
        if ('' === $url || \strlen($url) > self::LONGUEUR_MAX) {
            return null;
        }

        // Un fragment n'est jamais envoyé au serveur : on le retire.
        $diese = strpos($url, '#');
        if (false !== $diese) {
            $url = substr($url, 0, $diese);
        }

        if ('' === $url || !str_starts_with($url, '/')) {
            return null;
        }

        // `//hote` et `/\hote` sont interprétés par les navigateurs comme des
        // URLs absolues : refusés.
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return null;
        }

        if (str_contains($url, '\\') || preg_match('#[\x00-\x20\x7f]#', $url)) {
            return null;
        }

        // CR, LF et tabulation encodés : header injection.
        if (preg_match('/%(0d|0a|09)/i', $url)) {
            return null;
        }

        return $url;
    }

    /** Chemin d'une URL interne (sans query) — clé de la table des listes. */
    public static function chemin(string $url): string
    {
        $position = strpos($url, '?');

        return false === $position ? $url : substr($url, 0, $position);
    }

    /**
     * Ajoute un fragment de scroll (`#prospect-12`) à une URL qui n'en porte
     * pas encore.
     */
    private static function avecAncre(string $url, ?string $ancre): string
    {
        if (null === $ancre || '' === $ancre || str_contains($url, '#')) {
            return $url;
        }

        return $url.'#'.ltrim($ancre, '#');
    }
}
