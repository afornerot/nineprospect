<?php

namespace App\Service\Import;

use App\Entity\Contact;
use App\Entity\Prospect;
use App\Repository\ContactRepository;
use App\Repository\ProspectRepository;

/**
 * Analyse un fichier tableur en ImportPreview :
 *  - lit le fichier (xlsx/csv) ;
 *  - normalise chaque ligne (RowNormalizer) ;
 *  - regroupe les lignes par cleEntreprise ;
 *  - détecte les doublons existants en base (Prospect par cleEntreprise, Contact par email) ;
 *  - détecte les doublons intra-fichier.
 *
 * Aucune écriture en base ; c'est une étape dry-run.
 */
final class ImportAnalyzer
{
    public function __construct(
        private SpreadsheetReader $reader,
        private RowNormalizer $normalizer,
        private ProspectRepository $prospects,
        private ContactRepository $contactRepository,
    ) {
    }

    /**
     * @param array{
     *     filename: string,
     *     filePath: string,
     *     mode: string,
     *     ciblePrincipaleId?: int|null,
     *     ciblePrincipaleTitle?: string|null,
     *     ciblesSupplementairesIds?: list<int>,
     *     campagneId?: int|null,
     * } $params
     */
    public function analyze(array $params): ImportPreview
    {
        $preview = new ImportPreview(
            filename: $params['filename'],
            mode: $params['mode'],
            ciblePrincipaleId: $params['ciblePrincipaleId'] ?? null,
            ciblePrincipaleTitle: $params['ciblePrincipaleTitle'] ?? null,
            ciblesSupplementairesIds: $params['ciblesSupplementairesIds'] ?? [],
            campagneId: $params['campagneId'] ?? null,
        );

        try {
            $parsed = $this->reader->readFile($params['filePath']);
        } catch (\Throwable $e) {
            $preview->fatalError = $e->getMessage();

            return $preview;
        }

        $preview->columnsDetected = $this->normalizer->mapHeader($parsed['header']);
        $preview->columnsMissing = $this->normalizer->missingRequired($preview->columnsDetected);
        $preview->columnsUnknown = $this->normalizer->unknownColumns($parsed['header']);

        if ([] !== $preview->columnsMissing) {
            $preview->fatalError = 'Colonnes obligatoires manquantes : '.implode(', ', $preview->columnsMissing);

            return $preview;
        }

        // 1) Normaliser chaque ligne
        $rows = []; // list<ImportRow>
        foreach ($parsed['rows'] as $idx => $rawRow) {
            $rowNumber = $idx + 2; // +1 (0-based) +1 (header)
            $rows[] = $this->normalizer->normalize($rowNumber, $rawRow);
        }

        // 2) Pré-charger les Prospects existants par cleEntreprise
        $cles = array_values(array_unique(array_filter(array_map(fn ($r) => $r->cleEntreprise, $rows))));
        $existingProspects = $this->prospects->findByClesEntreprise($cles);

        // 3) Pré-charger les Contacts existants par email
        $emails = array_values(array_unique(array_filter(array_map(fn ($r) => $r->email, $rows))));
        $existingContacts = $this->contactRepository->findByEmails($emails);

        // 4) Regrouper + statut
        $groupsByKey = [];
        $seenEmails = []; // pour détection intra-fichier
        foreach ($rows as $row) {
            $key = $row->cleEntreprise;
            if (!isset($groupsByKey[$key])) {
                $groupsByKey[$key] = new ImportGroup($key);
            }

            $existingProspect = $existingProspects[$key] ?? null;
            $existingContact = (null !== $row->email) ? ($existingContacts[$row->email] ?? null) : null;

            $status = $this->computeStatus($row, $existingProspect, $existingContact, $seenEmails);
            $default = $this->defaultAction($status);

            $groupsByKey[$key]->addRow(new AnalyzedImportRow(
                row: $row,
                status: $status,
                existingProspectId: $existingProspect?->getId(),
                existingContactId: $existingContact?->getId(),
                defaultAction: $default,
            ));

            // Marquer l'email vu
            if (null !== $row->email) {
                $seenEmails[] = $row->email;
            }
        }

        // Trier les groupes par libellé pour affichage stable
        $groups = array_values($groupsByKey);
        usort($groups, static fn (ImportGroup $a, ImportGroup $b) => strcasecmp($a->getLibelle(), $b->getLibelle()));
        $preview->groups = $groups;

        return $preview;
    }

    /**
     * Statut d'une ligne en fonction des erreurs et des doublons détectés.
     *
     * @param list<string> $seenEmails
     */
    private function computeStatus(
        ImportRow $row,
        ?Prospect $existingProspect,
        ?Contact $existingContact,
        array $seenEmails,
    ): string {
        if ($row->hasErrors()) {
            return ImportRowStatus::ERROR;
        }

        if (null !== $row->email && in_array($row->email, $seenEmails, true)) {
            return ImportRowStatus::DUPLICATE_INTRA;
        }

        if (null !== $existingProspect) {
            return ImportRowStatus::DUPLICATE_PROSPECT_DB;
        }

        if (null !== $existingContact) {
            return ImportRowStatus::DUPLICATE_CONTACT_DB;
        }

        return ImportRowStatus::OK;
    }

    private function defaultAction(string $status): string
    {
        // L'action par défaut reflète ce qu'il y a de mieux à faire pour la ligne :
        // - OK → Importer (la ligne est valide, on l'importe)
        // - Doublon prospect en base → Rattacher (action principale pour ce cas)
        // - Doublon contact en base / intra → Ignorer (rien d'autre n'a de sens)
        // - Erreur → Ignorer (par défaut, l'exécuteur forcera SKIP de toute façon)
        return match ($status) {
            ImportRowStatus::OK => ImportRowAction::IMPORT,
            ImportRowStatus::DUPLICATE_PROSPECT_DB => ImportRowAction::LINK,
            ImportRowStatus::DUPLICATE_CONTACT_DB,
            ImportRowStatus::DUPLICATE_INTRA,
            ImportRowStatus::ERROR => ImportRowAction::SKIP,
            default => ImportRowAction::SKIP,
        };
    }
}
