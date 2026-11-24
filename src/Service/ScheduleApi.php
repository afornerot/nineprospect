<?php

namespace App\Service;

use App\Service\ConfigParameterBag;
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
 * une structure vide + `error` plutôt que de lever. Désactivée si la config
 * applicative `appActivateSchedule` (page /admin/config) n'est pas à « oui »,
 * ou si `SCHEDULE_URL` / `SCHEDULE_SECRET` sont vides → `isConfigured()` false
 * et aucune requête émise.
 */
class ScheduleApi
{
    private const TIMEOUT = 5;

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly ConfigParameterBag $config,
        #[Autowire('%scheduleUrl%')]
        private readonly ?string $scheduleUrl,
        #[Autowire('%scheduleSecret%')]
        private readonly ?string $scheduleSecret,
    ) {
    }

    public function isConfigured(): bool
    {
        return 'oui' === ($this->config->has('appActivateSchedule') ? $this->config->get('appActivateSchedule') : null)
            && '' !== ($this->scheduleUrl ?? '')
            && '' !== ($this->scheduleSecret ?? '');
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
}
