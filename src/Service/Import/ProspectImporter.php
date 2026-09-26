<?php

namespace App\Service\Import;

use App\Entity\Action;
use App\Entity\Campagne;
use App\Entity\Contact;
use App\Entity\Prospect;
use App\Entity\ProspectSprint;
use App\Entity\Sprint;
use App\Enum\PipelineStatut;
use App\Repository\CampagneRepository;
use App\Repository\PipelineRepository;
use App\Repository\ProspectRepository;
use App\Repository\SprintRepository;
use App\Service\Geo\GeoResolver;
use App\Service\Normalizer\CompanyKey;
use App\Service\Normalizer\DateParser;
use App\Service\Normalizer\EmailNormalizer;
use App\Service\Normalizer\NameSplitter;
use App\Service\Normalizer\PhoneNormalizer;
use App\Service\Spreadsheet\CsvReader;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Import de la feuille "Prospects" (export publicitaire + suivi commercial).
 *
 * Règles :
 *  - un prospect = une entreprise (groupement sur clé normalisée) ;
 *    les libellés "Particulier / À son compte..." donnent 1 prospect par ligne ;
 *  - un contact = une ligne de la feuille, upsert sur leadId puis email ;
 *  - le contact le plus complet devient contact principal ;
 *  - les valeurs sont normalisées, les problèmes signalés (jamais supprimés).
 */
final class ProspectImporter
{
    private const BLOC_PAR_PAQUET = 50;

    /**
     * Libellé d'en-tête normalisé => champ canonique.
     *
     * @var array<string, string>
     */
    private const COLONNES = [
        'lead id' => 'leadId',
        'created time' => 'createdAt',
        'created date' => 'createdAtSecondaire',
        'ad id' => 'adId',
        'campaign id' => 'campaignId',
        'account id' => 'accountId',
        'form id' => 'formId',
        'form name' => 'formName',
        'prenom' => 'prenom',
        'nom' => 'nom',
        'adresse e mail' => 'email',
        'url du profil linkedin' => 'linkedin',
        'n de telephone' => 'telephone',
        'code postal' => 'codePostal',
        'poste' => 'poste',
        'nom de l entreprise' => 'entreprise',
        'bureau d etude interne' => 'bureauEtude',
        'contact' => 'contact',
        'date' => 'date',
        'quali' => 'quali',
        'sprint' => 'sprint',
        'rq' => 'rq',
        'rappel' => 'rappel',
        'leads quafilie' => 'qualifie',
        'visio' => 'visio',
        'demo' => 'demo',
        'devis' => 'devis',
        'signature' => 'signature',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private CsvReader $lecteur,
        private DateParser $dates,
        private PhoneNormalizer $telephones,
        private EmailNormalizer $emails,
        private GeoResolver $geo,
        private ProspectRepository $prospects,
        private CampagneRepository $campagnes,
        private SprintRepository $sprints,
        private PipelineRepository $pipelines,
        private LoggerInterface $logger,
    ) {
    }

    public function importer(string $chemin, bool $reset = false, bool $dryRun = false): ImportReport
    {
        $rapport = new ImportReport();

        if ($reset) {
            $this->reinitialiser($rapport);
        }

        $lignes = $this->lecteur->read($chemin);
        [$indexEntetes, $colonnes] = $this->localiserEntetes($lignes);

        $donnees = [];
        foreach ($lignes as $numeroLigne => $ligne) {
            if ($numeroLigne <= $indexEntetes) {
                continue;
            }

            $champs = $this->extraireChamps($ligne, $colonnes);
            if ([] === $champs) {
                ++$rapport->lignesIgnorees;

                continue;
            }

            $donnees[] = $champs;
        }

        $rapport->lignesLues = count($donnees);

        $groupes = $this->regrouper($donnees);
        $doublonsEmail = $this->detecterDoublonsEmail($groupes);

        $existants = $this->chargerEtatExistant();

        $numeroGroupe = 0;
        foreach ($groupes as $cle => $groupe) {
            $this->importerGroupe($groupe, $existants, $rapport, $doublonsEmail[$cle] ?? []);

            if (0 === ++$numeroGroupe % self::BLOC_PAR_PAQUET && !$dryRun) {
                $this->em->flush();
            }
        }

        if ($dryRun) {
            $this->em->clear();
        } else {
            $this->em->flush();
        }

        $this->logger->info('Import prospects : '.$rapport->resume(), $rapport->toArray());

        return $rapport;
    }

