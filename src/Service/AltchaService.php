<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class AltchaService
{
    private const ALTCHA_SERVER_URL = 'http://altcha:3333';

    public function __construct(
        private HttpClientInterface $http,
        private string $altchaHmacKey,
    ) {
    }

    public function requestChallenge(): array
    {
        $response = $this->http->request('GET', self::ALTCHA_SERVER_URL . '/request');

        return $response->toArray();
    }

    public function verifySolution(array $payload): bool
    {
        $response = $this->http->request('POST', self::ALTCHA_SERVER_URL . '/verify', [
            'json' => $payload,
        ]);

        $data = $response->toArray();

        return $data['success'] ?? false;
    }
}
