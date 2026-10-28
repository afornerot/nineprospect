<?php

namespace App\Tests\Http;

use App\Http\ContexteRetour;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

/**
 * Le contexte de retour doit être déterministe et ne jamais accepter une URL
 * qui sorte du site (open-redirect / header injection).
 */
class ContexteRetourTest extends TestCase
{
    public function testAssainirRefuseToutCeQuiNEstPasUnCheminInterne(): void
    {
        $invalides = [
            'https://evil.example/pwn',
            'http://evil.example',
            '//evil.example/pwn',
            '/\\evil.example/pwn',
            'javascript:alert(1)',
            'evil.example',
            '/chemin\\retour',
            "/chemin\r\nSet-Cookie: x=1",
            '/chemin%0d%0aSet-Cookie:x=1',
            '',
            '   ',
            null,
            [],
        ];

        foreach ($invalides as $url) {
            $this->assertNull(ContexteRetour::assainir($url), var_export($url, true).' doit être refusé.');
        }
    }

    public function testAssainirConserveCheminQueryMaisRetireLeFragment(): void
    {
        $this->assertSame(
            '/user/prospects?q=acme&cible=4',
            ContexteRetour::assainir('/user/prospects?q=acme&cible=4#prospect-12'),
        );
        $this->assertSame('/user/agenda?date=2026-10-28', ContexteRetour::assainir('  /user/agenda?date=2026-10-28  '));
    }

    public function testMemoriserPageRetientLaListeEtLaPage(): void
    {
        $request = $this->requete();

        $this->contexte()->memoriserPage($request, '/user/prospects?cible=4', true);
        $this->contexte()->memoriserPage($request, '/user/prospects/show/12', false);

        $this->assertSame('/user/prospects/show/12', $this->contexte()->page($request));
        $this->assertSame(
            '/user/prospects?cible=4',
            $this->contexte()->liste($request, '/user/prospects'),
            'La liste mémorisée garde ses filtres.',
        );
    }

    public function testLesListesNeSeMarchentPasDessus(): void
    {
        $request = $this->requete();
        $contexte = $this->contexte();

        $contexte->memoriserPage($request, '/user/prospects?q=acme', true);
        $contexte->memoriserPage($request, '/user/contacts?utilisateur=3', true);
        $contexte->memoriserPage($request, '/user/actions?vue=retard', true);

        $this->assertSame('/user/prospects?q=acme', $contexte->liste($request, '/user/prospects'));
        $this->assertSame('/user/contacts?utilisateur=3', $contexte->liste($request, '/user/contacts'));
        $this->assertSame('/user/actions?vue=retard', $contexte->liste($request, '/user/actions'));
        $this->assertNull($contexte->liste($request, '/user/campagnes'));
    }

    public function testMemoriserPageIgnoreUneUrlExterne(): void
    {
        $request = $this->requete();

        $this->contexte()->memoriserPage($request, 'https://evil.example/pwn', true);

        $this->assertNull($this->contexte()->page($request));
        $this->assertNull($this->contexte()->liste($request, 'https://evil.example/pwn'));
    }

    public function testUrlRetombeSurLaRouteDeRepliSansSessionVide(): void
    {
        $request = $this->requete();

        $this->assertSame('/repli', $this->contexte()->url($request, 'app_fallback'));
    }

    public function testUrlPrendLaPageConsulteeAvantLeFormulaire(): void
    {
        $request = $this->requete();
        $this->contexte()->memoriserPage($request, '/user/prospects/show/12', false);

        $this->assertSame('/user/prospects/show/12', $this->contexte()->url($request, 'app_user_actions'));
    }

    public function testUrlNEnvoiePasUnFormulaireSurLuiMeme(): void
    {
        $request = $this->requete('/user/actions/submit');
        $this->contexte()->memoriserPage($request, '/user/actions/submit', false);

        $this->assertSame(
            '/repli',
            $this->contexte()->url($request, 'app_fallback'),
            'La page courante ne peut pas être sa propre destination de retour.',
        );
    }

    public function testListeOuRetombeSurLaRouteSiLaListeMemoriseeEstAutre(): void
    {
        $request = $this->requete();
        $this->contexte()->memoriserPage($request, '/user/contacts', true);

        $this->assertSame(
            '/repli',
            $this->contexte()->listeOu($request, '/user/prospects', 'app_fallback'),
        );

        $this->contexte()->memoriserPage($request, '/user/prospects?q=acme', true);
        $this->assertSame(
            '/user/prospects?q=acme',
            $this->contexte()->listeOu($request, '/user/prospects', 'app_fallback'),
        );
    }

    public function testListeOuAjouteLAncreDeScroll(): void
    {
        $request = $this->requete();
        $this->contexte()->memoriserPage($request, '/user/prospects?cible=4', true);

        $this->assertSame(
            '/user/prospects?cible=4#prospect-2675',
            $this->contexte()->listeOu($request, '/user/prospects', 'app_fallback', [], 'prospect-2675'),
        );
    }

    public function testRedirectionVersRetombeSurLaRouteDeRepliSiUrlInvalide(): void
    {
        $response = $this->contexte()->redirectionVers('https://evil.example', 'app_fallback');

        $this->assertSame('/repli', $response->getTargetUrl());
    }

    public function testRedirectionVersUtiliseLeChampRetour(): void
    {
        $response = $this->contexte()->redirectionVers('/user/prospects?cible=4', 'app_fallback');

        $this->assertSame('/user/prospects?cible=4', $response->getTargetUrl());
    }

    private function requete(string $uri = '/user/prospects'): Request
    {
        $request = Request::create($uri);
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function contexte(): ContexteRetour
    {
        return new ContexteRetour(new GenerateurDeRepli());
    }
}

/**
 * Générateur minimal : toute route produit `/repli` (+ query), ce qui suffit à
 * identifier le repli dans les assertions.
 */
final class GenerateurDeRepli implements UrlGeneratorInterface
{
    private RequestContext $contexte;

    public function __construct()
    {
        $this->contexte = new RequestContext();
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        return '/repli'.($parameters ? '?'.http_build_query($parameters) : '');
    }

    public function setContext(RequestContext $context): void
    {
        $this->contexte = $context;
    }

    public function getContext(): RequestContext
    {
        return $this->contexte;
    }
}