    /**
     * @param list<list<string>> $lignes
     *
     * @return array{0: int, 1: array<string, int>} index de la ligne d'en-tête, map champ => index
     */
    private function localiserEntetes(array $lignes): array
    {
        foreach ($lignes as $indexLigne => $ligne) {
            $colonnes = [];
            $trouve = 0;

            foreach ($ligne as $indexCellule => $cellule) {
                $entete = $this->normaliserEntete($cellule);

                if ('' === $entete) {
                    if (!isset($colonnes['notes'])) {
                        $colonnes['notes'] = $indexCellule;
                    }

                    continue;
                }

                $champ = self::COLONNES[$entete] ?? null;
                if (null !== $champ) {
                    $colonnes[$champ] = $indexCellule;
                    ++$trouve;
                }
            }

            if ($trouve >= 5) {
                return [$indexLigne, $colonnes];
            }
        }

        throw new \RuntimeException('En-tête de colonnes introuvable dans le fichier.');
    }

    /**
     * @param list<string>       $ligne
     * @param array<string, int> $colonnes
     *
     * @return array<string, string>
     */
    private function extraireChamps(array $ligne, array $colonnes): array
    {
        $champs = [];
        foreach ($colonnes as $champ => $index) {
            $champs[$champ] = trim($ligne[$index] ?? '');
        }

        if (!array_filter($champs, static fn (string $v): bool => '' !== $v)) {
            return [];
        }

        return $this->reparerBloc($champs);
    }

    /**
     * Les 6 lignes collées depuis un export Meta de format différent ont leurs
     * colonnes décalées (valeurs "l:", "ag:", "as:", "c:"). On remet en place.
     *
     * @param array<string, string> $champs
     *
     * @return array<string, string>
     */
    private function reparerBloc(array $champs): array
    {
        $decale = str_starts_with($champs['createdAtSecondaire'] ?? '', 'ag:')
            || str_starts_with($champs['leadId'] ?? '', 'l:')
            || str_starts_with($champs['campaignId'] ?? '', 'as:');

        if (!$decale) {
            unset($champs['createdAtSecondaire']);

            return $champs;
        }

        $champs['leadId'] = $this->retirerPrefixe($champs['leadId'] ?? '', ['l:']);
        $champs['createdAt'] = $champs['createdAt'] ?? '';
        $champs['adId'] = $this->retirerPrefixe($champs['createdAtSecondaire'] ?? '', ['ag:', 'as:']);
        $champs['campaignId'] = $this->retirerPrefixe($champs['formId'] ?? '', ['c:', 'as:']);
        $champs['formId'] = $champs['testLead'] ?? ($champs['formId'] ?? '');
        $champs['formName'] = $champs['formName'] ?? '';
        unset($champs['createdAtSecondaire'], $champs['testLead'], $champs['accountId']);

        return $champs;
    }

    /**
     * Regroupe les lignes par entreprise (ou 1 prospect/ligne pour un particulier).
     *
     * @param list<array<string, string>> $donnees
     *
     * @return array<string, array<string, mixed>>
     */
    private function regrouper(array $donnees): array
    {
        $groupes = [];

        foreach ($donnees as $numeroLigne => $champs) {
            $entreprise = $champs['entreprise'] ?? '';
            $prenom = $champs['prenom'] ?? '';
            $nom = $champs['nom'] ?? '';
            $decoupe = NameSplitter::split($prenom, $nom);

            $estParticulier = '' === $entreprise || CompanyKey::estParticulier($entreprise);

            if ($estParticulier) {
                $suffixe = '' !== ($champs['email'] ?? '') ? $champs['email'] : $decoupe['complet'];
                $cle = 'individu:'.CompanyKey::for($suffixe.' '.$entreprise);
                $nomGroupe = '' !== $decoupe['complet'] ? $decoupe['complet'] : $entreprise;
            } else {
                $cle = CompanyKey::for($entreprise);
                $nomGroupe = $entreprise;
            }

            if ('' === $cle) {
                $cle = 'ligne:'.$numeroLigne;
            }

            if (!isset($groupes[$cle])) {
                $groupes[$cle] = [
                    'cle' => $cle,
                    'nom' => '' !== $nomGroupe ? $nomGroupe : $decoupe['complet'],
                    'particulier' => $estParticulier,
                    'lignes' => [],
                ];
            }

            $groupes[$cle]['lignes'][] = $champs;
        }

        return $groupes;
    }

