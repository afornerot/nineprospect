<?php

namespace App\Repository;

use App\Entity\Pipeline;
use App\Entity\PipelineValeur;
use App\Entity\Sprint;
use App\Service\Normalizer\LabelKey;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Pipeline>
 */
class PipelineRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Pipeline::class);
    }

    /**
     * @return array<int, Pipeline>
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('p')
            ->orderBy('p.parDefaut', \SortDirection::Descending)
            ->addOrderBy('p.nom', \SortDirection::Ascending)
            ->getQuery()
            ->getResult();
    }

    public function getDefault(): ?Pipeline
    {
        return $this->findOneBy(['parDefaut' => true]);
    }

    public function countSprints(int $pipelineId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(s.id)')
            ->from(Sprint::class, 's')
            ->where('s.pipeline = :pipeline')
            ->setParameter('pipeline', $pipelineId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Nombre de valeurs enregistrées sur une étape (bloque sa suppression).
     *
     * @param array<int> $etapeIds
     */
    public function countValeurs(array $etapeIds): int
    {
        if ([] === $etapeIds) {
            return 0;
        }

        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(v.id)')
            ->from(PipelineValeur::class, 'v')
            ->where('v.etape IN (:ids)')
            ->setParameter('ids', $etapeIds)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Valeurs d'étape de pipeline par mois et par **libellé d'étape**, tous
     * pipelines confondus : l'étape « Appel » du pipeline par défaut, d'« Essai »
     * et d'« EOLE » alimente la même courbe.
     *
     * Comme `pipeline_valeur` ne conserve qu'une ligne par couple
     * prospect↔vague↔étape, le comptage porte sur la **valeur courante** de
     * chaque étape, dans le mois de sa date : un prospect qui change deux fois
     * de statut dans le même mois ne compte qu'une fois, dans le mois de son
     * dernier changement. Tous les statuts sont comptés (« À faire » n'a
     * pas de ligne et n'apparaît donc jamais). Les valeurs sans date
     * (antérieures à l'audit) sont exclues, comme pour la qualification.
     *
     * Le regroupement se fait sur la clé normalisée du libellé
     * (`LabelKey::for`) ; la forme la plus fréquente du groupe sert de libellé
     * affiché, les casse/accents variants étant départagés en faveur d'une
     * graphie mêlant majuscule initiale et minuscules (« Appel » plutôt que
     * « APPEL » ou « appel »).
     *
     * @param int|null $userId restreint à l'auteur du changement
     *
     * @return array{
     *     mois: list<string>,
     *     series: list<array{label: string, data: list<int>}>
     * } « mois » = clés « YYYY-MM » triées, « series[i].data » aligné sur « mois »
     */
    public function countStatutsEtapeParMoisParLibelle(?int $userId = null): array
    {
        // Une seule requête, sans filtre d'auteur : le libellé affiché doit
        // être le même pour tous les intervenants, sans quoi la même courbe
        // changerait de nom selon l'utilisateur sélectionné.
        $lignes = $this->getEntityManager()->createQueryBuilder()
            ->select('e.nom AS libelle', 'v.date AS changement', 'IDENTITY(v.modifiePar) AS auteur')
            ->from(PipelineValeur::class, 'v')
            ->innerJoin('v.etape', 'e')
            ->andWhere('v.date IS NOT NULL')
            ->orderBy('e.nom', \SortDirection::Ascending)
            ->getQuery()
            ->getArrayResult();

        /** @var array<string, array<string, int>> $parLibelle */
        $parLibelle = [];
        /** @var array<string, true> $mois */
        $mois = [];

        foreach ($lignes as $ligne) {
            if (null !== $userId && (int) $ligne['auteur'] !== $userId) {
                continue;
            }

            $changement = $ligne['changement'];
            if (!$changement instanceof \DateTimeInterface) {
                $changement = new \DateTime((string) $changement);
            }

            $cle = $changement->format('Y-m');
            $groupe = LabelKey::for((string) $ligne['libelle']);
            if ('' === $groupe) {
                continue;
            }

            $mois[$cle] = true;
            $parLibelle[$groupe][$cle] = ($parLibelle[$groupe][$cle] ?? 0) + 1;
        }

        $cles = array_keys($mois);
        sort($cles);

        $series = [];
        foreach ($this->libellesCanoniques($lignes) as $groupe => $libelle) {
            $donnees = [];
            foreach ($cles as $cle) {
                $donnees[] = $parLibelle[$groupe][$cle] ?? 0;
            }
            $series[] = ['label' => $libelle, 'data' => $donnees];
        }

        return ['mois' => $cles, 'series' => $series];
    }

    /**
     * Libellé d'affichage de chaque groupe normalisé, calculé sur l'ensemble
     * des valeurs (indépendamment du filtre d'auteur) : la forme la plus
     * fréquente ; à égalité, la graphie « Majuscule initiale » (ni tout en
     * capitales, ni tout en minuscules), puis l'ordre alphabétique.
     *
     * @param array<int, array<string, mixed>> $lignes
     *
     * @return array<string, string> groupe normalisé => libellé affiché
     */
    private function libellesCanoniques(array $lignes): array
    {
        /** @var array<string, array<string, int>> $formes */
        $formes = [];
        foreach ($lignes as $ligne) {
            $nom = (string) $ligne['libelle'];
            $groupe = LabelKey::for($nom);
            if ('' !== $groupe) {
                $formes[$groupe][$nom] = ($formes[$groupe][$nom] ?? 0) + 1;
            }
        }

        $libelles = [];
        foreach ($formes as $groupe => $occurrences) {
            if ([] === $occurrences) {
                continue;
            }

            $candidats = array_keys($occurrences, max($occurrences), true);
            usort($candidats, static function (string $a, string $b): int {
                $rang = static fn (string $forme): int => mb_strtoupper($forme) === $forme ? 2
                    : (mb_strtolower($forme) === $forme ? 1 : 0);

                return [$rang($a), $a] <=> [$rang($b), $b];
            });
            $libelles[$groupe] = (string) $candidats[0];
        }
        asort($libelles);

        return $libelles;
    }
}
