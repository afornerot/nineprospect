<?php

namespace App\DataFixtures;

use App\Entity\Pipeline;
use App\Entity\PipelineEtape;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;

/**
 * Pipeline par défaut (Visio, Démo, Devis, Signature) : créé une seule fois.
 * Si un pipeline par défaut existe déjà (base de dev ou de test déjà initialisée),
 * les fixtures ne modifient rien.
 */
class PipelineFixtures extends Fixture
{
    public function load(ObjectManager $manager): void
    {
        $repository = $manager->getRepository(Pipeline::class);
        if (null !== $repository->findOneBy(['parDefaut' => true])) {
            return;
        }

        $pipeline = new Pipeline();
        $pipeline->setNom('Pipeline par défaut');
        $pipeline->setParDefaut(true);

        foreach (['Visio', 'Démo', 'Devis', 'Signature'] as $index => $nom) {
            $etape = new PipelineEtape();
            $etape->setNom($nom);
            $etape->setOrdre($index + 1);
            $pipeline->addEtape($etape);
        }

        $manager->persist($pipeline);
        $manager->flush();
    }
}
