<?php

namespace App\Service\Import;

use App\Entity\Campagne;
use App\Entity\Cible;
use App\Entity\Contact;
use App\Entity\Prospect;
use App\Entity\ProspectCible;
use App\Message\GeocodeProspectMessage;
use App\Repository\CampagneRepository;
use App\Repository\CibleRepository;
use App\Repository\ContactRepository;
use App\Repository\ProspectRepository;
use App\Service\Geo\GeoResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Exécute un import à partir d'un ImportPreview + décisions utilisateur
 * (action par ligne). Crée/maj les Prospects, Contacts, ProspectCibles.
 *
 * - "Importer" (Créer prospect) : crée le Prospect (et Contact) ;
 *   si Prospect existant pour la cleEntreprise, MAJ non destructive.
 * - "Mettre à jour" : MAJ des champs du Prospect et du Contact lié à la ligne.
 * - "Rattacher" : ajoute juste les ProspectCibles (et Contacts manquants),
 *   ne modifie pas le Prospect.
 * - "Ignorer" : ne fait rien.
 *
 * Les lignes en erreur sont systématiquement ignorées (action forcée SKIP).
 */
final class ImportExecutor
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProspectRepository $prospectsRepo,
        private ContactRepository $contactRepo,
        private CibleRepository $ciblesRepo,
        private CampagneRepository $campagnesRepo,
        private GeoResolver $geo,
        private MessageBusInterface $bus,
    ) {
    }

    /**
     * @param array<int, string> $rowActions map rowNumber => action
     */
    public function execute(ImportPreview $preview, array $rowActions): ImportResult
    {
        $result = new ImportResult();

        // 1) Cible principale : nouvelle, existante, ou aucune
        $ciblePrincipale = $this->resolveCiblePrincipale($preview, $result);
        if (null === $ciblePrincipale && ('new' === $preview->mode || null !== $preview->ciblePrincipaleId)) {
            return $result; // erreur fatale interceptée en amont par le contrôleur
        }

        // 2) Cibles supplémentaires
        $ciblesSupplementaires = [];
        foreach ($preview->ciblesSupplementairesIds as $cibleId) {
            $c = $this->ciblesRepo->find($cibleId);
            if (null !== $c) {
                $ciblesSupplementaires[] = $c;
            }
        }
        $allCibles = null !== $ciblePrincipale
            ? array_merge([$ciblePrincipale], $ciblesSupplementaires)
            : $ciblesSupplementaires;

        // 3) Campagne
        $campagne = null;
        if (null !== $preview->campagneId) {
            $campagne = $this->campagnesRepo->find($preview->campagneId);
        }

        // 4) Cache des Prospects créés/rattachés par cleEntreprise
        $prospectByCle = [];

        foreach ($preview->groups as $group) {
            $cle = $group->getCleEntreprise();

            // Détermine la liste des actions effectives (SKIP forcé sur les lignes en erreur).
            $effectiveActions = [];
            foreach ($group->getRows() as $ar) {
                $rowNumber = $ar->row->rowNumber;
                $action = $rowActions[$rowNumber] ?? $ar->defaultAction;
                if ($ar->isError()) {
                    $action = ImportRowAction::SKIP;
                }
                $effectiveActions[$rowNumber] = $action;
            }

            // Si toutes les lignes du groupe sont ignorées, on ne crée pas de Prospect.
            // (Sauf cas particulier : si le Prospect existe déjà en base ET qu'au moins une
            //  ligne a pour but de s'y rattacher — mais comme Rattacher crée un Contact,
            //  il n'y a rien à faire si toutes les lignes sont SKIP.)
            $hasAnyAction = false;
            foreach ($effectiveActions as $action) {
                if (ImportRowAction::SKIP !== $action) {
                    $hasAnyAction = true;
                    break;
                }
            }

            // Pour Rattacher : si le Prospect existe déjà en base, on doit s'assurer
            // que les ProspectCibles sont rattachés même si le seul Contact à créer
            // est skip. On autorise donc Rattacher sans Contact uniquement sur un Prospect existant.
            $isRattacherOnly = false;
            foreach ($effectiveActions as $action) {
                if (ImportRowAction::LINK === $action) {
                    $isRattacherOnly = true;
                } elseif (ImportRowAction::SKIP !== $action) {
                    $isRattacherOnly = false;
                    break;
                }
            }
            $existingProspectFromGroup = $this->findExistingProspectForGroup($group);

            if (!$hasAnyAction) {
                // Toutes les lignes ignorées : on incrémente le compteur et on passe.
                foreach ($effectiveActions as $action) {
                    if (ImportRowAction::SKIP === $action) {
                        ++$result->rowsSkipped;
                    }
                }

                continue;
            }

            // Cas Rattacher sans action d'import : si le Prospect n'existe pas,
            // on ne peut rien rattacher, donc on saute.
            if ($isRattacherOnly && null === $existingProspectFromGroup) {
                foreach ($effectiveActions as $action) {
                    ++$result->rowsSkipped;
                }

                continue;
            }

            // Chercher ou créer le Prospect du groupe
            $prospect = $this->resolveProspect($group, $prospectByCle, $result, $allCibles, $campagne);
            $prospectByCle[$cle] = $prospect;

            // Pour chaque ligne du groupe, créer/maj les Contacts + ProspectCibles
            foreach ($group->getRows() as $ar) {
                $rowNumber = $ar->row->rowNumber;
                $action = $effectiveActions[$rowNumber];

                if (ImportRowAction::SKIP === $action) {
                    ++$result->rowsSkipped;
                    continue;
                }

                $this->processRow($ar, $action, $prospect, $allCibles, $campagne, $result);
            }
        }

        $this->em->flush();

        return $result;
    }

    private function resolveCiblePrincipale(ImportPreview $preview, ImportResult $result): ?Cible
    {
        if ('new' === $preview->mode) {
            $titre = trim((string) $preview->ciblePrincipaleTitle);
            if ('' === $titre) {
                $result->errors[] = ['row' => 0, 'motif' => 'Titre de la nouvelle cible manquant.'];

                return null;
            }
            $c = new Cible();
            $c->setTitre($titre);
            $this->em->persist($c);

            return $c;
        }

        if (null === $preview->ciblePrincipaleId) {
            // Pas de cible principale : l'import se fait sans rattachement à une cible.
            return null;
        }

        $c = $this->ciblesRepo->find($preview->ciblePrincipaleId);
        if (null === $c) {
            $result->errors[] = ['row' => 0, 'motif' => sprintf('Cible principale introuvable (id=%d).', $preview->ciblePrincipaleId)];

            return null;
        }

        return $c;
    }

    /**
     * Cherche un Prospect existant associé au groupe (via existingProspectId des lignes).
     * Utilisé pour savoir s'il faut rattacher ou créer.
     */
    private function findExistingProspectForGroup(ImportGroup $group): ?Prospect
    {
        foreach ($group->getRows() as $ar) {
            if (null !== $ar->existingProspectId) {
                $p = $this->prospectsRepo->find($ar->existingProspectId);
                if (null !== $p) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * @param list<Cible>             $allCibles
     * @param array<string, Prospect> $prospectByCle
     */
    private function resolveProspect(ImportGroup $group, array $prospectByCle, ImportResult $result, array $allCibles, ?Campagne $campagne): Prospect
    {
        $cle = $group->getCleEntreprise();

        if (isset($prospectByCle[$cle])) {
            return $prospectByCle[$cle];
        }

        // Chercher un Prospect existant
        $prospect = $this->findExistingProspectForGroup($group);

        if (null === $prospect) {
            // Recherche par cleEntreprise (cas où la détection initiale a échoué)
            $found = $this->prospectsRepo->findByClesEntreprise([$cle]);
            if (isset($found[$cle])) {
                $prospect = $found[$cle];
            }
        }

        $estCree = false;
        if (null === $prospect) {
            $prospect = new Prospect();
            $prospect->setNom($group->getLibelle());
            $prospect->setCleEntreprise($cle);
            $this->em->persist($prospect);
            $estCree = true;
        }

        // Alimenter les champs prospect à partir de la 1re ligne valide du groupe
        // On mémorise les coordonnées initiales : si le Prospect existait déjà
        // avec des coords, on ne re-géocode PAS (geste à éviter au cas où
        // l'utilisateur a déjà corrigé manuellement).
        $latAvant = $prospect->getLatitude();
        $lonAvant = $prospect->getLongitude();

        $first = null;
        foreach ($group->getRows() as $ar) {
            if (!$ar->isError()) {
                $first = $ar;
                break;
            }
        }
        if (null !== $first) {
            $row = $first->row;
            // Pour les nouveaux prospects : on remplit avec les valeurs du fichier
            // Pour les existants : on ne remplace que si la valeur actuelle est null
            if ($estCree || null === $prospect->getAdresse()) {
                $prospect->setAdresse($row->adresse);
            }
            if ($estCree || null === $prospect->getCodePostal()) {
                $prospect->setCodePostal($row->codePostal);
            }
            if ($estCree || null === $prospect->getVille()) {
                $prospect->setVille($row->ville);
            }
            if ($estCree || null === $prospect->getSiteUrl()) {
                $prospect->setSiteUrl($row->siteWeb);
            }
            if ($estCree || null === $prospect->getDepartement()) {
                if (null !== $row->codePostal && preg_match('/^\d{5}$/', $row->codePostal)) {
                    $dept = $this->geo->resolve($row->codePostal)['departement'] ?? null;
                    if (null !== $dept) {
                        $prospect->setDepartement($dept);
                    }
                }
            }
        }

        // Campagne (uniquement si null)
        if (null !== $campagne && null === $prospect->getCampagne()) {
            $prospect->setCampagne($campagne);
        }

        if ($estCree) {
            ++$result->prospectsCreated;
        } else {
            ++$result->prospectsLinked;
        }
        $prospectId = $prospect->getId();
        if (null !== $prospectId) {
            $result->affectedProspectIds[] = $prospectId;

            // Géocodage asynchrone : on dispatche dès qu'on rencontre un Prospect
            // qui a une adresse utilisable mais aucune coordonnée GPS. Cela couvre :
            //   1. Création d'un nouveau Prospect (adresse vient du fichier)
            //   2. Enrichissement d'un Prospect existant (adresse null → valeur)
            //   3. Prospect existant qui avait déjà son adresse mais aucune
            //      coordonnée (ex. ajoutée manuellement entre deux imports) :
            //      la prochaine tentative d'import déclenchera le géocodage
            //
            // On NE re-géocode PAS si le Prospect avait DÉJÀ des coordonnées
            // (cas de l'import subséquent sur un Prospect déjà géocodé), pour
            // éviter des appels API inutiles.
            //
            // Le handler vérifie aussi que les coordonnées sont effectivement
            // manquantes, et l'AdresseApi ne géocodera QUE si elle trouve
            // exactement 1 résultat (limite l'API à 2 et rejette les >1).
            $adresse = (string) $prospect->getAdresse();
            $cp = (string) ($prospect->getCodePostal() ?? '');
            $ville = (string) ($prospect->getVille() ?? '');

            $adresseExploitable = '' !== trim($adresse.' '.$cp.' '.$ville);
            $coordsManquantes = null === $prospect->getLatitude() || null === $prospect->getLongitude();

            // Dispatch dès que le Prospect a une adresse utilisable et pas
            // encore de coordonnées. La différenciation création/modification
            // n'est plus nécessaire : un Prospect qui traverse l'import sans
            // coordonnées a besoin d'être géocodé.
            $doitGeocoder = $adresseExploitable && $coordsManquantes;

            // Garde-fou : si le Prospect existait déjà AVEC des coordonnées
            // et qu'on ne les a pas modifiées, on ne lance PAS de re-géocodage.
            // Note : avec $coordsManquantes strict ci-dessus, ce cas est déjà
            // exclu. La condition ci-dessous est un garde-fou supplémentaire.
            if ($doitGeocoder && (null === $latAvant || null === $lonAvant || $estCree)) {
                $this->bus->dispatch(new GeocodeProspectMessage($prospectId));
            }
        }

        // Créer les ProspectCibles (toutes les cibles de la liste)
        $this->ensureProspectCibles($prospect, $allCibles);

        return $prospect;
    }

    /**
     * @param list<Cible> $allCibles
     */
    private function processRow(AnalyzedImportRow $ar, string $action, Prospect $prospect, array $allCibles, ?Campagne $campagne, ImportResult $result): void
    {
        $row = $ar->row;

        // 1) Gestion du Contact existant (peut être sur le même prospect ou un autre)
        $existingContact = null;
        if (null !== $ar->existingContactId) {
            $existingContact = $this->contactRepo->find($ar->existingContactId);
        }
        // Fallback : recherche par email
        if (null === $existingContact && null !== $row->email) {
            $found = $this->contactRepo->findByEmails([$row->email]);
            $existingContact = $found[$row->email] ?? null;
        }

        // Cas particulier "Rattacher" : on ne touche pas au Prospect (qui existe déjà)
        if (ImportRowAction::LINK === $action) {
            // Si le Contact existe déjà sur ce Prospect → rien à faire (déjà lié).
            // Si le Contact n'existe pas → on le crée sur ce Prospect (sans toucher au Prospect).
            // Si le Contact existe sur un AUTRE Prospect → on en crée un nouveau ici (pour
            // ne pas "voler" un contact à un autre prospect).
            if (null === $existingContact || $existingContact->getProspect()?->getId() !== $prospect->getId()) {
                $this->createContact($prospect, $row, false);
                ++$result->contactsCreated;
            }

            return;
        }

        // Cas "Importer" : on crée le Contact si pas déjà présent sur ce Prospect.
        if (null !== $existingContact && $existingContact->getProspect()?->getId() === $prospect->getId()) {
            // Le contact est déjà sur ce prospect → on ne fait rien (cas rare : le prospect
            // est nouveau en base mais le contact email existe déjà ailleurs, et le groupe
            // partage déjà ce prospect).
            return;
        }

        // Nouveau contact (ou contact existant sur un AUTRE prospect → on en crée un nouveau)
        // 1er contact créé = estPrincipal (cas typique d'un nouveau prospect).
        $this->createContact($prospect, $row, true);
        ++$result->contactsCreated;
    }

    private function createContact(Prospect $prospect, ImportRow $row, bool $estPrincipal): Contact
    {
        $contact = new Contact();
        $contact->setProspect($prospect);
        $contact->setNom($row->nom);
        $contact->setPrenom($row->prenom);
        $contact->setEmail($row->email);
        $contact->setPoste($row->fonction);
        $contact->setTelephoneBrut($row->telephoneBrut);
        $contact->setEstPrincipal($estPrincipal);
        $this->em->persist($contact);

        return $contact;
    }

    /**
     * Crée les liens ProspectCibles manquants pour les cibles données.
     *
     * @param list<Cible> $cibles
     */
    private function ensureProspectCibles(Prospect $prospect, array $cibles): void
    {
        $existingCibleIds = [];
        foreach ($prospect->getProspectCibles() as $pc) {
            $cible = $pc->getCible();
            if (null !== $cible) {
                $existingCibleIds[$cible->getId()] = true;
            }
        }

        foreach ($cibles as $cible) {
            if (isset($existingCibleIds[$cible->getId()])) {
                continue;
            }
            $pc = new ProspectCible();
            $pc->setProspect($prospect);
            $pc->setCible($cible);
            $pc->setQualifie(null); // Pas encore qualifié
            $this->em->persist($pc);
        }
    }
}
