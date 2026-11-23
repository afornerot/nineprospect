<?php

namespace App\Repository;

use App\Entity\Prospect;
use App\Entity\ProspectDocument;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProspectDocument>
 */
class ProspectDocumentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProspectDocument::class);
    }

    /**
     * Documents d'un prospect, groupés par type (Devis, Commande). Les
     * factures ne sont plus synchronisées : les éventuelles lignes
     * historiques sont ignorées (et purgées à la prochaine synchro).
     *
     * @return array<string, list<ProspectDocument>>
     */
    public function parProspect(Prospect $prospect): array
    {
        $documents = [
            ProspectDocument::TYPE_DEVIS => [],
            ProspectDocument::TYPE_COMMANDE => [],
        ];

        $lignes = $this->createQueryBuilder('d')
            ->where('d.prospect = :prospect')
            ->setParameter('prospect', $prospect->getId())
            ->orderBy('d.dateDoc', 'DESC')
            ->addOrderBy('d.id', 'DESC')
            ->getQuery()
            ->getResult();

        foreach ($lignes as $document) {
            if (isset($documents[$document->getType()])) {
                $documents[$document->getType()][] = $document;
            }
        }

        return $documents;
    }

    /**
     * Supprime tous les documents d'un prospect (déliaison Dolibarr, tiers
     * disparu). Retourne le nombre de lignes retirées.
     */
    public function supprimerParProspect(Prospect $prospect): int
    {
        return $this->createQueryBuilder('d')
            ->delete()
            ->where('d.prospect = :prospect')
            ->setParameter('prospect', $prospect->getId())
            ->getQuery()
            ->execute();
    }
}
