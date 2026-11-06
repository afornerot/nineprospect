<?php

namespace App\EventListener;

use App\Entity\ProspectProduit;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Date et signe chaque changement effectif de `ProspectProduit::qualifie`
 * (Non / Oui / Hors produit / Injoignable).
 *
 * Tous les writers (toggle, formulaire, import, affectation en masse,
 * formulaire public) passent par `setQualifie()` ; l'audit est posé ici pour
 * rester unique et ne dépendre d'aucun contrôleur. Un enregistrement sans
 * changement de valeur (formulaire soumis à l'identique) ne modifie pas
 * l'horodatage.
 *
 * L'auteur est l'utilisateur authentifié ; il reste null pour un changement
 * déclenché hors session (import, commande, formulaire public).
 */
#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
class QualifieModificationListener
{
    public function __construct(
        private Security $security,
    ) {
    }

    /**
     * Création d'un lien : s'il naît déjà tranché (« Oui », « Hors produit »
     * ou « Injoignable »), la décision est datée et signée. Créé « Non », il
     * reste sans audit.
     */
    public function prePersist(PrePersistEventArgs $args): void
    {
        $entity = $args->getObject();
        if ($entity instanceof ProspectProduit) {
            $this->tracer($entity);
        }
    }

    /**
     * Modification : l'audit ne réagit qu'à un changement réel de `qualifie`.
     *  - nouvelle valeur « Non » : plus de décision, date et auteur sont
     *    vidés ;
     *  - tout autre état : date du jour + auteur courant.
     * Doctrine recalcule le changeset après les listeners, les deux champs
     * d'audit sont donc bien persistés (dont la FK auteur).
     */
    public function preUpdate(PreUpdateEventArgs $args): void
    {
        $entity = $args->getObject();
        if (!$entity instanceof ProspectProduit || !$args->hasChangedField('qualifie')) {
            return;
        }

        $this->tracer($entity);
    }

    /**
     * État « Non » = aucune décision : pas de date ni d'auteur. Un état
     * tranché (« Oui », « Hors produit », « Injoignable ») est daté et signé.
     */
    private function tracer(ProspectProduit $entity): void
    {
        if (!$entity->getQualifie()->estTranchee()) {
            $entity->setQualifieModifieAt(null);
            $entity->setQualifieModifiePar(null);

            return;
        }

        $entity->setQualifieModifieAt(new \DateTime());
        $entity->setQualifieModifiePar($this->auteur());
    }

    private function auteur(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : null;
    }
}
