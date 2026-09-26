<?php

namespace App\Command;

use App\Service\Import\ProspectImporter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:import-prospects',
    description: 'Importe un export CSV de prospects (feuille "Prospects")',
)]
class ImportProspectsCommand extends Command
{
    public function __construct(
        private ProspectImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('fichier', InputArgument::REQUIRED, 'Chemin vers le fichier CSV à importer')
            ->addOption('reset', null, InputOption::VALUE_NONE, 'Supprime les prospects, campagnes et vagues avant import')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simule l\'import sans rien enregistrer')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $chemin = (string) $input->getArgument('fichier');
        $reset = (bool) $input->getOption('reset');
        $dryRun = (bool) $input->getOption('dry-run');

        $io->title('IMPORT PROSPECTS');
        $io->text('Fichier = '.$chemin);
        $io->text('Mode = '.($dryRun ? 'simulation' : 'réel').($reset ? ' + réinitialisation' : ''));

        $depart = microtime(true);

        try {
            $rapport = $this->importer->importer($chemin, $reset, $dryRun);
        } catch (\Throwable $e) {
            $io->error('Échec de l\'import : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success($rapport->resume().' en '.round(microtime(true) - $depart, 2).'s');

        $io->table(
            ['Rubrique', 'Valeur'],
            [
                ['Lignes lues', (string) $rapport->lignesLues],
                ['Lignes ignorées', (string) $rapport->lignesIgnorees],
                ['Prospects créés', (string) $rapport->prospectsCrees],
                ['Prospects mis à jour', (string) $rapport->prospectsMaj],
                ['Contacts créés', (string) $rapport->contactsCrees],
                ['Contacts mis à jour', (string) $rapport->contactsMaj],
                ['Actions créées', (string) $rapport->actionsCreees],
                ['Campagnes créées', (string) $rapport->campagnesCreees],
                ['Vagues créées', (string) $rapport->sprintsCrees],
                ['Prospects en anomalie', (string) $rapport->anomalies],
            ],
        );

        if ([] !== $rapport->messages) {
            $io->listing($rapport->messages);
        }

        return Command::SUCCESS;
    }
}
