<?php

namespace App\Tests\Service\Import;

use App\Service\Import\ImportRowAction;
use App\Service\Import\ImportRowStatus;
use PHPUnit\Framework\TestCase;

class ImportRowActionTest extends TestCase
{
    public function testChoicesForStatusOkProposeImporterEtIgnorer(): void
    {
        $choices = ImportRowAction::choicesForStatus(ImportRowStatus::OK);
        $this->assertSame([
            ImportRowAction::IMPORT => 'Importer',
            ImportRowAction::SKIP => 'Ignorer',
        ], $choices);
    }

    public function testChoicesForStatusDoublonProspectProposeModifierEtIgnorer(): void
    {
        $choices = ImportRowAction::choicesForStatus(ImportRowStatus::DUPLICATE_PROSPECT_DB);
        $this->assertSame([
            ImportRowAction::LINK => 'Modifier',
            ImportRowAction::SKIP => 'Ignorer',
        ], $choices);
    }

    public function testChoicesForStatusDoublonContactNeProposeQueIgnorer(): void
    {
        $choices = ImportRowAction::choicesForStatus(ImportRowStatus::DUPLICATE_CONTACT_DB);
        $this->assertSame([ImportRowAction::SKIP => 'Ignorer'], $choices);
    }

    public function testChoicesForStatusDoublonIntraNeProposeQueIgnorer(): void
    {
        $choices = ImportRowAction::choicesForStatus(ImportRowStatus::DUPLICATE_INTRA);
        $this->assertSame([ImportRowAction::SKIP => 'Ignorer'], $choices);
    }

    public function testChoicesForStatusErreurEstVide(): void
    {
        // Erreur : aucune action possible, le skip est forcé par l'exécuteur.
        $this->assertSame([], ImportRowAction::choicesForStatus(ImportRowStatus::ERROR));
    }

    public function testIgnorerEstToujoursProposePourLesLignesValidesOuDoublons(): void
    {
        $this->assertArrayHasKey(ImportRowAction::SKIP, ImportRowAction::choicesForStatus(ImportRowStatus::OK));
        $this->assertArrayHasKey(ImportRowAction::SKIP, ImportRowAction::choicesForStatus(ImportRowStatus::DUPLICATE_PROSPECT_DB));
        $this->assertArrayHasKey(ImportRowAction::SKIP, ImportRowAction::choicesForStatus(ImportRowStatus::DUPLICATE_CONTACT_DB));
        $this->assertArrayHasKey(ImportRowAction::SKIP, ImportRowAction::choicesForStatus(ImportRowStatus::DUPLICATE_INTRA));
    }
}
