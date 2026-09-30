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

        try {
            $ch = curl_init(self::ALTCHA_SERVER_URL . '/verify');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);
            if (isset($result['success']) && $result['success'] === true) {
                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
}
