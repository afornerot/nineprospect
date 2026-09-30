<?php

namespace App\Command;

use App\Repository\CampagneRepository;
use App\Repository\CibleRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:generate-slugs',
    description: 'Génère les slugs manquants pour les campagnes et cibles',
)]
class GenerateSlugsCommand extends Command
{
    public function __construct(
        private CampagneRepository $campagnes,
        private CibleRepository $cibles,
        private EntityManagerInterface $em,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $countCibles = $this->cibles->generateMissingSlugs($this->em);
        $io->info("$countCibles slugs générés pour les cibles");

        $countCampagnes = $this->campagnes->generateMissingSlugs($this->em);
        $io->info("$countCampagnes slugs générés pour les campagnes");

        $io->success('Terminé !');

        return Command::SUCCESS;
    }
}
