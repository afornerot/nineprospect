<?php

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authentification machine-to-machine de l'API REST `/v1/api` par le secret
 * applicatif (`APP_SECRET`), transmis dans l'en-tête `X-API-SECRET`.
 *
 * Le secret est partagé par toute l'application : il ne doit pas être
 * celui de démonstration (`changeme`), sinon l'API est fermée par sécurité
 * (voir `DashboardApiController`, qui répond alors 503).
 *
 * L'utilisateur authentifié est le même que pour MCP (`mcp-system`), avec le
 * rôle `ROLE_MCP` : l'API n'a accès qu'aux routes qui l'autorisent
 * explicitement.
 */
class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public const HEADER = 'X-API-SECRET';

    /**
     * Valeurs refusées même si l'application tourne avec : le secret livré
     * par défaut dans `.env` ne doit jamais ouvrir l'API.
     */
    private const INTERDITS = ['', 'changeme'];

    public function __construct(
        #[Autowire(param: 'appSecret')]
        private readonly string $apiSecret,
    ) {
    }

    public function supports(Request $request): ?bool
    {
        return str_starts_with($request->getPathInfo(), '/v1/api');
    }

    public function authenticate(Request $request): Passport
    {
        if (!$this->secretConfigure()) {
            throw new AuthenticationException('API secret is not configured.');
        }

        $fourni = $request->headers->get(self::HEADER);
        if (null === $fourni || '' === trim($fourni)) {
            throw new AuthenticationException(sprintf('Missing %s header.', self::HEADER));
        }

        if (!hash_equals($this->apiSecret, (string) $fourni)) {
            throw new AuthenticationException('Invalid API secret.');
        }

        return new SelfValidatingPassport(new UserBadge('mcp-system'));
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new JsonResponse(
            ['error' => 'Unauthorized', 'message' => $exception->getMessage()],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    /**
     * Le secret par défaut du dépôt (`changeme`) n'ouvre pas l'API : il faut
     * une vraie valeur dans `.env.local`.
     */
    private function secretConfigure(): bool
    {
        return !\in_array(trim($this->apiSecret), self::INTERDITS, true);
    }
}