    /**
     * Emails présents dans plusieurs groupes de prospects.
     *
     * @param array<string, array<string, mixed>> $groupes
     *
     * @return array<string, list<string>>
     */
    private function detecterDoublonsEmail(array $groupes): array
    {
        $parEmail = [];
        foreach ($groupes as $cle => $groupe) {
            foreach ($groupe['lignes'] as $champs) {
                $email = $this->emails->normalize($champs['email'] ?? '');
                if (null !== $email) {
                    $parEmail[$email][] = $cle;
                }
            }
        }

        $doublons = [];
        foreach ($parEmail as $email => $cles) {
            $uniques = array_values(array_unique($cles));
            if (count($uniques) > 1) {
                foreach ($uniques as $cle) {
                    $doublons[$cle][] = $email;
                }
            }
        }

        return $doublons;
    }

    /**
     * @return array{prospects: array<string, Prospect>, parLead: array<string, Contact>, parCleEmail: array<string, Contact>, parCleNom: array<string, Contact>, actions: array<string, array<string, bool>>, campagnes: array<string, Campagne>, sprints: array<int, Sprint>}
     */
    private function chargerEtatExistant(): array
    {
        $prospectsExistants = [];
        $parLead = [];
        $parCleEmail = [];
        $parCleNom = [];
        $actions = [];
        $campagnes = [];
        $sprints = [];

        $prospects = $this->prospects->createQueryBuilder('p')
            ->leftJoin('p.contacts', 'c')->addSelect('c')
            ->leftJoin('p.actions', 'a')->addSelect('a')
            ->leftJoin('p.campagne', 'ca')->addSelect('ca')
            ->leftJoin('p.sprints', 'ps')->addSelect('ps')
            ->leftJoin('ps.sprint', 's')->addSelect('s')
            ->getQuery()
            ->getResult();

        foreach ($prospects as $prospect) {
            $cle = (string) $prospect->getCleEntreprise();
            $prospectsExistants[$cle] = $prospect;

            foreach ($prospect->getContacts() as $contact) {
                $this->indexerContact($contact, $cle, $parLead, $parCleEmail, $parCleNom);
            }

            foreach ($prospect->getActions() as $action) {
                $actions[$cle][$this->cleAction($action)] = true;
            }
        }

        foreach ($this->campagnes->findAll() as $campagne) {
            $campagnes[(string) $campagne->getExternalId()] = $campagne;
        }

        foreach ($this->sprints->findAll() as $sprint) {
            if (null !== $sprint->getNumero()) {
                $sprints[$sprint->getNumero()] = $sprint;
            }
        }

        return [
            'prospects' => $prospectsExistants,
            'parLead' => $parLead,
            'parCleEmail' => $parCleEmail,
            'parCleNom' => $parCleNom,
            'actions' => $actions,
            'campagnes' => $campagnes,
            'sprints' => $sprints,
        ];
    }

    /**
     * @param array<string, Contact> $parLead
     * @param array<string, Contact> $parCleEmail
     * @param array<string, Contact> $parCleNom
     */
    private function indexerContact(Contact $contact, string $cle, array &$parLead, array &$parCleEmail, array &$parCleNom): void
    {
        $leadId = $contact->getLeadId();
        if (null !== $leadId) {
            $parLead[$leadId] = $contact;
        }

        $email = $this->emails->normalize($contact->getEmail());
        if (null !== $email) {
            $parCleEmail[$cle.'|'.$email] = $contact;
        }

        $parCleNom[$cle.'|'.CompanyKey::for((string) $contact->getNomComplet())] = $contact;
    }

