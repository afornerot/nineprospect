<?php

namespace App\Tests\Service\Import;

use App\Entity\Prospect;
use App\Message\GeocodeProspectMessage;
use App\Repository\CampagneRepository;
use App\Repository\CibleRepository;
use App\Repository\ContactRepository;
use App\Repository\ProspectRepository;
use App\Service\Geo\GeoResolver;
use App\Service\Import\AnalyzedImportRow;
use App\Service\Import\ImportExecutor;
use App\Service\Import\ImportGroup;
use App\Service\Import\ImportPreview;
use App\Service\Import\ImportResult;
use App\Service\Import\ImportRow;
use App\Service\Import\ImportRowAction;
use App\Service\Import\ImportRowStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

class ImportExecutorTest extends TestCase
{
    public function testExecuteCréeLesProspectsContactsEtProspectCibles(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        $preview = $this->createPreview([
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com', '01 23 45 67 89', '10 rue Test', '75002', 'Paris'],
            ['Société X', 'MARTIN', 'Pierre', 'pierre@example.com', null, '10 rue Test', '75002', 'Paris'],
        ]);

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        $result = $executor->execute($preview, [
            2 => ImportRowAction::IMPORT,
            3 => ImportRowAction::IMPORT,
        ]);

        $this->assertInstanceOf(ImportResult::class, $result);
        $this->assertSame(1, $result->prospectsCreated, '1 seul Prospect pour 2 lignes de la même organisation');
        $this->assertSame(2, $result->contactsCreated);
        // Le Prospect créé n'a pas d'ID persisté en base dans le test (pas de flush réel),
        // donc le dispatch du géocodage n'a pas lieu. Voir le test sur existant
        // pour le cas dispatché.
        $this->assertCount(0, $bus->dispatched);
    }

    public function testExecuteSkipQuandActionEstSkip(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        // Cible existante pour ne pas avoir à mocker une création de Cible
        $cible = new \App\Entity\Cible();
        $cible->setTitre('Test');
        $ciblesRepo->method('find')->willReturn($cible);

        $preview = $this->createPreviewWithMode([
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com', null, null, null, null],
        ], 'existing', 42, null);

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        $result = $executor->execute($preview, [2 => ImportRowAction::SKIP]);

        // Ligne ignorée → aucun Prospect créé (groupement sans aucune ligne active
        // n'a pas de raison d'exister).
        $this->assertSame(0, $result->prospectsCreated);
        $this->assertSame(0, $result->contactsCreated);
        $this->assertSame(1, $result->rowsSkipped);
        $this->assertCount(0, $bus->dispatched, 'Aucun message de géocodage pour un Prospect non créé');
    }

    public function testExecuteCréeProspectQuandAuMoinsUneLigneEstActivee(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        $preview = $this->createPreview([
            ['Société X', 'A', 'X', 'a@x.com', null, null, null, null],
            ['Société X', 'B', 'X', 'b@x.com', null, '10 rue Test', '75002', 'Paris'],
        ]);

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        // Une ligne importée, une ligne ignorée : Prospect créé, 1 Contact.
        $result = $executor->execute($preview, [
            2 => ImportRowAction::SKIP,
            3 => ImportRowAction::IMPORT,
        ]);

        $this->assertSame(1, $result->prospectsCreated);
        $this->assertSame(1, $result->contactsCreated);
        $this->assertSame(1, $result->rowsSkipped);
        // Le Prospect créé n'a pas d'ID (non persisté en base dans le test),
        // donc le dispatch du géocodage n'a pas lieu.
        $this->assertCount(0, $bus->dispatched);
    }

