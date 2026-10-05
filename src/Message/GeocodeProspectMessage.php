<?php

namespace App\Message;

/**
 * Message asynchrone : géocoder un Prospect via l'API Adresse.
 *
 * Dispatché après chaque création de Prospect par ImportExecutor (ou autre flux),
 * pour ne pas bloquer l'import sur des appels HTTP externes potentiellement lents.
 */
final class GeocodeProspectMessage
{
    public function __construct(
        public readonly int $prospectId,
    ) {
    }
}