    /**
     * @param array<string, mixed> $groupe
     * @param array<string, mixed> $etat
     * @param list<string>         $emailsDoublons
     */
    private function importerGroupe(array $groupe, array &$etat, ImportReport $rapport, array $emailsDoublons): void
    {
        /** @var list<array<string, string>> $lignes */
        $lignes = $groupe['lignes'];
        $cle = (string) $groupe['cle'];

        $lignes = $this->trier($lignes);
        $principale = $this->lignePrincipale($lignes);

        $prospect = $etat['prospects'][$cle] ?? null;
        $nouveau = null === $prospect;

        if ($nouveau) {
            $prospect = new Prospect();
            $prospect->setCleEntreprise($cle);
            $prospect->setNom((string) $groupe['nom']);
            $etat['prospects'][$cle] = $prospect;
            ++$rapport->prospectsCrees;
        } else {
            ++$rapport->prospectsMaj;
        }

        $anomalies = [];

        $prospect->setAnomalie(false)->setAnomalieMotif(null);

        $this->appliquerSuivi($prospect, $lignes, $anomalies);
        $this->appliquerGEO($prospect, $lignes, $anomalies);
        $this->appliquerCampagne($prospect, $lignes, $principale, $etat, $rapport, $anomalies);
        $this->appliquerSprints($prospect, $lignes, $etat, $rapport, $anomalies);

        foreach (array_merge($this->anomaliesContacts($lignes), $emailsDoublons) as $anomalie) {
            $anomalies[] = $anomalie;
        }

        if ($nouveau) {
            $this->em->persist($prospect);
        }

        $this->importerContacts($prospect, $lignes, $principale, $etat, $rapport);
        $this->importerActions($prospect, $lignes, $etat, $rapport, $anomalies);

        foreach ($anomalies as $anomalie) {
            $prospect->addAnomalie($anomalie);
        }

        if ([] !== $anomalies) {
            ++$rapport->anomalies;
        }
    }

    /**
     * @param list<array<string, string>> $lignes
     * @param list<string>                $anomalies
     */
    private function appliquerSuivi(Prospect $prospect, array $lignes, array &$anomalies): void
    {
        $valeur = $this->derniereValeur($lignes, 'entreprise');
        if (null !== $valeur && '' === (string) $prospect->getNom()) {
            $prospect->setNom($valeur);
        }

        $contacte = $this->derniereValeur($lignes, 'contact');
        if (null !== $contacte) {
            $prospect->setContacte($this->boolOuAnomalie($contacte, 'Contact', $anomalies));
        }

        $dateContact = $this->derniereValeur($lignes, 'date');
        if (null !== $dateContact) {
            $premierContact = $this->dates->parseCourt($dateContact, $this->anneeReference($lignes));
            if (null === $premierContact) {
                $anomalies[] = 'date de premier contact illisible "'.$dateContact.'"';
            }
            $prospect->setDatePremierContact($premierContact);
        }

        $quali = $this->derniereValeur($lignes, 'quali');
        if (null !== $quali) {
            $prospect->setQuali(mb_substr($quali, 0, 255));
        }

        $qualifie = $this->derniereValeur($lignes, 'qualifie');
        if (null !== $qualifie) {
            $prospect->setQualifie($this->boolOuAnomalie($qualifie, 'Leads qualifiés', $anomalies));
        }

        $bureau = $this->derniereValeur($lignes, 'bureauEtude');
        if (null !== $bureau) {
            $prospect->setBureauEtudeInterne($this->boolOuAnomalie($bureau, 'Bureau d\'étude interne', $anomalies));
        }

        $notes = $this->derniereValeur($lignes, 'notes');
        if (null !== $notes) {
            $prospect->setNotes($notes);
        }
    }

    /**
     * @param list<array<string, string>> $lignes
     * @param list<string>                $anomalies
     */
    private function appliquerGEO(Prospect $prospect, array $lignes, array &$anomalies): void
    {
        foreach (array_reverse($lignes) as $ligne) {
            $brut = $ligne['codePostal'] ?? '';
            if ('' === $brut) {
                continue;
            }

            $geo = $this->geo->resolve($brut);
            $prospect->setCodePostal($geo['codePostal']);
            $prospect->setVille($geo['ville']);
            $prospect->setDepartement($geo['departement']);

            if (null !== $geo['anomalie']) {
                $anomalies[] = $geo['anomalie'];
            }

            return;
        }
    }

    /**
     * @param list<array<string, string>> $lignes
     * @param array<string, string>       $principale
     * @param array<string, mixed>        $etat
     * @param list<string>                $anomalies
     */
    private function appliquerCampagne(Prospect $prospect, array $lignes, array $principale, array &$etat, ImportReport $rapport, array &$anomalies): void
    {
        $externes = [];
        foreach ($lignes as $ligne) {
            $externe = trim((string) ($ligne['campaignId'] ?? ''));
            if ('' !== $externe) {
                $externes[$externe] = trim((string) ($ligne['formName'] ?? ''));
            }
        }

        foreach ($externes as $externe => $libelleForm) {
            if (isset($etat['campagnes'][$externe])) {
                continue;
            }

            $campagne = new Campagne();
            $campagne->setExternalId($externe);
            $campagne->setNom($externe);
            $campagne->setSourceLabel('' !== $libelleForm ? mb_substr($libelleForm, 0, 180) : null);
            $this->em->persist($campagne);
            $etat['campagnes'][$externe] = $campagne;
            ++$rapport->campagnesCreees;
        }

        $externe = trim((string) ($principale['campaignId'] ?? ''));
        $prospect->setCampagne('' !== $externe ? $etat['campagnes'][$externe] : null);

        if (count($externes) > 1) {
            $anomalies[] = 'campagnes multiples : '.implode(' / ', array_keys($externes));
        }
    }

