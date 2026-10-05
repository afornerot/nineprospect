<?php

namespace App\Command;

use App\Message\GeocodeProspectMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatche un message GeocodeProspectMessage pour un seul Prospect.
 * Utile pour re-géocoder manuellement un Prospect qui a été créé/modifié
 * sans déclencher le géocodage (ex. coordonnées absentes avant ce fix).
 *
 * Usage :
 *   php bin/console app:geocode-prospect 2682
 */
#[AsCommand(
    name: 'app:geocode-prospect',
    description: 'Dispatche GeocodeProspectMessage pour un seul Prospect (re-géocodage manuel)',
)]
class GeocodeProspectCommand extends Command
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('prospectId', InputArgument::REQUIRED, 'ID du Prospect à géocoder');
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $prospectId = (int) $input->getArgument('prospectId');

        $this->bus->dispatch(new GeocodeProspectMessage($prospectId));
        $io->success(sprintf('Message GeocodeProspectMessage dispatché pour le prospect #%d.', $prospectId));

        return Command::SUCCESS;
    }
}
