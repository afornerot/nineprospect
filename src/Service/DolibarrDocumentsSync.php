<?php

namespace App\Service;

use App\Entity\Prospect;
use App\Entity\ProspectDocument;
use App\Repository\ObjectiveRepository;
use App\Repository\ProspectDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Mémorise localement les documents commerciaux Dolibarr d'un prospect :
 * uniquement les devis (propositions) et les commandes — les factures ne
 * sont pas récupérées.
 *
 * Synchro à l'ouverture de la fiche (toujours fraîche : le cache Dolibarr
 * est invalidé avant la lecture) et par le cron quotidien
 * (`app:dolibarr:refresh-documents`). L'association à un produit se fait
 * ensuite dans nineprospect, sans être jamais écrasée par une synchro.
 */
final class DolibarrDocumentsSync
{
    /** Clés retournées par {@see DolibarrApi::documentsForThirdparty()} → types persistés. */
    private const SECTIONS = [
        'proposals' => ProspectDocument::TYPE_DEVIS,
        'orders' => ProspectDocument::TYPE_COMMANDE,
    ];

    public function __construct(
        private readonly DolibarrApi $api,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
        private readonly ObjectiveRepository $objectives,
    ) {
    }

    /**
     * Plus ancienne date de début d'objectif : la synchro ne retient pas les
     * documents Dolibarr antérieurs (calculé une fois par process, null si
     * aucun objectif → pas de plancher).
     */
    private ?\DateTimeImmutable $plancher = null;

    private function plancherDocuments(): ?\DateTimeImmutable
    {
        if (null === $this->plancher) {
            $min = $this->objectives->createQueryBuilder('o')
                ->select('MIN(o.startDate)')
                ->getQuery()
                ->getSingleScalarResult();
            $this->plancher = null !== $min
                ? \DateTimeImmutable::createFromInterface(new \DateTime((string) $min))
                : new \DateTimeImmutable('0001-01-01');
        }

        return $this->plancher;
    }

    /**
     * Synchronise les documents du prospect. Retourne un libellé d'erreur
     * partiel si Dolibarr a répondu incomplètement (les types lisibles sont
     * quand même mémorisés), null si tout s'est bien passé.
     */
    public function synchroniser(Prospect $prospect): ?string
    {
        $tiersId = trim((string) $prospect->getIdDolibarr());
        $repository = $this->em->getRepository(ProspectDocument::class);

        // Tiers délié : les documents mémorisés ne correspondent plus à
        // une source vivante, on les retire.
        if ('' === $tiersId || !ctype_digit($tiersId) || !$this->api->isConfigured()) {
            $repository->supprimerParProspect($prospect);
            $this->em->flush();

            return null;
        }

        $auteurs = $this->auteursDolibarr();

        $this->api->invaliderCacheDocuments($tiersId);
        $documents = $this->api->documentsForThirdparty($tiersId, [] === $auteurs ? null : $auteurs);

        $existants = $this->em->createQueryBuilder()
            ->select('d')
            ->from(ProspectDocument::class, 'd')
            ->where('d.prospect = :prospect')
            ->setParameter('prospect', $prospect->getId())
            ->getQuery()
            ->getResult();

        // Index (type + id Dolibarr) → ligne existante.
        $cles = [];
        foreach ($existants as $existant) {
            $cles[$existant->getType().':'.$existant->getDolibarrId()] = $existant;
        }

        $vus = [];
        $maintenant = new \DateTime();
        foreach (self::SECTIONS as $section => $type) {
            foreach ($documents[$section] ?? [] as $doc) {
                $idDolibarr = (string) ($doc['id'] ?? '');
                if ('' === $idDolibarr) {
                    continue;
                }

                // Antérieur au premier objectif : ignoré (et purgé comme
                // « disparu » puisque non vu ci-dessous).
                if (null !== $doc['date'] && $doc['date'] < $this->plancherDocuments()->format('Y-m-d')) {
                    continue;
                }

                $cle = $type.':'.$idDolibarr;
                $vus[$cle] = true;

                $ligne = $cles[$cle] ?? (new ProspectDocument())
                    ->setProspect($prospect)
                    ->setType($type)
                    ->setDolibarrId($idDolibarr);
                $ligne
                    ->setRef((string) ($doc['ref'] ?? ''))
                    ->setDateDoc(null !== $doc['date'] ? new \DateTime((string) $doc['date']) : null)
                    ->setTypeLabel($doc['typeLabel'] ?? null)
                    ->setStatutLabel((string) ($doc['statutLabel'] ?? ''))
                    ->setStatutCouleur((string) ($doc['statutCouleur'] ?? 'secondary'))
                    ->setTotalHt($doc['totalHt'] ?? null)
                    ->setUrl($doc['url'] ?? null)
                    ->setSyncAt($maintenant);

                $this->em->persist($ligne);
            }
        }

        // Disparus de Dolibarr (supprimés, ou repassés brouillon) : retirés.
        foreach ($cles as $cle => $ligne) {
            if (!isset($vus[$cle])) {
                $this->em->remove($ligne);
            }
        }

        $this->em->flush();

        if (null !== ($documents['error'] ?? null)) {
            $this->logger->warning('Synchro documents Dolibarr incomplète', [
                'prospect' => $prospect->getId(),
                'erreur' => $documents['error'],
            ]);

            return (string) $documents['error'];
        }

        return null;
    }
    /**
     * Ids Dolibarr des commerciaux déclarés. Aucun déclaré → tableau vide,
     * interprété par l'appelant comme « pas de filtre ».
     *
     * @return list<string>
     */
    private function auteursDolibarr(): array
    {
        $commerciaux = $this->em->getRepository(\App\Entity\User::class)
            ->findBy(['commercial' => true]);

        $ids = [];
        foreach ($commerciaux as $commercial) {
            if (null !== $commercial->getDolibarrUserId()) {
                $ids[] = $commercial->getDolibarrUserId();
            }
        }

        return $ids;
    }

    /**
     * Synchronise tous les prospects liés à Dolibarr (cron quotidien).
     *
     * @return array{total: int, erreurs: int, messages: list<string>}
     */
    public function synchroniserTous(): array
    {
        $repository = $this->em->getRepository(Prospect::class);
        $ids = $repository->createQueryBuilder('p')
            ->select('p.id')
            ->where("p.idDolibarr IS NOT NULL AND p.idDolibarr <> ''")
            ->getQuery()
            ->getSingleColumnResult();

        $resultat = ['total' => 0, 'erreurs' => 0, 'messages' => []];
        foreach ($ids as $id) {
            $prospect = $repository->find((int) $id);
            if (null === $prospect) {
                continue;
            }

            ++$resultat['total'];
            try {
                $erreur = $this->synchroniser($prospect);
            } catch (\Throwable $e) {
                ++$resultat['erreurs'];
                $resultat['messages'][] = 'Prospect '.$prospect->getId().' : '.$e->getMessage();
                $this->logger->error('Synchro documents Dolibarr en échec', [
                    'prospect' => $prospect->getId(),
                    'exception' => $e,
                ]);
                $this->em->clear();
                continue;
            }

            if (null !== $erreur) {
                ++$resultat['erreurs'];
                $resultat['messages'][] = 'Prospect '.$prospect->getId().' : '.$erreur;
            }
            $this->em->clear();
        }

        return $resultat;
    }
}