    public function testNeCreePasProspectSiToutesLesLignesSontIgnorees(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        // Aucune entité ne doit être persistée
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        // Cible existante pour ne pas avoir à mocker une création de Cible
        $cible = new \App\Entity\Cible();
        $cible->setTitre('Test');
        $ciblesRepo->method('find')->willReturn($cible);

        $preview = $this->createPreviewWithMode([
            ['Université de Tours', 'A', 'Test', 'a@example.com', null, null, null, null],
            ['Université de Tours', 'B', 'Test', 'b@example.com', null, null, null, null],
            ['Université de Tours', 'C', 'Test', 'c@example.com', null, null, null, null],
        ], 'existing', 42, null);

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        // Toutes les lignes SKIP → pas de Prospect ni Contact.
        $result = $executor->execute($preview, [
            2 => ImportRowAction::SKIP,
            3 => ImportRowAction::SKIP,
            4 => ImportRowAction::SKIP,
        ]);

        $this->assertSame(0, $result->prospectsCreated);
        $this->assertSame(0, $result->contactsCreated);
        $this->assertSame(3, $result->rowsSkipped);
        $this->assertCount(0, $bus->dispatched);
    }

    public function testNeCreePasProspectQuandActionRattacherSeuleSansProspectExistant(): void
    {
        // Cas particulier : toutes les actions sont Rattacher (LINK) mais aucun
        // Prospect existant en base → on ne peut rien rattacher, on saute.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        // Cible existante pour ne pas avoir à mocker une création de Cible
        $cible = new \App\Entity\Cible();
        $cible->setTitre('Test');
        $ciblesRepo->method('find')->willReturn($cible);

        $preview = $this->createPreviewWithMode([
            ['Nouvelle Société', 'A', 'Z', 'a@z.com', null, null, null, null],
        ], 'existing', 42, null);

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        $result = $executor->execute($preview, [
            2 => ImportRowAction::LINK,
        ]);

        $this->assertSame(0, $result->prospectsCreated);
        $this->assertSame(0, $result->contactsCreated);
        $this->assertSame(1, $result->rowsSkipped);
        $this->assertCount(0, $bus->dispatched);
    }

    public function testDispatcheGeocodeAussiPourProspectExistantEnrichi(): void
    {
        // Cas d'un Prospect EXISTANT dont l'adresse a été enrichie :
        // on doit aussi dispatch le géocodage.
        $existingProspect = new Prospect();
        $existingProspect->setNom('Société Existante');
        $existingProspect->setCleEntreprise('import:societe existante');
        // Pas d'adresse, pas de coordonnées : c'est ce qu'on enrichit.
        // On lui donne un id via réflexion (l'ORM le ferait sinon).
        (new \ReflectionProperty($existingProspect, 'id'))->setValue($existingProspect, 123);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([
            'import:societe existante' => $existingProspect,
        ]);
        // Le groupe va appeler find() sur existingProspectId=123
        $prospectsRepo->method('find')->with(123)->willReturn($existingProspect);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        // Cible existante
        $cible = new \App\Entity\Cible();
        $cible->setTitre('Test');
        $ciblesRepo->method('find')->willReturn($cible);

        $preview = $this->createPreviewWithMode([
            ['Société Existante', 'A', 'X', 'a@x.com', null, '10 rue Test', '75002', 'Paris'],
        ], 'existing', 42, null);

        // Force l'existantProspectId sur la ligne (sinon le regroupement ne trouve pas)
        $preview->groups[0]->addRow(new AnalyzedImportRow(
            row: $preview->groups[0]->getRows()[0]->row,
            status: ImportRowStatus::DUPLICATE_PROSPECT_DB,
            existingProspectId: 123,
            defaultAction: ImportRowAction::LINK,
        ));

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        // Action "Modifier" (LINK) sur le Prospect existant → le Prospect est enrichi
        // avec l'adresse, et le message de géocodage doit être dispatché.
        $result = $executor->execute($preview, [
            2 => ImportRowAction::LINK,
        ]);

        $this->assertSame(1, $result->prospectsLinked, 'Le Prospect existant est rattaché (compteur)');
        // Adresse enrichie
        $this->assertSame('10 rue Test', $existingProspect->getAdresse());
        $this->assertSame('75002', $existingProspect->getCodePostal());
        $this->assertSame('Paris', $existingProspect->getVille());
        // Géocodage dispatché
        $this->assertCount(1, $bus->dispatched, 'Le géocodage doit aussi être dispatché sur modification');
        $this->assertInstanceOf(GeocodeProspectMessage::class, $bus->dispatched[0]->getMessage());
    }

