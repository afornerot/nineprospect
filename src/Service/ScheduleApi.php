<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP **lecture seule** de l'API schedule (`{SCHEDULE_URL}`), le
 * service de planning ETP du nine-project. Rappatrié de `suivi` : même appel
 * que `ServiceController::service()` / `EtpController::getEtpData()` là-bas.
 *
 * Authentification : clé secrète partagée (`SCHEDULE_SECRET`) passée dans le
 * corps du POST.
 *
 * Toutes les méthodes publiques sont tolérantes aux pannes : elles renvoient
 * une structure vide + `error` plutôt que de lever. `SCHEDULE_URL` ou
 * `SCHEDULE_SECRET` vide -> `isConfigured()` false et aucune requête émise.
 */
class ScheduleApi
{
    private const TIMEOUT = 5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        #[Autowire('%scheduleUrl%')]
        private readonly ?string $scheduleUrl,
        #[Autowire('%scheduleSecret%')]
        private readonly ?string $scheduleSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== ($this->scheduleUrl ?? '') && '' !== ($this->scheduleSecret ?? '');
    }

    /**
     * ETP consommés par service et par membre sur une période, en mois
     * numériques `YYYYMM` inclusifs (ex. 202601 → 202612).
     *
     * @return array<string, array<string, float>> service → membre → ETP ;
     *         `['error' => …]` si l'appel échoue ou l'intégration est vide
     */
    public function getETPs(int $startMonth, int $endMonth): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Schedule non configuré'];
        }

        try {
            $response = $this->http->request('POST', rtrim($this->scheduleUrl, '/').'/rest/getETPs', [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
                'body' => [
                    'key' => $this->scheduleSecret,
                    'startMonth' => (string) $startMonth,
                    'endMonth' => (string) $endMonth,
                ],
            ]);

            $contenu = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('Schedule injoignable : {message}', ['message' => $e->getMessage()]);

            return ['error' => 'Schedule injoignable : '.$e->getMessage()];
        }

        return is_array($contenu) ? $contenu : ['error' => 'Réponse schedule inattendue'];
    }

    /**
     * Jours planifiés sur une tâche schedule (`Produit::$scheduleTaskId`),
     * tous membres confondus, sur une période (mois `YYYYMM` inclusifs).
     *
     * @return array{total: float}|array{error: string} total en jours ;
     *         `['error' => …]` si l'appel échoue ou l'intégration est vide
     */
    public function getTaskJours(string $taskId, int $startMonth, int $endMonth): array
    {
        if (!$this->isConfigured()) {
            return ['error' => 'Schedule non configuré'];
        }

        try {
            $response = $this->http->request('POST', rtrim($this->scheduleUrl, '/').'/rest/taskETPs', [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => self::TIMEOUT,
                'body' => [
                    'key' => $this->scheduleSecret,
                    'taskId' => (string) $taskId,
                    'startMonth' => (string) $startMonth,
                    'endMonth' => (string) $endMonth,
                ],
            ]);

            $contenu = $response->toArray();
        } catch (\Throwable $e) {
            $this->logger->warning('Schedule injoignable : {message}', ['message' => $e->getMessage()]);

            return ['error' => 'Schedule injoignable : '.$e->getMessage()];
        }

        if (!is_array($contenu) || !isset($contenu['total']) || !is_numeric($contenu['total'])) {
            return ['error' => 'Réponse schedule inattendue'];
        }

        return ['total' => (float) $contenu['total']];
    }
}