    /**
     * Chaque ligne CSV appartient à une vague : on crée le lien prospect↔vague
     * et on y reporte le pipeline de cette ligne. Plusieurs vagues pour un même
     * prospect est un cas normal (relance en vague suivante), pas une anomalie.
     *
     * @param list<array<string, string>> $lignes
     * @param array<string, mixed>        $etat
     * @param list<string>                $anomalies
     */
    private function appliquerSprints(Prospect $prospect, array $lignes, array &$etat, ImportReport $rapport, array &$anomalies): void
    {
        foreach ($lignes as $ligne) {
            $brut = trim((string) ($ligne['sprint'] ?? ''));
            if ('' === $brut || !ctype_digit($brut)) {
                if ($this->ligneAPipeline($ligne)) {
                    $anomalies[] = 'pipeline hors vague ignoré (ligne sans numéro de vague)';
                }

                continue;
            }

            $numero = (int) $brut;
            $sprint = $etat['sprints'][$numero] ?? null;

            if (null === $sprint) {
                $sprint = new Sprint();
                $sprint->setNumero($numero);
                $sprint->setPipeline($this->pipelines->getDefault());
                $this->em->persist($sprint);
                $etat['sprints'][$numero] = $sprint;
                ++$rapport->sprintsCrees;
            }

            $lien = $prospect->lienPour($sprint);
            if (null === $lien) {
                $lien = new ProspectSprint();
                $lien->setSprint($sprint);
                $prospect->addSprint($lien);
            }

            $this->appliquerPipeline($lien, $ligne, $anomalies);
        }
    }

    /**
     * Pipeline d'une ligne sur sa propre vague : les colonnes de la feuille
     * (visio, démo, devis, signature) sont rattachées aux étapes du pipeline
     * de la vague par nom normalisé. Une colonne sans étape correspondante
     * (pipeline différent du pipeline par défaut) devient une anomalie.
     *
     * @param array<string, string> $ligne
     * @param list<string>          $anomalies
     */
    private function appliquerPipeline(ProspectSprint $lien, array $ligne, array &$anomalies): void
    {
        $parNom = [];
        foreach ($lien->getSprint()?->getPipeline()?->getEtapes() ?? [] as $etape) {
            $parNom[$this->normaliserEtape((string) $etape->getNom())] = $etape;
        }

        foreach (['visio' => 'Visio', 'demo' => 'Démo', 'devis' => 'Devis', 'signature' => 'Signature'] as $champ => $libelle) {
            $brut = trim((string) ($ligne[$champ] ?? ''));
            if ('' === $brut) {
                continue;
            }

            $statut = PipelineStatut::fromRaw($brut);
            if (null === $statut) {
                $this->ajouterAnomalie($anomalies, $libelle.' : valeur non reconnue "'.$brut.'"');

                continue;
            }

            $etape = $parNom[$this->normaliserEtape($libelle)] ?? null;
            if (null === $etape) {
                $numero = $lien->getSprint()?->getNumero();
                $this->ajouterAnomalie($anomalies, 'étape "'.$libelle.'" absente du pipeline de la vague '.$numero);

                continue;
            }

            $lien->definirValeur($etape, $statut, null);
        }
    }

