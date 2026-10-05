<?php

namespace App\MessageHandler;

use App\Message\GeocodeProspectMessage;
use App\Repository\ProspectRepository;
use App\Service\AdresseApi;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handler du message GeocodeProspectMessage : récupère ses données et lance
 * le géocodage via l'API Adresse. Met à jour lat/lon si succès.
 */
#[AsMessageHandler]
final class GeocodeProspectMessageHandler
{
    public function __construct(
        private ProspectRepository $prospects,
        private AdresseApi $adresseApi,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(GeocodeProspectMessage $message): void
    {
        $prospect = $this->prospects->find($message->prospectId);
        if (null === $prospect) {
            return;
        }

        // Ne pas re-géocoder si déjà positionné
        if (null !== $prospect->getLatitude() && null !== $prospect->getLongitude()) {
            return;
        }

        // Adresse complète minimale requise
        $adresse = (string) $prospect->getAdresse();
        $cp = (string) ($prospect->getCodePostal() ?? '');
        $ville = (string) ($prospect->getVille() ?? '');
        if ('' === trim($adresse.' '.$cp.' '.$ville)) {
            return;
        }

        $coords = $this->adresseApi->geocode($adresse, $cp, $ville);
        if (null === $coords) {
            $this->logger->info('GeocodeProspectMessage: pas de résultat API pour prospect {id}', [
                'id' => $prospect->getId(),
            ]);

            return;
        }

        $prospect->setLatitude($coords['lat']);
        $prospect->setLongitude($coords['lon']);
        $this->em->flush();

        $this->logger->info('GeocodeProspectMessage: prospect {id} géocodé ({lat}, {lon})', [
            'id' => $prospect->getId(),
            'lat' => $coords['lat'],
            'lon' => $coords['lon'],
        ]);
    }
}
