<?php

namespace App\DataFixtures;

use App\Entity\Cron;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

class CronFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $crons = [
            'app:bonjour' => [
                'description' => 'Cron de test qui affiche Bonjour',
                'statut' => Cron::STATUT_DISABLED,
                'repeatcall' => 0,
                'repeatexec' => 0,
                'repeatinterval' => 60,
            ],
            'app:dolibarr:refresh-documents' => [
                'description' => 'Rafraîchit les documents Dolibarr (devis, commandes, factures) de tous les prospects liés',
                'statut' => Cron::STATUT_OK,
                'repeatcall' => 0,
                'repeatexec' => 0,
                'repeatinterval' => 86400,
            ],
        ];

        foreach ($crons as $command => $item) {
            $cron = $manager->getRepository(Cron::class)->findOneBy(['command' => $command]);
            if ($cron) {
                continue;
            }

            $cron = new Cron();
            $cron->setCommand($command);
            $cron->setDescription($item['description']);
            $cron->setStatut($item['statut']);
            $cron->setRepeatcall($item['repeatcall']);
            $cron->setRepeatexec($item['repeatexec']);
            $cron->setRepeatinterval($item['repeatinterval']);
            $cron->setNextexecdate($item['repeatinterval'] >= 86400
                // Créneau quotidien fixe : premier passage à 6:00 du matin.
                ? (new \DateTime('today 06:00') > new \DateTime()
                    ? new \DateTime('today 06:00')
                    : new \DateTime('tomorrow 06:00'))
                : new \DateTime());

            $manager->persist($cron);
        }

        $manager->flush();
    }
}
