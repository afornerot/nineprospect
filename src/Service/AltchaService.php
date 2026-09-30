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
        $data = [
            'algorithm' => $payload['algorithm'] ?? 'SHA-256',
            'challenge' => $payload['challenge'] ?? '',
            'salt' => $payload['salt'] ?? '',
            'signature' => $payload['signature'] ?? '',
        ];

        if (isset($payload['solution'])) {
            if (is_array($payload['solution'])) {
                $data['number'] = $payload['solution']['number'] ?? 0;
            } else {
                $data['number'] = (int) $payload['solution'];
            }
        }

        $response = $this->http->request('POST', self::ALTCHA_SERVER_URL . '/verify', [
            'json' => $data,
        ]);

        $result = $response->toArray();

        return $result['success'] ?? false;
    }
}
