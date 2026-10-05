<?php

namespace App\Repository;

use App\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    public function findOneByLeadId(string $leadId): ?Contact
    {
        return $this->findOneBy(['leadId' => $leadId]);
    }

    /**
     * Contacts d'un prospect, triés par nom + prénom.
     *
     * @return array<Contact>
     */
    public function findByProspect(int $prospectId): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.prospect = :pid')
            ->setParameter('pid', $prospectId)
            ->orderBy('c.nom', 'ASC')
            ->addOrderBy('c.prenom', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Contacts existants pour ces emails, indexés par email (lowercase).
     * Utilisé pour détecter les doublons de Contact à l'import.
     *
     * @param list<string> $emails
     *
     * @return array<string, Contact>
     */
    public function findByEmails(array $emails): array
    {
        if ([] === $emails) {
            return [];
        }

        $contacts = $this->createQueryBuilder('c')
            ->where('c.email IN (:emails)')
            ->setParameter('emails', $emails)
            ->getQuery()
            ->getResult();

        $out = [];
        foreach ($contacts as $c) {
            $email = $c->getEmail();
            if (null !== $email) {
                $out[$email] = $c;
            }
        }

        return $out;
    }
}
