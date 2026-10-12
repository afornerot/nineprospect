<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Repository\ProspectRepository;
use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/carte')]
class CarteController extends AbstractController
{
    use LayoutRenderTrait;

    /**
     * Whitelist des styles Mapbox autorisés. Évite qu'un utilisateur injecte
     * n'importe quelle URL dans `mapbox://styles/...`.
     */
    private const STYLES = [
        'streets-v12' => 'Rues',
        'light-v11' => 'Clair',
        'dark-v11' => 'Sombre',
        'satellite-streets-v12' => 'Satellite',
        'outdoors-v12' => 'Terrain',
    ];

    public function __construct(
        private ProspectRepository $prospects,
        #[Autowire('%mapboxPublicToken%')]
        private string $mapboxPublicToken,
        private UserRepository $users,
    ) {
    }

    #[Route('', name: 'app_user_carte')]
    public function index(Request $request): Response
    {
        $style = (string) $request->query->get('style', 'streets-v12');
        if (!isset(self::STYLES[$style])) {
            $style = 'streets-v12';
        }

        $userId = $this->lireParamEntier($request, 'utilisateur');
        $prospectsGeo = $this->prospects->findGeolocalises($userId);

        return $this->renderLayout('carte/index.html.twig', 'Carte des prospects', [
            'mapboxToken' => $this->mapboxPublicToken,
            'prospectsGeo' => $prospectsGeo,
            'totalProspects' => (int) $this->prospects->count([]),
            'styleKey' => $style,
            'styles' => self::STYLES,
            'utilisateurs' => $this->users->findAll(),
            'utilisateurFiltre' => $userId,
        ]);
    }

    /**
     * Entier de requête tolérant aux valeurs vides ou non numériques.
     */
    private function lireParamEntier(Request $request, string $nom): ?int
    {
        $valeur = $request->query->all()[$nom] ?? null;
        if (!is_scalar($valeur)) {
            return null;
        }

        $texte = trim((string) $valeur);
        if ('' === $texte) {
            return null;
        }

        return filter_var($texte, FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]);
    }
}