    public function testNeDispatchePasGeocodeSiCoordonneesDejaPresentes(): void
    {
        // Prospect EXISTANT qui a déjà son adresse ET ses coordonnées.
        // L'import ne change rien → pas de re-géocodage (sinon on risque
        // d'écraser un placement GPS corrigé à la main par l'utilisateur).
        $existingProspect = new Prospect();
        $existingProspect->setNom('Société Complète');
        $existingProspect->setCleEntreprise('import:societe complete');
        $existingProspect->setAdresse('10 rue de la Paix');
        $existingProspect->setCodePostal('75002');
        $existingProspect->setVille('Paris');
        $existingProspect->setLatitude(48.8566);
        $existingProspect->setLongitude(2.3522);
        (new \ReflectionProperty($existingProspect, 'id'))->setValue($existingProspect, 123);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([
            'import:societe complete' => $existingProspect,
        ]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        // Cible existante
        $cible = new \App\Entity\Cible();
        $cible->setTitre('Test');
        $ciblesRepo->method('find')->willReturn($cible);

        $preview = $this->createPreviewWithMode([
            ['Société Complète', 'A', 'X', 'a@x.com', null, '10 rue de la Paix', '75002', 'Paris'],
        ], 'existing', 42, null);

        $preview->groups[0]->addRow(new AnalyzedImportRow(
            row: $preview->groups[0]->getRows()[0]->row,
            status: ImportRowStatus::DUPLICATE_PROSPECT_DB,
            existingProspectId: 123,
            defaultAction: ImportRowAction::LINK,
        ));

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        // Toutes les valeurs sont déjà renseignées → aucun setter ne change l'adresse.
        $result = $executor->execute($preview, [
            2 => ImportRowAction::LINK,
        ]);

        $this->assertCount(0, $bus->dispatched, 'Pas de re-géocodage si les coordonnées existent déjà');
    }

    public function testDispatcheGeocodePourProspectExistantAvecAdresseMaisSansCoords(): void
    {
        // Cas vécu en production : un Prospect existe en base avec son adresse
        // et son département mais SANS coordonnées GPS (ex. coordonnées effacées
        // ou ajoutées manuellement sans géocodage). Le prochain import doit
        // déclencher le géocodage.
        $existingProspect = new Prospect();
        $existingProspect->setNom('Conseil Départemental du Cher');
        $existingProspect->setCleEntreprise('import:conseil departemental du cher');
        $existingProspect->setAdresse('1 place de la Préfecture');
        $existingProspect->setCodePostal('18000');
        $existingProspect->setVille('Bourges');
        // Pas de latitude / longitude !
        (new \ReflectionProperty($existingProspect, 'id'))->setValue($existingProspect, 2682);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->any())->method('persist');
        $em->expects($this->once())->method('flush');

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([
            'import:conseil departemental du cher' => $existingProspect,
        ]);
        $prospectsRepo->method('find')->with(2682)->willReturn($existingProspect);
        $contactRepo = $this->createMock(ContactRepository::class);
        $contactRepo->method('findByEmails')->willReturn([]);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        // Cible existante
        $cible = new \App\Entity\Cible();
        $cible->setTitre('Import');
        $ciblesRepo->method('find')->willReturn($cible);

        $preview = $this->createPreviewWithMode([
            ['Conseil Départemental du Cher', 'DUPONT', 'Marie', 'marie@example.com', null, '1 place de la Préfecture', '18000', 'Bourges'],
        ], 'existing', 42, null);

        $preview->groups[0]->addRow(new AnalyzedImportRow(
            row: $preview->groups[0]->getRows()[0]->row,
            status: ImportRowStatus::DUPLICATE_PROSPECT_DB,
            existingProspectId: 2682,
            defaultAction: ImportRowAction::LINK,
        ));

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        // L'adresse du row est identique à celle déjà en base (rien n'a changé),
        // mais comme les coordonnées sont absentes, le géocodage doit être lancé.
        $executor->execute($preview, [
            2 => ImportRowAction::LINK,
        ]);

        $this->assertCount(1, $bus->dispatched, 'Le géocodage doit être lancé même si l\'adresse n\'a pas changé (coords manquantes)');
        $this->assertInstanceOf(GeocodeProspectMessage::class, $bus->dispatched[0]->getMessage());
        $msg = $bus->dispatched[0]->getMessage();
        $this->assertSame(2682, $msg->prospectId);
    }

