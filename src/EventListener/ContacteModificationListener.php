<?php

namespace App\EventListener;

use App\Entity\Prospect;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\Persistence\ObjectManager;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Signe le basculement d'un prospect à l'état « Contacté » par l'utilisateur
 * qui l'a effectué : `prospect.contacte_par_id` accompagne `contacte` et
 * `datePremierContact` (« Contacté le … par … »).
 *
 * Tous les writers passent par `Prospect::setContacte()` (switch de la liste
 * et de la fiche, formulaire d'édition, formulaire public), donc l'audit vit
 * ici et ne dépend d'aucun contrôleur :
 *  - `contacte` devient vrai → auteur courant ;
 *  - `contacte` repasse à `false`/`null` → auteur vidé (plus de contact, donc
 *    plus de signataire) ;
 *  - un enregistrement qui ne touche pas `contacte` ne modifie rien : re-dater
 *    un contact existant ne réattribue pas la signature.
 *
 * L'auteur reste `null` hors session (import, commande, formulaire public) et
 * pour l'historique antérieur à l'audit.
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class ContacteModificationListener
{
    public function __construct(
        private Security $security,
    ) {
    }

    /**
     * Création : un prospect qui naît contacté (import, formulaire) est signé
     * par la session courante ; un prospect créé non contacté n'a pas
     * d'auteur.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Prospect) {
            return;
        }

        $this->tracer($entity, $args->getObjectManager());
    }

    /**
     * Modification : seul un changement réel du drapeau `contacte` réécrit
     * l'auteur.
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof Prospect || !$args->hasChangedField('contacte')) {
            return;
        }

        $this->tracer($entity, $args->getObjectManager());
    }

    private function tracer(Prospect $prospect, ObjectManager $om): void
    {
        if (true !== $prospect->getContacte()) {
            $prospect->setContactePar(null);

            return;
        }

        $prospect->setContactePar($this->auteur($om));
    }

    /**
     * L'utilisateur est résolu par l'unité de travail : la session peut
     * porter une instance détachée (après un `clear()`), qu'il ne faut pas
     * réintroduire telle quelle dans l'unité de travail.
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