    /**
     * Normalisation d'un libellé d'étape pour le rapprochement avec les
     * en-têtes de la feuille (« Démo » => « demo »).
     */
    private function normaliserEtape(string $libelle): string
    {
        $libelle = strtr($libelle, [
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Î' => 'I', 'Ï' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C',
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);

        return str_replace(' ', '', mb_strtolower(trim($libelle)));
    }

    /**
     * @param list<string> $anomalies
     */
    private function ajouterAnomalie(array &$anomalies, string $message): void
    {
        if (!in_array($message, $anomalies, true)) {
            $anomalies[] = $message;
        }
    }

    /**
     * @param array<string, string> $ligne
     */
    private function ligneAPipeline(array $ligne): bool
    {
        foreach (['visio', 'demo', 'devis', 'signature'] as $champ) {
            if ('' !== trim((string) ($ligne[$champ] ?? ''))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, string>> $lignes
     * @param array<string, string>       $principale
     * @param array<string, mixed>        $etat
     */
    private function importerContacts(Prospect $prospect, array $lignes, array $principale, array &$etat, ImportReport $rapport): void
    {
        $cle = (string) $prospect->getCleEntreprise();
        $candidatPrincipal = null;
        $scorePrincipal = -1;
        $dejaPrincipal = false;

        foreach ($prospect->getContacts() as $contactExistant) {
            if (true === $contactExistant->isEstPrincipal()) {
                $dejaPrincipal = true;
                break;
            }
        }

        foreach ($lignes as $ligne) {
            $decoupe = NameSplitter::split((string) ($ligne['prenom'] ?? ''), (string) ($ligne['nom'] ?? ''));
            $nomComplet = $decoupe['complet'];
            if ('' === $nomComplet && '' === ($ligne['email'] ?? '')) {
                continue;
            }

            $leadId = trim($ligne['leadId'] ?? '');
            $email = $this->emails->normalize($ligne['email'] ?? '');
            $telephoneBrut = trim($ligne['telephone'] ?? '');

            $contact = null;
            if ('' !== $leadId && isset($etat['parLead'][$leadId])) {
                $contact = $etat['parLead'][$leadId];
            } elseif (null !== $email && isset($etat['parCleEmail'][$cle.'|'.$email])) {
                $contact = $etat['parCleEmail'][$cle.'|'.$email];
            } elseif (isset($etat['parCleNom'][$cle.'|'.CompanyKey::for($nomComplet)])) {
                $contact = $etat['parCleNom'][$cle.'|'.CompanyKey::for($nomComplet)];
            }

            $nouveau = null === $contact;
            if ($nouveau) {
                $contact = new Contact();
                $contact->setProspect($prospect);
                $prospect->addContact($contact);
                $this->em->persist($contact);
                ++$rapport->contactsCrees;
            } else {
                ++$rapport->contactsMaj;
            }

            $contact->setPrenom($decoupe['prenom']);
            $contact->setNom('' !== $decoupe['nom'] ? $decoupe['nom'] : $nomComplet);
            $contact->setNomComplet($nomComplet);
            $contact->setEmail($email);
            $contact->setPoste('' !== trim($ligne['poste'] ?? '') ? mb_substr(trim($ligne['poste']), 0, 255) : null);
            $contact->setLinkedinUrl('' !== trim($ligne['linkedin'] ?? '') ? mb_substr(trim($ligne['linkedin']), 0, 255) : null);
            $contact->setTelephoneBrut('' !== $telephoneBrut ? mb_substr($telephoneBrut, 0, 64) : null);
            $contact->setTelephone($this->telephones->normalize($telephoneBrut));

            if ('' !== $leadId) {
                $contact->setLeadId(mb_substr($leadId, 0, 64));
            }
            $contact->setAdId('' !== trim($ligne['adId'] ?? '') ? mb_substr(trim($ligne['adId']), 0, 64) : $contact->getAdId());
            $contact->setFormId('' !== trim($ligne['formId'] ?? '') ? mb_substr(trim($ligne['formId']), 0, 64) : $contact->getFormId());
            $contact->setFormName('' !== trim($ligne['formName'] ?? '') ? mb_substr(trim($ligne['formName']), 0, 180) : $contact->getFormName());
            $contact->setCampaignExternalId('' !== trim($ligne['campaignId'] ?? '') ? mb_substr(trim($ligne['campaignId']), 0, 64) : $contact->getCampaignExternalId());

            $sourceDate = $this->dates->parse($ligne['createdAt'] ?? '');
            if (null !== $sourceDate) {
                $contact->setSourceCreatedAt($sourceDate);
            }

            if ($nouveau) {
                $this->indexerContact($contact, $cle, $etat['parLead'], $etat['parCleEmail'], $etat['parCleNom']);
            }

            $score = $this->scoreCompleteness($ligne);
            if ($score > $scorePrincipal) {
                $scorePrincipal = $score;
                $candidatPrincipal = $contact;
            }
        }

        if (null !== $candidatPrincipal && !$dejaPrincipal) {
            foreach ($prospect->getContacts() as $contact) {
                $contact->setEstPrincipal(false);
            }
            $candidatPrincipal->setEstPrincipal(true);
        }
    }

    /**
     * @param list<array<string, string>> $lignes
     * @param array<string, mixed>        $etat
     * @param list<string>                $anomalies
     */
    private function importerActions(Prospect $prospect, array $lignes, array &$etat, ImportReport $rapport, array &$anomalies): void
    {
        $cle = (string) $prospect->getCleEntreprise();
        $annee = $this->anneeReference($lignes);

        foreach ($lignes as $ligne) {
            $date = trim($ligne['date'] ?? '');
            $resultat = trim($ligne['rq'] ?? '');
            $rappel = trim($ligne['rappel'] ?? '');

            if ('' === $date && '' === $resultat && '' === $rappel) {
                continue;
            }

            $dateAction = null;
            if ('' !== $date) {
                $dateAction = $this->dates->parseCourt($date, $annee);
                if (null === $dateAction) {
                    $anomalies[] = 'date d\'action illisible "'.$date.'"';
                }
            }

            $dateRelance = null;
            if ('' !== $rappel) {
                $dateRelance = $this->dates->parseCourt($rappel, $annee);
                if (null === $dateRelance) {
                    $anomalies[] = 'date de relance illisible "'.$rappel.'"';
                }
            }

            $resultatAction = '' !== $resultat ? $resultat : null;

            if (null === $dateAction && null === $dateRelance && null === $resultatAction) {
                continue;
            }

            $action = new Action();
            $action->setProspect($prospect);
            $action->setDateRealisee($dateAction);
            $action->setResultat($resultatAction);
            $action->setDatePrevue($dateRelance);

            $contact = $prospect->getContactPrincipal();
            if (null !== $contact) {
                $action->setContact($contact);
            }

            $dejaPresente = isset($etat['actions'][$cle][$this->cleAction($action)]);
            if ($dejaPresente) {
                continue;
            }

            $etat['actions'][$cle][$this->cleAction($action)] = true;
            $prospect->addAction($action);
            $this->em->persist($action);
            ++$rapport->actionsCreees;

            // Si un rappel est renseigné et que l'action source est réalisée,
            // créer la planifiée associée (indépendante, sans lien hiérarchique).
            if (null !== $dateRelance && null !== $dateAction) {
                $cleEnfant = $this->clePlanifieeImport($prospect, $dateRelance);
                if (!isset($etat['actions'][$cle][$cleEnfant])) {
                    $enfant = new Action();
                    $enfant->setProspect($prospect);
                    $enfant->setContact($contact);
                    $enfant->setDatePrevue($dateRelance);
                    $etat['actions'][$cle][$cleEnfant] = true;
                    $prospect->addAction($enfant);
                    $this->em->persist($enfant);
                    ++$rapport->actionsCreees;
                }
            }
        }
    }

    private function cleAction(Action $action): string
    {
        return md5(implode('|', [
            $action->getDateRealisee()?->format('Y-m-d') ?? '',
            (string) $action->getResultat(),
            $action->getDatePrevue()?->format('Y-m-d') ?? '',
        ]));
    }

    private function clePlanifieeImport(Prospect $prospect, \DateTime $date): string
    {
        $cle = (string) $prospect->getCleEntreprise();

        return md5('planifiee|'.$cle.'|'.$date->format('Y-m-d'));
    }

    /**
     * @param list<array<string, string>> $lignes
     *
     * @return list<array<string, string>>
     */
    private function trier(array $lignes): array
    {
        usort($lignes, function (array $a, array $b): int {
            $da = $this->dates->parse($a['createdAt'] ?? '')?->getTimestamp() ?? 0;
            $db = $this->dates->parse($b['createdAt'] ?? '')?->getTimestamp() ?? 0;

            return $da <=> $db;
        });

        return $lignes;
    }

    /**
     * Ligne la plus complète du groupe (devient la référence campagne / contact principal).
     *
     * @param list<array<string, string>> $lignes
     *
     * @return array<string, string>
     */
    private function lignePrincipale(array $lignes): array
    {
        $meilleure = [];
        $meilleurScore = -1;

        foreach ($lignes as $ligne) {
            $score = $this->scoreCompleteness($ligne);
            if ($score > $meilleurScore) {
                $meilleurScore = $score;
                $meilleure = $ligne;
            }
        }

        return $meilleure;
    }

    /**
     * @param array<string, string> $ligne
     */
    private function scoreCompleteness(array $ligne): int
    {
        $score = 0;
        if ('' !== ($ligne['email'] ?? '')) {
            $score += 3;
        }
        if ('' !== ($ligne['telephone'] ?? '')) {
            $score += 2;
        }
        if ('' !== ($ligne['poste'] ?? '')) {
            ++$score;
        }
        if ('' !== ($ligne['linkedin'] ?? '')) {
            ++$score;
        }
        if ('' !== ($ligne['prenom'] ?? '')) {
            ++$score;
        }

        return $score;
    }

    /**
     * Dernière valeur non vide d'un champ, les lignes étant triées par date.
     *
     * @param list<array<string, string>> $lignes
     */
    private function derniereValeur(array $lignes, string $champ): ?string
    {
        $valeur = null;
        foreach ($lignes as $ligne) {
            $candidate = trim($ligne[$champ] ?? '');
            if ('' !== $candidate) {
                $valeur = $candidate;
            }
        }

        return $valeur;
    }

    /**
     * @param list<array<string, string>> $lignes
     */
    private function anneeReference(array $lignes): ?int
    {
        foreach (array_reverse($lignes) as $ligne) {
            $date = $this->dates->parse($ligne['createdAt'] ?? '');
            if (null !== $date) {
                return (int) $date->format('Y');
            }
        }

        return null;
    }

    /**
     * Anomalies téléphone / e-mail rencontrées sur les lignes du groupe.
     *
     * @param list<array<string, string>> $lignes
     *
     * @return list<string>
     */
    private function anomaliesContacts(array $lignes): array
    {
        $anomalies = [];

        foreach ($lignes as $ligne) {
            $motif = $this->telephones->anomalie((string) ($ligne['telephone'] ?? ''));
            if (null !== $motif && !in_array($motif, $anomalies, true)) {
                $anomalies[] = $motif;
            }

            $motif = $this->emails->anomalie((string) ($ligne['email'] ?? ''));
            if (null !== $motif && !in_array($motif, $anomalies, true)) {
                $anomalies[] = $motif;
            }
        }

        return $anomalies;
    }

    /**
     * Convertit une valeur oui/non ; signale en anomalie ce qui est illisible.
     *
     * @param list<string> $anomalies
     */
    private function boolOuAnomalie(string $valeur, string $libelle, array &$anomalies): ?bool
    {
        $converti = $this->versBool($valeur);

        if (null === $converti) {
            $anomalies[] = $libelle.' : valeur non reconnue "'.$valeur.'"';
        }

        return $converti;
    }

    private function versBool(string $valeur): ?bool
    {
        $nettoye = mb_strtolower(trim($valeur));

        return match ($nettoye) {
            'oui', 'o', 'ok', '1', 'true' => true,
            'non', 'n', '0', 'false' => false,
            default => null,
        };
    }

    private function normaliserEntete(string $entete): string
    {
        $valeur = mb_strtolower(trim($entete));
        $valeur = strtr($valeur, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i', 'í' => 'i', 'ì' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ó' => 'o', 'ò' => 'o',
            'û' => 'u', 'ü' => 'u', 'ù' => 'u', 'ú' => 'u',
            'ç' => 'c', '°' => '', 'œ' => 'oe', 'æ' => 'ae',
        ]);
        $valeur = preg_replace('/[^a-z0-9]+/', ' ', $valeur) ?? $valeur;

        return trim($valeur);
    }

    /**
     * @param list<string> $prefixes
     */
    private function retirerPrefixe(string $valeur, array $prefixes): string
    {
        foreach ($prefixes as $prefixe) {
            if (str_starts_with($valeur, $prefixe)) {
                return substr($valeur, strlen($prefixe));
            }
        }

        return $valeur;
    }

    private function reinitialiser(ImportReport $rapport): void
    {
        $prospects = $this->prospects->findAll();

        foreach ($prospects as $prospect) {
            $this->em->remove($prospect);
        }
        $this->em->flush();

        foreach ($this->campagnes->findAll() as $campagne) {
            $this->em->remove($campagne);
        }
        foreach ($this->sprints->findAll() as $sprint) {
            $this->em->remove($sprint);
        }
        $this->em->flush();

        $rapport->ajouterMessage('Base réinitialisée : '.count($prospects).' prospects supprimés.');
    }
}