    /**
     * Construit un ImportPreview de test avec N lignes.
     *
     * Format des rows : [organisation, nom, prenom, email, telephone, adresse, codePostal, ville]
     *
     * @param list<array{0:string,1:string,2:string,3:string,4:?string,5:?string,6:?string,7:?string}> $rows
     */
    private function createPreview(array $rows): ImportPreview
    {
        return $this->createPreviewWithMode($rows, 'new', null, 'Test');
    }

    /**
     * Construit un ImportPreview avec contrôle du mode et de la cible.
     *
     * @param list<array{0:string,1:string,2:string,3:string,4:?string,5:?string,6:?string,7:?string}> $rows
     */
    private function createPreviewWithMode(array $rows, string $mode, ?int $cibleId, ?string $cibleTitle): ImportPreview
    {
        $preview = new ImportPreview(
            filename: 'test.csv',
            mode: $mode,
            ciblePrincipaleId: $cibleId,
            ciblePrincipaleTitle: $cibleTitle,
        );

        $groups = [];
        foreach ($rows as $idx => $row) {
            $rowNumber = $idx + 2;
            [$org, $nom, $prenom, $email, $tel, $adresse, $cp, $ville] = $row;

            $cle = 'import:'.strtolower($org);
            if (!isset($groups[$cle])) {
                $groups[$cle] = new ImportGroup($cle);
            }

            $importRow = new ImportRow(
                rowNumber: $rowNumber,
                organisation: $org,
                prenom: $prenom,
                nom: $nom,
                nomComplet: $prenom.' '.$nom,
                email: $email,
                telephone: null !== $tel ? '+33000000000' : null,
                telephoneBrut: $tel,
                fonction: null,
                adresse: $adresse,
                codePostal: $cp,
                ville: $ville,
                siteWeb: null,
                cleEntreprise: $cle,
            );

            $ar = new AnalyzedImportRow(
                row: $importRow,
                status: ImportRowStatus::OK,
                existingProspectId: null,
                existingContactId: null,
                defaultAction: ImportRowAction::IMPORT,
            );

            $groups[$cle]->addRow($ar);
        }

        $preview->groups = array_values($groups);

        return $preview;
    }

