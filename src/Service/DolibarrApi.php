<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client HTTP **lecture seule** de l'API REST Dolibarr
 * (`{DOLIBARR_URL}/api/index.php`), utilisé pour lier un prospect à un tiers
 * existant.
 *
 * Authentification : clé API globale du produit (`DOLIBARR_API_KEY`), pas de
 * clé par utilisateur. L'intégration ne lit que des tiers ; aucun writer
 * Dolibarr n'existe côté nineprospect.
 *
 * Toutes les méthodes publiques sont tolérantes aux pannes : elles renvoient
 * une structure vide + `error` plutôt que de lever. `DOLIBARR_URL` ou
 * `DOLIBARR_API_KEY` vide → `isConfigured()` false et aucune requête émise.
 */
class DolibarrApi
{
    private const TIMEOUT = 5;

    /** Durée de mise en cache d'un tiers résolu (secondes). */
    private const TTL_THIRDPARTY = 600;

    /** Nombre de tiers renvoyés par recherche. */
    private const DEFAULT_LIMIT = 20;

    /** Longueur maximale du critère de recherche accepté. */
    private const MAX_QUERY_LENGTH = 80;

    /** Durée de mise en cache des documents d'un tiers (secondes). */
    private const TTL_DOCUMENTS = 300;

    /** Taille de page pour énumérer exhaustivement les documents d'un tiers. */
    private const DOCUMENTS_PAGE_SIZE = 100;

    /** Garde-fou : nombre maximal de pages par type (100 × 50 = 5000 docs). */
    private const DOCUMENTS_MAX_PAGES = 50;

