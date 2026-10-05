<?php

namespace App\Command;

use App\Message\GeocodeProspectMessage;
use App\Repository\ProspectRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Dispatche les messages GeocodeProspectMessage pour tous les Prospects sans
 * coordonnées GPS, afin qu'ils soient géocodés en asynchrone via l'API Adresse.
 *
 * Les messages partent sur le transport "async" (configuré dans messenger.yaml),
 * il faut donc qu'un worker tourne pour qu'ils soient consommés :
 *   docker compose exec -T nineprospect php bin/console messenger:consume async
 *
 * Usage :
 *   php bin/console app:geocode-prospects          # tous les Prospects sans coords
 *   php bin/console app:geocode-prospects --limit 100   # au max 100
 *   php bin/console app:geocode-prospects --dry-run    # preview sans dispatcher
 */
#[AsCommand(
    name: 'app:geocode-prospects',
    description: 'Dispatche GeocodeProspectMessage pour tous les Prospects sans coordonnées GPS',
)]
class GeocodeProspectsCommand extends Command
{
    public function __construct(
        private ProspectRepository $prospects,
        private MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Nombre maximum de Prospects à dispatcher', 500)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Liste les Prospects concernés sans dispatcher')
        ;
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $limit = (int) $input->getOption('limit');
        $dryRun = (bool) $input->getOption('dry-run');

        // Récupère tous les Prospects avec une adresse utilisable mais sans coordonnées.
        // On parcourt le repository, on ne sait pas faire une Query naturelle pour
        // "adresse non vide + lat/lon null", donc on récupère tout et on filtre en PHP
        // (acceptable pour un script de maintenance).
        $all = $this->prospects->findAll();
        $toGeocode = [];
        foreach ($all as $prospect) {
            if (null !== $prospect->getLatitude() || null !== $prospect->getLongitude()) {
                continue;
            }
            $adresse = (string) $prospect->getAdresse();
            $cp = (string) ($prospect->getCodePostal() ?? '');
            $ville = (string) ($prospect->getVille() ?? '');
            if ('' === trim($adresse.' '.$cp.' '.$ville)) {
                continue;
            }
            $toGeocode[] = $prospect->getId();
            if (\count($toGeocode) >= $limit) {
                break;
            }
        }

        if ([] === $toGeocode) {
            $io->success('Aucun Prospect à géocoder.');

            return Command::SUCCESS;
        }

        $io->writeln(sprintf('%d Prospect(s) à géocoder%s.', \count($toGeocode), $dryRun ? ' (dry-run)' : ''));

        if (!$dryRun) {
            foreach ($toGeocode as $prospectId) {
                if (null !== $prospectId) {
                    $this->bus->dispatch(new GeocodeProspectMessage($prospectId));
                }
            }
        }

        $io->success(sprintf(
            '%d message(s) GeocodeProspectMessage %s.',
            \count($toGeocode),
            $dryRun ? 'à dispatcher (dry-run, rien envoyé)' : 'dispatchés',
        ));

        return Command::SUCCESS;
    }
}
