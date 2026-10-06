<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Cible;
use App\Form\CibleType;
use App\Repository\CampagneRepository;
use App\Repository\CategoryRepository;
use App\Repository\CibleRepository;
use App\Service\Import\ImportAnalyzer;
use App\Service\Import\ImportExecutor;
use App\Service\Import\ImportPreview;
use App\Service\Import\ImportRowAction;
use App\Service\Import\ImportRowStatus;
use App\Service\Import\ImportTemplateGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/cibles')]
class CibleController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    private const SESSION_KEY = 'cible_import_context';
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private CibleRepository $cibles,
        private CategoryRepository $categories,
        private CampagneRepository $campagnes,
        private EntityManagerInterface $em,
        private ImportAnalyzer $analyzer,
        private ImportExecutor $executor,
        private ImportTemplateGenerator $templateGenerator,
    ) {
    }

    #[Route('', name: 'app_user_cibles')]
    public function list(): Response
    {
        return $this->renderLayout('cibles/list.html.twig', 'Cibles', [
            'routesubmit' => 'app_user_cibles_submit',
            'routeupdate' => 'app_user_cibles_update',
            'routeimport' => 'app_user_cibles_import',
            'routeprospects' => 'app_user_prospects',
            'cibles' => $this->cibles->findAllOrdered(),
            'campagnes' => $this->campagnes->findAll(),
        ]);
    }

    #[Route('/submit', name: 'app_user_cibles_submit')]
    public function submit(Request $request): Response
    {
        $cible = new Cible();

        $form = $this->createForm(CibleType::class, $cible);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($cible);
            $this->em->flush();

            return $this->redirectToRoute('app_user_cibles_update', ['id' => $cible->getId()]);
        }

        return $this->renderLayout('cibles/edit.html.twig', 'Nouvelle cible', [
            'routecancel' => 'app_user_cibles',
            'form' => $form,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_cibles_update')]
    public function update(int $id, Request $request): Response
    {
        $cible = $this->cibles->find($id);
        if (!$cible) {
            return $this->redirectToRoute('app_user_cibles');
        }

        $form = $this->createForm(CibleType::class, $cible);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->redirectToRoute('app_user_cibles');
        }

        return $this->renderLayout('cibles/edit.html.twig', 'Modification = '.$cible->getTitre(), [
            'routecancel' => 'app_user_cibles',
            'routedelete' => 'app_user_cibles_delete',
            'form' => $form,
            'cible' => $cible,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_cibles_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $cible = $this->cibles->find($id);
        if (!$cible) {
            return $this->redirectToRoute('app_user_cibles');
        }

        return $this->deleteEntity($request, $cible, $id, 'delete-cible', 'cibles', $this->em, [
            'list' => 'app_user_cibles',
            'update' => 'app_user_cibles_update',
        ]);
    }

    // ========================================================================
    // Import tableur
    // ========================================================================

    #[Route('/import', name: 'app_user_cibles_import', methods: ['GET', 'POST'])]
    public function import(Request $request, SessionInterface $session): Response
    {
        // Nettoyer le contexte d'import précédent
        $this->clearImportContext($request, $session);

        $form = $this->createImportForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            /** @var UploadedFile|null $file */
            $file = $data['file'] ?? null;

            if (null === $file) {
                $this->addFlash('error', 'Veuillez sélectionner un fichier.');

                return $this->redirectToRoute('app_user_cibles_import');
            }

            if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
                $this->addFlash('error', sprintf('Le fichier dépasse la taille maximale (%d Mo).', self::MAX_UPLOAD_BYTES / 1024 / 1024));

                return $this->redirectToRoute('app_user_cibles_import');
            }

            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                $this->addFlash('error', 'Format non supporté. Formats acceptés : .xlsx ou .csv.');

                return $this->redirectToRoute('app_user_cibles_import');
            }

            // Stocker le fichier temporairement
            $tmpDir = sys_get_temp_dir().'/nine_import';
            if (!is_dir($tmpDir)) {
                @mkdir($tmpDir, 0775, true);
            }
            $tmpPath = $tmpDir.'/'.uniqid('import_', true).'.'.$extension;
            try {
                $file->move($tmpDir, basename($tmpPath));
            } catch (\Throwable $e) {
                $this->addFlash('error', 'Impossible de stocker le fichier : '.$e->getMessage());

                return $this->redirectToRoute('app_user_cibles_import');
            }

            // Logique de ciblage :
            //  - si le multi-select "Cibles" est rempli ET un titre est saisi → on privilégie
            //    la nouvelle cible (titre saisi) + on rattache aussi aux cibles existantes
            //  - si seul le multi-select est rempli → on rattache à ces cibles (mode existing)
            //  - si seul le titre est saisi → on crée une nouvelle cible seule
            //  - si rien n'est rempli → import sans rattachement à une cible
            $cibleIds = array_values(array_filter(array_map('intval', (array) ($data['cibles'] ?? []))));
            $titre = trim((string) ($data['cible_nouvelle_titre'] ?? ''));

            $mode = 'existing';
            $ciblePrincipaleId = null;
            $ciblePrincipaleTitle = null;

            if ('' !== $titre && [] === $cibleIds) {
                // Cas simple : on crée une nouvelle cible seule
                $mode = 'new';
                $ciblePrincipaleTitle = $titre;
            } elseif ('' !== $titre) {
                // Titre + cibles existantes : on crée la nouvelle cible ET on
                // rattache aux cibles existantes via le multi-select
                $mode = 'new';
                $ciblePrincipaleTitle = $titre;
            } elseif ([] !== $cibleIds) {
                // Que des cibles existantes : mode existing
                $mode = 'existing';
            }
            // Sinon : tout est vide → import sans rattachement

            // Vérifier que les IDs du multi-select existent réellement
            $cibleIds = array_values(array_filter(
                $cibleIds,
                fn (int $id): bool => null !== $this->cibles->find($id),
            ));

            // Campagne (optionnelle)
            $campagneId = null;
            if (!empty($data['campagne_id'])) {
                $campagneId = (int) $data['campagne_id'];
                if (null === $this->campagnes->find($campagneId)) {
                    @unlink($tmpPath);
                    $this->addFlash('error', 'Campagne sélectionnée introuvable.');

                    return $this->redirectToRoute('app_user_cibles_import');
                }
            }

            // Catégories supplémentaires (optionnelles) : multi-select, on vérifie
            // que les IDs existent réellement et on dédoublonne avec la cible
            // principale (pas de chevauchement possible entre Cible et Catégorie).
            $categorieIds = array_values(array_filter(array_map('intval', (array) ($data['categories'] ?? []))));
            $categorieIds = array_values(array_filter(
                $categorieIds,
                fn (int $id): bool => null !== $this->categories->find($id),
            ));

            // Stockage du contexte pour l'étape preview
            // Si mode "new", la nouvelle cible est la principale (ciblePrincipaleId sera défini après création)
            // Les autres IDs du multi-select sont les "cibles supplémentaires"
            $ciblesSupplementairesIds = [];
            if ('new' === $mode && [] !== $cibleIds) {
                $ciblesSupplementairesIds = $cibleIds;
            } elseif ('existing' === $mode) {
                $ciblePrincipaleId = $cibleIds[0] ?? null;
                $ciblesSupplementairesIds = array_slice($cibleIds, 1);
            }

            $context = [
                'filePath' => $tmpPath,
                'tmpPath' => $tmpPath,
                'filename' => $file->getClientOriginalName(),
                'mode' => $mode,
                'ciblePrincipaleId' => $ciblePrincipaleId,
                'ciblePrincipaleTitle' => $ciblePrincipaleTitle,
                'ciblesSupplementairesIds' => $ciblesSupplementairesIds,
                'campagneId' => $campagneId,
                'categoriesSupplementairesIds' => $categorieIds,
            ];
            $this->saveImportContext($request, $session, $context);

            return $this->redirectToRoute('app_user_cibles_import_preview');
        }

        return $this->renderLayout('cibles/import.html.twig', 'Importer une cible', [
            'form' => $form->createView(),
            'routetemplate' => 'app_user_cibles_import_template',
            'routecancel' => 'app_user_cibles',
            'cibles' => $this->cibles->findAllOrdered(),
            'campagnes' => $this->campagnes->findAll(),
        ]);
    }

    #[Route('/import/preview', name: 'app_user_cibles_import_preview', methods: ['GET'])]
    public function importPreview(Request $request, SessionInterface $session): Response
    {
        $context = $this->getImportContext($request, $session);
        if (null === $context) {
            $this->addFlash('error', "Le contexte d'import a expiré. Recommencez l'upload.");

            return $this->redirectToRoute('app_user_cibles_import');
        }

        /** @var array{filename: string, filePath: string, mode: string, ciblePrincipaleId: int|null, ciblePrincipaleTitle: string|null, ciblesSupplementairesIds: list<int>, campagneId: int|null, categoriesSupplementairesIds: list<int>} $context */
        $preview = $this->analyzer->analyze($context);

        if ($preview->isFatal()) {
            // Retour à l'écran 1 avec un message
            $this->addFlash('error', $preview->fatalError ?? 'Colonnes obligatoires manquantes.');
            $this->clearImportContext($request, $session);

            return $this->redirectToRoute('app_user_cibles_import');
        }

        // Réécrire le contexte pour inclure la preview sérialisée
        $context['preview'] = $preview;
        $this->saveImportContext($request, $session, $context);

        return $this->renderLayout('cibles/import_preview.html.twig', 'Pré-import : '.$context['filename'], [
            'preview' => $preview,
            'context' => $context,
            'routeimport' => 'app_user_cibles_import',
            'rowActions' => ImportRowAction::ALL,
            'rowStatuses' => [
                ImportRowStatus::OK => 'OK',
                ImportRowStatus::ERROR => 'Erreur',
                ImportRowStatus::DUPLICATE_PROSPECT_DB => 'Doublon prospect',
                ImportRowStatus::DUPLICATE_CONTACT_DB => 'Doublon contact',
                ImportRowStatus::DUPLICATE_INTRA => 'Doublon interne',
            ],
        ]);
    }

    #[Route('/import/execute', name: 'app_user_cibles_import_execute', methods: ['POST'])]
    public function importExecute(Request $request, SessionInterface $session): Response
    {
        if (!$this->isCsrfTokenValid('cible-import-execute', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_user_cibles_import');
        }

        $context = $this->getImportContext($request, $session);
        if (null === $context || !isset($context['preview'])) {
            $this->addFlash('error', "Le contexte d'import a expiré. Recommencez l'upload.");

            return $this->redirectToRoute('app_user_cibles_import');
        }

        /** @var ImportPreview $preview */
        $preview = $context['preview'];

        // Lecture des actions par ligne depuis le formulaire
        $rowActions = (array) $request->request->all('row_actions');
        $rowActions = array_map(static fn ($v) => (string) $v, $rowActions);

        $result = $this->executor->execute($preview, $rowActions);

        // Nettoyage du fichier temp + contexte
        if (isset($context['tmpPath']) && is_file($context['tmpPath'])) {
            @unlink($context['tmpPath']);
        }
        $this->clearImportContext($request, $session);

        return $this->renderLayout('cibles/import_report.html.twig', 'Rapport d\'import', [
            'result' => $result,
            'routecible' => 'app_user_cibles_update',
            'routecancel' => 'app_user_cibles',
        ]);
    }

    #[Route('/import/template', name: 'app_user_cibles_import_template', methods: ['GET'])]
    public function importTemplate(): Response
    {
        $content = $this->templateGenerator->generate();
        $tmpPath = sys_get_temp_dir().'/nine_import_template_'.uniqid().'.xlsx';
        file_put_contents($tmpPath, $content);

        $response = new BinaryFileResponse($tmpPath);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'modele-import-cible.xlsx',
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createImportForm(): \Symfony\Component\Form\FormInterface
    {
        $cibles = $this->cibles->findAllOrdered();
        $campagnes = $this->campagnes->findAll();

        $builder = $this->createFormBuilder()
            ->add('cible_nouvelle_titre', \Symfony\Component\Form\Extension\Core\Type\TextType::class, [
                'label' => 'Titre de la nouvelle cible (optionnel — créez une nouvelle cible si aucune ne convient)',
                'required' => false,
                'attr' => ['placeholder' => 'Ex : Conseils départementaux 2026'],
            ]);

        // Une seule zone "Cibles existantes" : multi-select, peut être vide.
        // Si le champ est rempli, les prospects importés seront rattachés à ces cibles.
        // Si le champ est vide ET que "Titre de la nouvelle cible" est rempli, une nouvelle
        // cible sera créée. Sinon, l'import se fait sans rattachement à une cible.
        if ([] === $cibles) {
            $builder->add('cibles', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Cibles existantes',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => [],
                'disabled' => true,
                'help' => 'Aucune cible existante — créez-en une via le champ ci-dessus.',
                'attr' => ['class' => 'select2'],
            ]);
        } else {
            $builder->add('cibles', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Cibles existantes (optionnel — laissez vide pour importer sans cible)',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => array_combine(
                    array_map(static fn (Cible $c) => (string) $c, $cibles),
                    array_map(static fn (Cible $c) => (string) $c->getId(), $cibles),
                ),
                'attr' => ['class' => 'select2'],
            ]);
        }

        if ([] === $campagnes) {
            $builder->add('campagne_id', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Campagne (optionnel)',
                'required' => false,
                'choices' => [],
                'disabled' => true,
            ]);
        } else {
            $builder->add('campagne_id', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Campagne (optionnel)',
                'required' => false,
                'placeholder' => '— Aucune —',
                'choices' => array_combine(
                    array_map(static fn ($c) => (string) $c, $campagnes),
                    array_map(static fn ($c) => (string) $c->getId(), $campagnes),
                ),
            ]);
        }

        // Catégories existantes (multi-select, optionnel) : tous les prospects
        // importés seront rattachés à ces catégories. Symétrique au multi-select
        // cibles ci-dessus.
        $categories = $this->categories->findAllOrdered();
        if ([] === $categories) {
            $builder->add('categories', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Catégories (optionnel)',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => [],
                'disabled' => true,
                'help' => 'Aucune catégorie existante — créez-en une via /user/categories.',
                'attr' => ['class' => 'select2'],
            ]);
        } else {
            $builder->add('categories', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Catégories (optionnel — laissez vide pour importer sans catégorie)',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => array_combine(
                    array_map(static fn (\App\Entity\Category $c) => (string) $c, $categories),
                    array_map(static fn (\App\Entity\Category $c) => (string) $c->getId(), $categories),
                ),
                'attr' => ['class' => 'select2'],
            ]);
        }

        $builder->add('file', \Symfony\Component\Form\Extension\Core\Type\FileType::class, [
            'label' => 'Fichier (.xlsx ou .csv, max 10 Mo)',
            'required' => true,
            'constraints' => [
                new \Symfony\Component\Validator\Constraints\File([
                    'maxSize' => '10M',
                    'mimeTypes' => [
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                        'text/csv',
                        'text/plain',
                        'application/csv',
                    ],
                ]),
            ],
        ]);

        return $builder->getForm();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getImportContext(Request $request, SessionInterface $session): ?array
    {
        if (!$session->has(self::SESSION_KEY)) {
            return null;
        }
        $ctx = $session->get(self::SESSION_KEY);

        // Vérifier que le fichier temporaire existe encore
        if (isset($ctx['tmpPath']) && !is_file($ctx['tmpPath'])) {
            $this->clearImportContext($request, $session);

            return null;
        }

        return is_array($ctx) ? $ctx : null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function saveImportContext(Request $request, SessionInterface $session, array $context): void
    {
        $session->set(self::SESSION_KEY, $context);
    }

    private function clearImportContext(Request $request, SessionInterface $session): void
    {
        if ($session->has(self::SESSION_KEY)) {
            $ctx = $session->get(self::SESSION_KEY);
            if (is_array($ctx) && isset($ctx['tmpPath']) && is_file($ctx['tmpPath'])) {
                @unlink($ctx['tmpPath']);
            }
            $session->remove(self::SESSION_KEY);
        }
    }
}
