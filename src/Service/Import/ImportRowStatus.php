<?php

namespace App\Service\Import;

/**
 * Statut calculé d'une ligne lors de l'analyse : sert à la fois pour
 * l'affichage dans le pré-import et pour le rapport final.
 */
final class ImportRowStatus
{
    public const OK = 'ok';
    public const ERROR = 'error';
    public const DUPLICATE_PROSPECT_DB = 'duplicate_prospect_db';
    public const DUPLICATE_CONTACT_DB = 'duplicate_contact_db';
    public const DUPLICATE_INTRA = 'duplicate_intra';
}