    /**
     * GeoResolver est final, donc on ne peut pas le mocker via PHPUnit.
     * On crée un mock de DepartementRepository (qui est lui-meme non final),
     * on injecte dans le resolver via réflexion, et on pré-remplit le cache
     * pour que trouver() short-circuit immédiatement.
     */
    private function makeGeoResolverStub(): GeoResolver
    {
        $deptRepo = $this->createMock(\App\Repository\DepartementRepository::class);

        $geo = (new \ReflectionClass(GeoResolver::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty($geo, 'departements'))->setValue($geo, $deptRepo);
        (new \ReflectionProperty($geo, 'cache'))->setValue($geo, []);

        return $geo;
    }

    /**
     * Mock d'EntityManagerInterface qui collecte les entités passées à persist()
     * dans un tableau `persisted` (utilisé par les tests qui veulent inspecter
     * le Prospect / Contact effectivement persisté).
     */
    private function makeTrackingEntityManager(): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = [];
        $em->method('persist')->willReturnCallback(function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->persisted = &$persisted;

        return $em;
    }

    /**
     * Mock d'un MessageBusInterface qui collecte les messages dispatchés.
     */
    private function makeMessageBusStub(): MessageBusInterface
    {
        return new class implements MessageBusInterface {
            /** @var list<Envelope> */
            public array $dispatched = [];

            public function dispatch(object $message, array|stamps $stamps = []): Envelope
            {
                $envelope = $message instanceof Envelope ? $message : new Envelope($message);
                $this->dispatched[] = $envelope;

                return $envelope;
            }
        };
    }

    public function testExecuteCréeProspectSansContactSiNomOuPrenomManquant(): void
    {
        // Cas 1+2 : Organisation + email (pas de Nom/Prénom) → Prospect sans Contact.
        $em = $this->makeTrackingEntityManager();

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        $preview = $this->createPreviewWithMode([
            ['Société X', '', '', 'contact@x.com', null, '10 rue Test', '75002', 'Paris'],
        ], 'new', null, 'Test');

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        $result = $executor->execute($preview, [2 => ImportRowAction::IMPORT]);

        $this->assertSame(1, $result->prospectsCreated);
        $this->assertSame(0, $result->contactsCreated, 'Aucun Contact car Nom et Prénom manquent');
    }

    public function testExecuteCopieEmailSurProspectSiNomOuPrenomManque(): void
    {
        // Cas 2 : email seul → l'email doit être copié sur Prospect.email.
        $em = $this->makeTrackingEntityManager();

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        $preview = $this->createPreviewWithMode([
            ['Société X', '', '', 'contact@x.com', null, '10 rue Test', '75002', 'Paris'],
        ], 'new', null, 'Test');

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        $executor->execute($preview, [2 => ImportRowAction::IMPORT]);

        $persistedProspect = null;
        foreach ($em->persisted as $arg) {
            if ($arg instanceof Prospect) {
                $persistedProspect = $arg;
                break;
            }
        }
        $this->assertNotNull($persistedProspect);
        $this->assertSame('contact@x.com', $persistedProspect->getEmail(), 'Email doit être copié sur Prospect');
    }

    public function testExecuteCréeContactSansEmailSiRowNaPasEmail(): void
    {
        // Cas 3 : Nom + Prénom présents, pas d'email → Contact créé SANS email.
        $em = $this->makeTrackingEntityManager();

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        $preview = $this->createPreviewWithMode([
            ['Société X', 'DUPONT', 'Marie', '', null, '10 rue Test', '75002', 'Paris'],
        ], 'new', null, 'Test');

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        $result = $executor->execute($preview, [2 => ImportRowAction::IMPORT]);

        $this->assertSame(1, $result->prospectsCreated);
        $this->assertSame(1, $result->contactsCreated);

        $persistedContact = null;
        foreach ($em->persisted as $arg) {
            if ($arg instanceof \App\Entity\Contact) {
                $persistedContact = $arg;
                break;
            }
        }
        $this->assertNotNull($persistedContact);
        $this->assertTrue('' === (string) $persistedContact->getEmail() || null === $persistedContact->getEmail(), 'Email du Contact doit rester null/vide');
    }

    public function testExecuteEmailVaUniquementSurContactPasSurProspectSiContactCree(): void
    {
        // Cas 4 (complet) : Nom + Prénom + email → email uniquement sur le Contact,
        // PAS dupliqué sur Prospect.
        $em = $this->makeTrackingEntityManager();

        $prospectsRepo = $this->createMock(ProspectRepository::class);
        $prospectsRepo->method('findByClesEntreprise')->willReturn([]);
        $contactRepo = $this->createMock(ContactRepository::class);
        $ciblesRepo = $this->createMock(CibleRepository::class);
        $campagnesRepo = $this->createMock(CampagneRepository::class);
        $geo = $this->makeGeoResolverStub();
        $bus = $this->makeMessageBusStub();

        $preview = $this->createPreviewWithMode([
            ['Société X', 'DUPONT', 'Marie', 'marie@example.com', null, '10 rue Test', '75002', 'Paris'],
        ], 'new', null, 'Test');

        $executor = new ImportExecutor(
            $em,
            $prospectsRepo,
            $contactRepo,
            $ciblesRepo,
            $campagnesRepo,
            $geo,
            $bus,
        );

        $executor->execute($preview, [2 => ImportRowAction::IMPORT]);

        $persistedProspect = null;
        $persistedContact = null;
        foreach ($em->persisted as $arg) {
            if ($arg instanceof Prospect) {
                $persistedProspect = $arg;
            } elseif ($arg instanceof \App\Entity\Contact) {
                $persistedContact = $arg;
            }
        }
        $this->assertNotNull($persistedProspect);
        $this->assertNotNull($persistedContact);
        $this->assertSame('marie@example.com', $persistedContact->getEmail());
        $this->assertNull($persistedProspect->getEmail(), 'Email ne doit pas être dupliqué sur Prospect quand un Contact est créé');
    }
}
