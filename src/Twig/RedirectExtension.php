<?php

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Construit des URL de retour vers la liste prospects en préservant les
 * filtres de la query string courante et en ajoutant un fragment pour le
 * scroll vers le prospect travaillé (ex. "#prospect-2417").
 *
 * Usage :
 *   {{ prospects_list_url() }}                       → liste filtrée
 *   {{ prospects_list_url(prospect.id) }}            → liste filtrée + scroll
 */
class RedirectExtension extends AbstractExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('prospects_list_url', [$this, 'listUrl']),
        ];
    }

    public function listUrl(?int $prospectId = null): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $base = '/user/prospects';

        if (null !== $request) {
            $query = $request->query->all();
            // On retire les paramètres techniques qui ne sont pas des filtres
            // applicatifs (ex. _fragment, _locale, _target_path, _token, etc.).
            unset($query['_fragment']);
            if ([] !== $query) {
                $base .= '?'.http_build_query($query);
            }
        }

        if (null !== $prospectId) {
            $base .= '#prospect-'.$prospectId;
        }

        return $base;
    }
}
