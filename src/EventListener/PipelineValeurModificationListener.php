<?php

namespace App\EventListener;

use App\Entity\PipelineValeur;
use App\Entity\User;
use App\Enum\PipelineStatut;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Signe chaque changement de statut d'une étape de pipeline (`pipeline_valeur`)
 * par l'utilisateur qui l'a fait, en complément de la date déjà posée par
 * `ProspectSprint::definirStatut()` / `definirValeur()`.
 *
 * Tous les writers (clic de cycle sur la liste et la fiche, formulaire
 * prospect) passent par ces deux méthodes d'entité, donc l'audit est posé ici
 * une seule fois et ne dépend d'aucun contrôleur. Une écriture qui ne change
 * pas le statut (édition d'une date seule, enregistrement à l'identique) ne
 * réécrit pas l'auteur.
 *
 * L'auteur est l'utilisateur authentifié ; il reste null hors session
 * (import, commande), l'affichage se réduit alors à la date.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class PipelineValeurModificationListener
{
    public function __construct(
        private Security $security,
    ) {
    }

    /**
     * Création d'une valeur : elle porte un statut décidé (ou une date seule,
     * auquel cas aucun changement de statut n'a eu lieu et l'auteur reste
     * vide).
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof PipelineValeur || PipelineStatut::NON_DEMARRE === $entity->getStatut()) {
            return;
        }

        $entity->setModifiePar($this->auteur($args->getObjectManager()));
    }

    /**
     * Modification : seul un changement réel de `statut` resigne la valeur.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof PipelineValeur || !$args->hasChangedField('statut')) {
            return;
        }

        $entity->setModifiePar($this->auteur($args->getObjectManager()));
    }

    /**
     * L'utilisateur est résolu par l'unité de travail : la session peut porter
     * une instance détachée (après un `clear()`), qu'il ne faut pas
     * réintroduire telle quelle dans l'unité de travail. `find()` réutilise
     * l'instance déjà managée quand elle existe.
     */
    private function auteur(ObjectManager $om): ?User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User || null === $user->getId()) {
            return null;
        }

        $gere = $om->find(User::class, $user->getId());

        return $gere instanceof User ? $gere : null;
    }
}
