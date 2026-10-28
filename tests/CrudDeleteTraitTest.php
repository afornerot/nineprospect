<?php

namespace App\Tests\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Http\ContexteRetour;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;

class CrudDeleteTraitTest extends TestCase
{
    public function testInvalidCsrfBlocksDeletion(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('remove');

        $response = $this->controller()->testDelete(
            $this->requete('bad-token'),
            new \stdClass(),
            1,
            'delete-x',
            $em,
            ['list' => 'route_list', 'update' => 'route_update'],
        );

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            '/route/list',
            $response->getTargetUrl(),
            'Un CSRF invalide renvoie sur la liste du module, jamais sur un formulaire.',
        );
    }

    public function testSuppressionReussieRevientSurLaPageConsultee(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('remove');
        $em->expects($this->once())->method('flush');

        $requete = $this->requete('valid-token');
        $this->contexte()->memoriserPage($requete, '/user/prospects?cible=4', true);

        $response = $this->controller()->testDelete(
            $requete,
            new \stdClass(),
            1,
            'delete-x',
            $em,
            ['list' => 'route_list', 'update' => 'route_update'],
        );

        $this->assertSame(
            '/user/prospects?cible=4',
            $response->getTargetUrl(),
            'Après suppression on revient sur la liste filtrée consultée.',
        );
    }

    public function testSuppressionReussieAvecFicheParente(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('flush');

        $response = $this->controller()->testDelete(
            $this->requete('valid-token'),
            new \stdClass(),
            7,
            'delete-x',
            $em,
            [
                'list' => 'route_list',
                'update' => 'route_update',
                'successRoute' => 'route_parent',
                'successRouteParams' => ['id' => 42],
            ],
        );

        $this->assertSame('/route/parent?id=42', $response->getTargetUrl());
    }

    private function requete(string $token): Request
    {
        $request = new Request([], ['_csrf_token' => $token]);
        $request->setSession(new Session(new MockArraySessionStorage()));
        $request->attributes->set('_route', 'route_update');

        return $request;
    }

    private function contexte(): ContexteRetour
    {
        return new ContexteRetour(new GenerateurDeRoutesDeTest());
    }

    private function controller(): FlushStubController
    {
        return new FlushStubController($this->contexte());
    }
}

final class GenerateurDeRoutesDeTest implements UrlGeneratorInterface
{
    private RequestContext $contexte;

    public function __construct()
    {
        $this->contexte = new RequestContext();
    }

    public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
    {
        $url = '/'.str_replace('_', '/', $name);

        return $parameters ? $url.'?'.http_build_query($parameters) : $url;
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

class FlushStubController
{
    use CrudDeleteTrait;

    public \Psr\Container\ContainerInterface $container;

    public function __construct(ContexteRetour $contexteRetour)
    {
        $this->contexteRetour = $contexteRetour;
    }

    public function isCsrfTokenValid(string $tokenId, mixed $token): bool
    {
        return 'valid-token' === $token;
    }

    public function addFlash(string $type, mixed $message): void
    {
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function redirectToRoute(string $route, array $parameters = [], int $status = 302): RedirectResponse
    {
        $url = '/'.str_replace('_', '/', $route);

        return new RedirectResponse($parameters ? $url.'?'.http_build_query($parameters) : $url);
    }

    /**
     * @param array{list: string, update?: string, conflictMessage?: string} $context
     */
    public function testDelete(Request $request, object $entity, int|string $id, string $tokenPrefix, EntityManagerInterface $em, array $context): Response
    {
        return $this->deleteEntity($request, $entity, $id, $tokenPrefix, 'x', $em, $context);
    }
}