    /**
     * Endpoints Dolibarr des documents commerciaux d'un tiers, avec leur
     * libellé d'affichage et le préfixe d'URL de leur fiche Dolibarr.
     *
     * @var array<string, array{label: string, url: string}>
     */
    private const DOCUMENT_TYPES = [
        'proposals' => ['label' => 'Devis', 'url' => '/comm/propal/card.php?id='],
        'orders' => ['label' => 'Commande', 'url' => '/commande/card.php?id='],
        'invoices' => ['label' => 'Facture', 'url' => '/compta/facture/card.php?facid='],
    ];

    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
        private readonly CacheInterface $cache,
        #[Autowire('%dolibarrUrl%')]
        private readonly ?string $url = null,
        #[Autowire('%dolibarrApiKey%')]
        private readonly ?string $apiKey = null,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== trim((string) $this->url) && '' !== trim((string) $this->apiKey);
    }

    /**
     * URL publique de la fiche du tiers dans l'interface Dolibarr.
     */
    public function cardUrl(?string $thirdpartyId): ?string
    {
        $id = trim((string) $thirdpartyId);
        if (!$this->isConfigured() || '' === $id) {
            return null;
        }

        return rtrim((string) $this->url, '/').'/societe/card.php?socid='.rawurlencode($id);
    }

    /**
     * Recherche de tiers par nom, code client ou code fournisseur.
     *
     * `sqlfilters` est construit ici à partir d'un critère assaini : les
     * caractères qui casseraient le filtre SQL (`'`, `\`) ou en élargiraient
     * le sens (`%`, `_`) sont retirés, et les mots sont chaînés par `%`.
     *
     * @return array{results: list<array<string, mixed>>, error: ?string}
     */
    public function search(string $query, int $limit = self::DEFAULT_LIMIT): array
    {
        if (!$this->isConfigured()) {
            return ['results' => [], 'error' => 'Intégration Dolibarr non configurée.'];
        }

        $filtre = $this->buildSqlFilters($query);
        if (null === $filtre) {
            return ['results' => [], 'error' => null];
        }

        $response = $this->request('thirdparties', [
            'limit' => max(1, min(100, $limit)),
            'sqlfilters' => $filtre,
        ]);

        if (null !== $response['error']) {
            return ['results' => [], 'error' => $response['error']];
        }

        return ['results' => $response['rows'], 'error' => null];
    }

    /**
     * Tiers par identifiant Dolibarr. Retourne la ligne normalisée, ou null
     * si le tiers n'existe pas / en cas d'erreur (l'appelant traite les deux
     * de la même façon : pas de liaison possible).
     *
     * @return array<string, mixed>|null
     */
    public function findById(string $thirdpartyId): ?array
    {
        $id = trim($thirdpartyId);
        if (!$this->isConfigured() || '' === $id || !ctype_digit($id)) {
            return null;
        }

        // Une fiche prospect affiche le tiers lié à chaque chargement :
        // le cache évite un aller-retour Dolibarr par affichage.
        return $this->cache->get(
            'dolibarr.thirdparty.'.$id,
            function (ItemInterface $item) use ($id): ?array {
                $item->expiresAfter(self::TTL_THIRDPARTY);

                $response = $this->request('thirdparties/'.$id);
                if (null !== $response['error'] || [] === $response['rows']) {
                    return null;
                }

                return $this->normalize($response['rows'][0]);
            },
        );
    }

    /**
     * Devis, commandes et factures d'un tiers, brouillons exclus, **sans
     * limite** : chaque type est paginé jusqu'à épuisement.
     *
     * Dolibarr expose un endpoint par type (`proposals`, `orders`,
     * `invoices`) et son `sqlfilters` ne connaît que les colonnes réelles :
     * le lien au tiers est `t.fk_soc` et le statut `t.fk_statut` (`:>:0`
     * écarte les brouillons, seuls documents à porter le statut 0).
     * Le tri se fait sur `t.rowid` (les colonnes `date*` ne sont pas
     * exposées au filtre de tri de cette API).
     *
     * @return array{
     *   proposals: list<array<string, mixed>>,
     *   orders: list<array<string, mixed>>,
     *   invoices: list<array<string, mixed>>,
     *   error: ?string
     * }
     */
    public function documentsForThirdparty(string $thirdpartyId): array
    {
        $vide = ['proposals' => [], 'orders' => [], 'invoices' => [], 'error' => null];

        $id = trim($thirdpartyId);
        if (!$this->isConfigured() || '' === $id || !ctype_digit($id)) {
            return $vide;
        }

        return $this->cache->get(
            'dolibarr.documents.'.$id,
            function (ItemInterface $item) use ($id, $vide): array {
                $item->expiresAfter(self::TTL_DOCUMENTS);

                $filtre = sprintf('(t.fk_soc:=:%s) and (t.fk_statut:>:0)', $id);
                $documents = $vide;

                foreach (self::DOCUMENT_TYPES as $type => $config) {
                    // Toute la liste, pas un échantillon : on pagine jusqu'à
                    // épuisement (le serveur Dolibarr plafonne chaque page).
                    $rows = [];
                    for ($page = 0; $page < self::DOCUMENTS_MAX_PAGES; ++$page) {
                        $response = $this->request($type, [
                            'limit' => self::DOCUMENTS_PAGE_SIZE,
                            'page' => $page,
                            'sqlfilters' => $filtre,
                            'sortfield' => 't.rowid',
                            'sortorder' => 'DESC',
                        ]);

                        if (null !== $response['error']) {
                            // Une panne partielle ne masque pas les autres types.
                            $documents['error'] ??= $response['error'];

                            break;
                        }

                        $rows = array_merge($rows, $response['rows']);
                        if (\count($response['rows']) < self::DOCUMENTS_PAGE_SIZE) {
                            break;
                        }
                    }

                    $documents[$type] = array_map(
                        fn (array $row): array => $this->normalizeDocument($row, $config['label'], $config['url']),
                        $rows,
                    );
                }

                return $documents;
            },
        );
    }

    /**
     * Aplatit un document Dolibarr (devis, commande, facture) pour le pavé
     * de la fiche prospect : référence, date, statut et montant HT.
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *   id: string, type: string, typeLabel: ?string, ref: string,
     *   date: ?string, statutLabel: string, statutCouleur: string,
     *   totalHt: ?float, url: ?string
     * }
     */
    public function normalizeDocument(array $row, string $type, string $urlPrefix): array
    {
        $statut = (int) ($row['statut'] ?? $row['fk_statut'] ?? 0);
        $typeFacture = 'Facture' === $type ? (int) ($row['type'] ?? 0) : 0;

        return [
            'id' => (string) ($row['id'] ?? ''),
            'type' => $type,
            // Avoir / acompte / pro forma sont des factures Dolibarr : on
            // nomme le sous-type pour éviter de les confondre avec une
            // facture définitive.
            'typeLabel' => 'Facture' === $type ? self::libelleTypeFacture($typeFacture) : null,
            'ref' => (string) ($row['ref'] ?? ''),
            'date' => $this->timestampToDate($row['date'] ?? null),
            'statutLabel' => self::statutLabel($type, $statut),
            'statutCouleur' => self::statutCouleur($type, $statut),
            'totalHt' => $this->montant($row['total_ht'] ?? null),
            'url' => '' !== (string) ($row['id'] ?? '') && $this->isConfigured()
                ? rtrim((string) $this->url, '/').$urlPrefix.(string) $row['id']
                : null,
        ];
    }

    /**
     * Sous-type d'une facture Dolibarr (`type` 0..4).
     */
    private static function libelleTypeFacture(int $type): string
    {
        return match ($type) {
            1 => 'Remplacement',
            2 => 'Avoir',
            3 => 'Acompte',
            4 => 'Pro forma',
            default => 'Facture',
        };
    }

    /**
     * Libellé du statut, aligné sur les écrans Dolibarr (mêmes valeurs).
     */
    private static function statutLabel(string $type, int $statut): string
    {
        return match ($type) {
            'Devis' => match ($statut) {
                1 => 'Validée',
                2 => 'Signée',
                3 => 'Close',
                4 => 'Archivée',
                default => 'Brouillon',
            },
            'Commande' => match ($statut) {
                1 => 'Validée',
                2 => 'Envoyée',
                3 => 'Réceptionnée',
                default => 'Brouillon',
            },
            default => match ($statut) {
                1 => 'Validée',
                2 => 'Payée',
                3 => 'Annulée',
                default => 'Brouillon',
            },
        };
    }

    private static function statutCouleur(string $type, int $statut): string
    {
        return match ($type) {
            'Devis' => match ($statut) {
                1 => 'primary',
                2 => 'warning',
                3 => 'success',
                4 => 'dark',
                default => 'secondary',
            },
            'Commande' => match ($statut) {
                1 => 'primary',
                2 => 'info',
                3 => 'success',
                default => 'secondary',
            },
            default => match ($statut) {
                1 => 'primary',
                2 => 'success',
                3 => 'danger',
                default => 'secondary',
            },
        };
    }

    /**
     * Dolibarr renvoie des timestamps Unix (parfois `null` ou `''`).
     */
    private function timestampToDate(mixed $value): ?string
    {
        if (!is_numeric($value)) {
            return null;
        }

        $ts = (int) $value;
        if ($ts <= 0) {
            return null;
        }

        return (new \DateTimeImmutable('@'.$ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d');
    }

    private function montant(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    /**
     * Aplatit une réponse brute de l'API (champs Dolibarr → champs écran).
     *
     * @param array<string, mixed> $row
     *
     * @return array{
     *   id: string, nom: string, alias: ?string, email: ?string,
     *   codeClient: ?string, codeFournisseur: ?string,
     *   siren: ?string, siret: ?string,
     *   adresse: ?string, codePostal: ?string, ville: ?string, pays: ?string,
     *   types: list<string>
     * }
     */
    public function normalize(array $row): array
    {
        $nom = $this->str($row['name'] ?? null);
        $alias = $this->str($row['name_alias'] ?? null);

        return [
            'id' => (string) ($row['id'] ?? ''),
            'nom' => $nom ?? $alias ?? '',
            'alias' => $alias,
            'email' => $this->str($row['email'] ?? null),
            'codeClient' => $this->str($row['code_client'] ?? null),
            'codeFournisseur' => $this->str($row['code_fournisseur'] ?? null),
            'siren' => $this->str($row['idprof1'] ?? $row['siren'] ?? null),
            'siret' => $this->str($row['idprof2'] ?? $row['siret'] ?? null),
            'adresse' => $this->str($row['address'] ?? null),
            'codePostal' => $this->str($row['zip'] ?? null),
            'ville' => $this->str($row['town'] ?? null),
            'pays' => $this->str($row['country_code'] ?? null),
            'types' => $this->types($row),
        ];
    }

    /**
     * Libellés des rôles du tiers. Dolibarr encode `client` (`1` client,
     * `2` prospect, `3` client et prospect) et `fournisseur` (`1` oui,
     * `3` oui et sous-traitant).
     *
     * @param array<string, mixed> $row
     *
     * @return list<string>
     */
    private function types(array $row): array
    {
        $client = (string) ($row['client'] ?? '0');
        $fournisseur = (string) ($row['fournisseur'] ?? '0');

        $types = [];
        if ('1' === $client) {
            $types[] = 'Client';
        } elseif ('2' === $client) {
            $types[] = 'Prospect';
        } elseif ('3' === $client) {
            $types[] = 'Client/Prospect';
        }
        if ('1' === $fournisseur) {
            $types[] = 'Fournisseur';
        } elseif ('3' === $fournisseur) {
            $types[] = 'Fournisseur/Sous-traitant';
        }

        return $types;
    }

    /**
     * Construit le filtre `sqlfilters` Dolibarr, ou null si le critère ne
     * contient aucun terme exploitable.
     */
    private function buildSqlFilters(string $query): ?string
    {
        $query = mb_substr(trim($query), 0, self::MAX_QUERY_LENGTH);
        // Retire ce qui casserait la syntaxe `sqlfilters` (apostrophe, anti-slash)
        // ou détournerait le LIKE (`%`, `_`).
        $query = str_replace(["'", '\\', '%', '_'], ' ', $query);
        $mots = array_values(array_filter(
            preg_split('/\s+/u', $query) ?: [],
            static fn (string $mot): bool => '' !== $mot,
        ));
        if ([] === $mots) {
            return null;
        }

        $like = "'%".implode('%', $mots)."%'";

        return sprintf(
            '(t.nom:like:%1$s) or (t.code_client:like:%1$s) or (t.code_fournisseur:like:%1$s)',
            $like,
        );
    }

    /**
     * Exécute un GET sur l'API. `rows` est toujours une liste de lignes
     * brutes ; une réponse 404 « no result » n'est pas une erreur.
     *
     * @param array<string, scalar> $query
     *
     * @return array{rows: list<array<string, mixed>>, error: ?string}
     */
    private function request(string $endpoint, array $query = []): array
    {
        $url = rtrim((string) $this->url, '/').'/api/index.php/'.$endpoint;

        try {
            $response = $this->http->request('GET', $url, [
                'query' => $query,
                'headers' => [
                    'DOLAPIKEY' => trim((string) $this->apiKey),
                    'Accept' => 'application/json',
                ],
                'timeout' => self::TIMEOUT,
            ]);
            $status = $response->getStatusCode();
            $body = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->error('Dolibarr: appel {endpoint} en échec : {err}', [
                'endpoint' => $endpoint,
                'err' => $e->getMessage(),
            ]);

            return ['rows' => [], 'error' => 'Dolibarr injoignable : '.$e->getMessage()];
        }

        if (isset($body['error'])) {
            $message = \is_array($body['error'])
                ? (string) ($body['error']['message'] ?? '')
                : (string) $body['error'];

            // 404 = « aucun tiers trouvé » côté Dolibarr : résultat vide, pas une panne.
            if (404 === $status) {
                return ['rows' => [], 'error' => null];
            }

            $this->logger->warning('Dolibarr: {endpoint} HTTP {status} : {msg}', [
                'endpoint' => $endpoint,
                'status' => $status,
                'msg' => $message,
            ]);

            return ['rows' => [], 'error' => '' !== $message ? $message : sprintf('HTTP %d', $status)];
        }

        if (200 !== $status) {
            return ['rows' => [], 'error' => sprintf('HTTP %d', $status)];
        }

        // Un GET unitaire renvoie un objet ; une recherche renvoie une liste.
        if (isset($body['id'])) {
            return ['rows' => [$body], 'error' => null];
        }

        if (!array_is_list($body)) {
            return ['rows' => [], 'error' => null];
        }

        $rows = [];
        foreach ($body as $row) {
            if (\is_array($row)) {
                $rows[] = $row;
            }
        }

        return ['rows' => $rows, 'error' => null];
    }

    private function str(mixed $value): ?string
    {
        if (!\is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
