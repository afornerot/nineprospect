<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les lignes orphelines qui pointent vers des Prospects / Cibles / Campagnes / Sprints
 * inexistants.
 *
 * Utilité : si vous supprimez manuellement des Prospects (ou toute autre entité) en SQL brut,
 * les tables liées (contact, action, prospect_cible, prospect_user, prospect_sprint) conservent
 * des FK pointant dans le vide. Doctrine refuse alors de charger ces entités et toute opération
 * qui y touche échoue ("Entity of type X for IDs id(Y) was not found").
 *
 * Les cascades Doctrine (cascade: ['remove'] + orphanRemoval: true) ne fonctionnent QUE
 * via l'ORM. Cette commande imite ce comportement en SQL, ce qui est utile après une
 * purge manuelle.
 *
 * Usage :
 *   php bin/console app:cleanup-orphans          # mode interactif (confirmation)
 *   php bin/console app:cleanup-orphans --dry-run # affiche ce qui serait supprimé
 *   php bin/console app:cleanup-orphans --yes     # supprime sans confirmation
 */
#[AsCommand(
    name: 'app:cleanup-orphans',
    description: 'Supprime les lignes orphelines (contact, action, prospect_cible, prospect_user, prospect_sprint) pointant vers des entités supprimées',
)]
class CleanupOrphansCommand extends Command
{
    /**
     * Cibles (label SQL, table SQL, colonne FK).
     * L'ordre est important : on supprime d'abord les tables "profondes"
     * avant leurs parents logiques.
     */
    private const TARGETS = [
        [
            'label' => 'Actions sans prospect',
            'table' => 'action',
            'fkColumn' => 'prospect_id',
            'parentTable' => 'prospect',
            'parentColumn' => 'id',
        ],
        [
            'label' => 'Contacts sans prospect',
            'table' => 'contact',
            'fkColumn' => 'prospect_id',
            'parentTable' => 'prospect',
            'parentColumn' => 'id',
        ],
        [
            'label' => 'Liens prospect-user sans prospect',
            'table' => 'prospect_user',
            'fkColumn' => 'prospect_id',
            'parentTable' => 'prospect',
            'parentColumn' => 'id',
        ],
        [
            'label' => 'Prospects-Sprints sans prospect',
            'table' => 'prospect_sprint',
            'fkColumn' => 'prospect_id',
            'parentTable' => 'prospect',
            'parentColumn' => 'id',
        ],
        [
            'label' => 'Prospects-Sprints sans sprint',
            'table' => 'prospect_sprint',
            'fkColumn' => 'sprint_id',
            'parentTable' => 'sprint',
            'parentColumn' => 'id',
        ],
        [
            'label' => 'ProspectCibles sans prospect',
            'table' => 'prospect_cible',
            'fkColumn' => 'prospect_id',
            'parentTable' => 'prospect',
            'parentColumn' => 'id',
        ],
        [
            'label' => 'ProspectCibles sans cible',
            'table' => 'prospect_cible',
            'fkColumn' => 'cible_id',
            'parentTable' => 'cible',
            'parentColumn' => 'id',
        ],
        [
            'label' => 'Prospects sans campagne',
            'table' => 'prospect',
            'fkColumn' => 'campagne_id',
            'parentTable' => 'campagne',
            'parentColumn' => 'id',
        ],
    ];

    public function __construct(
        private Connection $connection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Compte les orphelins sans rien supprimer')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Confirme la suppression sans prompt')
        ;
    }

    public function __invoke(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $yes = (bool) $input->getOption('yes');

        $io->title($dryRun ? 'CLEANUP ORPHANS — DRY RUN' : 'CLEANUP ORPHANS');

        // 1) Compter les orphelins par catégorie
        $stats = [];
        $total = 0;
        foreach (self::TARGETS as $target) {
            $sql = sprintf(
                'SELECT COUNT(*) FROM %s child LEFT JOIN %s parent ON child.%s = parent.%s WHERE parent.%s IS NULL',
                $target['table'],
                $target['parentTable'],
                $target['fkColumn'],
                $target['parentColumn'],
                $target['parentColumn'],
            );

            try {
                $count = (int) $this->connection->fetchOne($sql);
            } catch (\Throwable $e) {
                $io->warning(sprintf('%s : table inaccessible (%s)', $target['label'], $e->getMessage()));
                continue;
            }

            $stats[] = [
                'label' => $target['label'],
                'table' => $target['table'],
                'fk' => $target['fkColumn'],
                'count' => $count,
            ];
            $total += $count;
        }

        // 2) Affichage du rapport
        $io->table(
            ['Catégorie', 'Table.colonne', 'Orphelins'],
            array_map(
                static fn (array $row): array => [
                    $row['label'],
                    $row['table'].'.'.$row['fk'],
                    (string) $row['count'],
                ],
                $stats,
            ),
        );

        if (0 === $total) {
            $io->success('Aucun orphelin à supprimer.');

            return Command::SUCCESS;
        }

        // 3) Confirmation
        if ($dryRun) {
            $io->info(sprintf('%d orphelin(s) à supprimer — dry-run, rien n\'a été supprimé.', $total));

            return Command::SUCCESS;
        }

        if (!$yes) {
            $io->warning(sprintf('%d ligne(s) vont être définitivement supprimées.', $total));
            if (!$io->confirm('Confirmer la suppression ?', false)) {
                $io->info('Annulé.');

                return Command::SUCCESS;
            }
        }

        // 4) Suppression effective (en transaction par catégorie)
        $deleted = 0;
        $this->connection->beginTransaction();
        try {
            foreach ($stats as $row) {
                if (0 === $row['count']) {
                    continue;
                }
                $target = self::TARGETS[array_search($row, $stats, true)];
                $sql = sprintf(
                    'DELETE child FROM %s child LEFT JOIN %s parent ON child.%s = parent.%s WHERE parent.%s IS NULL',
                    $target['table'],
                    $target['parentTable'],
                    $target['fkColumn'],
                    $target['parentColumn'],
                    $target['parentColumn'],
                );
                $nb = $this->connection->executeStatement($sql);
                $deleted += $nb;
                $io->writeln(sprintf('  → %s : %d supprimé(s)', $row['label'], $nb));
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();
            $io->error('Erreur pendant la suppression : '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Terminé : %d orphelin(s) supprimé(s).', $deleted));

        return Command::SUCCESS;
    }
}
