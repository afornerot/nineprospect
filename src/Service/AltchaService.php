<?php

namespace App\Service;

class AltchaService
{
    private const ALTCHA_SERVER_URL = 'http://altcha:3333';

    public function __construct(
        private string $altchaHmacKey,
    ) {
    }

    public function requestChallenge(): array
    {
        $response = file_get_contents(self::ALTCHA_SERVER_URL . '/request');
        return json_decode($response, true);
    }

    public function verifySolution(array $payload): array
    {
        $data = $payload;

        if (isset($payload['payload'])) {
            $decoded = base64_decode($payload['payload'], true);
            if ($decoded) {
                $data = json_decode($decoded, true);
            }
        }

        $payloadToVerify = [
            'algorithm' => $data['algorithm'] ?? 'SHA-256',
            'challenge' => $data['challenge'] ?? '',
            'salt' => $data['salt'] ?? '',
            'signature' => $data['signature'] ?? '',
        ];

        if (isset($data['number'])) {
            $payloadToVerify['number'] = (int) $data['number'];
        }

        try {
            $ch = curl_init(self::ALTCHA_SERVER_URL . '/verify');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payloadToVerify),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            if (200 !== $httpCode) {
                return ['success' => false, 'data' => null];
            }

            $result = json_decode($response, true);
            $success = isset($result['success']) && $result['success'] === true;

            return [
                'success' => $success,
                'data' => $success ? $data : null,
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'data' => null];
        }
    }
}
