<?php

namespace App\Command;

use App\Service\DolibarrApi;
use App\Service\DolibarrDocumentsSync;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rafraîchit les documents Dolibarr (devis, commandes, factures) mémorisés
 * pour tous les prospects liés à un tiers Dolibarr. Planifié via le cron
 * interne (`cron` de la base, `app:cron` chaque minute), tous les jours à 6:00.
 */
#[AsCommand(
    name: 'app:dolibarr:refresh-documents',
    description: 'Rafraîchit les documents Dolibarr de tous les prospects liés',
)]
class RefreshDocumentsDolibarrCommand extends Command
{
    public function __construct(
        private readonly DolibarrDocumentsSync $sync,
        private readonly DolibarrApi $dolibarr,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!$this->dolibarr->isConfigured()) {
            $io->note('Intégration Dolibarr désactivée (DOLIBARR_URL absent) : aucune synchronisation.');

            return Command::SUCCESS;
        }

        $resultat = $this->sync->synchroniserTous();

        $io->text(sprintf('%d prospect(s) lié(s) synchronisé(s).', $resultat['total']));

        foreach ($resultat['messages'] as $message) {
            $io->warning($message);
        }

        if ($resultat['erreurs'] > 0) {
            $io->error(sprintf('%d erreur(s) pendant la synchro.', $resultat['erreurs']));

            return Command::FAILURE;
        }

        $io->success('Synchronisation terminée.');

        return Command::SUCCESS;
    }
}
