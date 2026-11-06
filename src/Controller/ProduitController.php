<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Produit;
use App\Form\ProduitType;
use App\Http\ContexteRetour;
use App\Repository\CampagneRepository;
use App\Repository\CibleRepository;
use App\Repository\ProduitRepository;
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

#[Route('/user/produits')]
class ProduitController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    private const SESSION_KEY = 'produit_import_context';
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB

    public function __construct(
        private ProduitRepository $produits,
        private CibleRepository $cibles,
        private CampagneRepository $campagnes,
        private EntityManagerInterface $em,
        private ImportAnalyzer $analyzer,
        private ImportExecutor $executor,
        private ImportTemplateGenerator $templateGenerator,
        protected ContexteRetour $contexteRetour,
    ) {
    }

    #[Route('', name: 'app_user_produits')]
    public function list(): Response
    {
        return $this->renderLayout('produits/list.html.twig', 'Produits', [
            'routesubmit' => 'app_user_produits_submit',
            'routeupdate' => 'app_user_produits_update',
            'routeimport' => 'app_user_produits_import',
            'routeprospects' => 'app_user_prospects',
            'produits' => $this->produits->findAllOrdered(),
            'campagnes' => $this->campagnes->findAll(),
        ]);
    }

    #[Route('/submit', name: 'app_user_produits_submit')]
    public function submit(Request $request): Response
    {
        $produit = new Produit();

        $form = $this->createForm(ProduitType::class, $produit);
        $retour = $this->contexteRetour->listeDuModule($request, 'app_user_produits');
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->persist($produit);
            $this->em->flush();

            // La création ouvre la fiche : c'est là que se joignent les
            // documents de le produit.
            return $this->redirectToRoute('app_user_produits_update', ['id' => $produit->getId()]);
        }

        return $this->renderLayout('produits/edit.html.twig', 'Nouveau produit', [
            'form' => $form,
            'retour' => $retour,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_produits_update')]
    public function update(int $id, Request $request): Response
    {
        $produit = $this->produits->find($id);
        if (!$produit) {
            return $this->contexteRetour->redirectionVersListe($request, 'app_user_produits');
        }

        $form = $this->createForm(ProduitType::class, $produit);
        $retour = $this->contexteRetour->listeDuModule($request, 'app_user_produits');
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();

            return $this->contexteRetour->redirectionVersListe($request, 'app_user_produits');
        }

        return $this->renderLayout('produits/edit.html.twig', 'Modification = '.$produit->getTitre(), [
            'routedelete' => 'app_user_produits_delete',
            'form' => $form,
            'produit' => $produit,
            'retour' => $retour,
        ]);
    }

    #[Route('/delete/{id}', name: 'app_user_produits_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $produit = $this->produits->find($id);
        if (!$produit) {
            return $this->contexteRetour->redirectionVersListe($request, 'app_user_produits');
        }

        return $this->deleteEntity($request, $produit, $id, 'delete-produit', 'produits', $this->em, [
            'list' => 'app_user_produits',
            'update' => 'app_user_produits_update',
        ]);
    }

    // ========================================================================
    // Import tableur
    // ========================================================================

    #[Route('/import', name: 'app_user_produits_import', methods: ['GET', 'POST'])]
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

                return $this->redirectToRoute('app_user_produits_import');
            }

            if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
                $this->addFlash('error', sprintf('Le fichier dépasse la taille maximale (%d Mo).', self::MAX_UPLOAD_BYTES / 1024 / 1024));

                return $this->redirectToRoute('app_user_produits_import');
            }

            $extension = strtolower($file->getClientOriginalExtension());
            if (!in_array($extension, ['xlsx', 'csv'], true)) {
                $this->addFlash('error', 'Format non supporté. Formats acceptés : .xlsx ou .csv.');

                return $this->redirectToRoute('app_user_produits_import');
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

                return $this->redirectToRoute('app_user_produits_import');
            }

            // Logique de ciblage :
            //  - si le multi-select "Produits" est rempli ET un titre est saisi → on privilégie
            //    le nouveau produit (titre saisi) + on rattache aussi aux produits existants
            //  - si seul le multi-select est rempli → on rattache à ces produits (mode existing)
            //  - si seul le titre est saisi → on crée un nouveau produit seul
            //  - si rien n'est rempli → import sans rattachement à un produit
            $produitIds = array_values(array_filter(array_map('intval', (array) ($data['produits'] ?? []))));
            $titre = trim((string) ($data['produit_nouvelle_titre'] ?? ''));

            $mode = 'existing';
            $produitPrincipaleId = null;
            $produitPrincipaleTitle = null;

            if ('' !== $titre && [] === $produitIds) {
                // Cas simple : on crée un nouveau produit seul
                $mode = 'new';
                $produitPrincipaleTitle = $titre;
            } elseif ('' !== $titre) {
                // Titre + produits existants : on crée le nouveau produit ET on
                // rattache aux produits existants via le multi-select
                $mode = 'new';
                $produitPrincipaleTitle = $titre;
            } elseif ([] !== $produitIds) {
                // Que des produits existants : mode existing
                $mode = 'existing';
            }
            // Sinon : tout est vide → import sans rattachement

            // Vérifier que les IDs du multi-select existent réellement
            $produitIds = array_values(array_filter(
                $produitIds,
                fn (int $id): bool => null !== $this->produits->find($id),
            ));

            // Campagne (optionnelle)
            $campagneId = null;
            if (!empty($data['campagne_id'])) {
                $campagneId = (int) $data['campagne_id'];
                if (null === $this->campagnes->find($campagneId)) {
                    @unlink($tmpPath);
                    $this->addFlash('error', 'Campagne sélectionnée introuvable.');

                    return $this->redirectToRoute('app_user_produits_import');
                }
            }

            // Cibles supplémentaires (optionnelles) : multi-select, on vérifie
            // que les IDs existent réellement et on dédoublonne avec le produit
            // principale (pas de chevauchement possible entre Produit et Cible).
            $cibleIds = array_values(array_filter(array_map('intval', (array) ($data['cibles'] ?? []))));
            $cibleIds = array_values(array_filter(
                $cibleIds,
                fn (int $id): bool => null !== $this->cibles->find($id),
            ));

            // Stockage du contexte pour l'étape preview
            // Si mode "new", le nouveau produit est la principale (produitPrincipaleId sera défini après création)
            // Les autres IDs du multi-select sont les "produits supplémentaires"
            $produitsSupplementairesIds = [];
            if ('new' === $mode && [] !== $produitIds) {
                $produitsSupplementairesIds = $produitIds;
            } elseif ('existing' === $mode) {
                $produitPrincipaleId = $produitIds[0] ?? null;
                $produitsSupplementairesIds = array_slice($produitIds, 1);
            }

            $context = [
                'filePath' => $tmpPath,
                'tmpPath' => $tmpPath,
                'filename' => $file->getClientOriginalName(),
                'mode' => $mode,
                'produitPrincipaleId' => $produitPrincipaleId,
                'produitPrincipaleTitle' => $produitPrincipaleTitle,
                'produitsSupplementairesIds' => $produitsSupplementairesIds,
                'campagneId' => $campagneId,
                'ciblesSupplementairesIds' => $cibleIds,
            ];
            $this->saveImportContext($request, $session, $context);

            return $this->redirectToRoute('app_user_produits_import_preview');
        }

        return $this->renderLayout('produits/import.html.twig', 'Importer un produit', [
            'form' => $form->createView(),
            'routetemplate' => 'app_user_produits_import_template',
            'retour' => $this->contexteRetour->listeDuModule($request, 'app_user_produits'),
            'produits' => $this->produits->findAllOrdered(),
            'campagnes' => $this->campagnes->findAll(),
        ]);
    }

    #[Route('/import/preview', name: 'app_user_produits_import_preview', methods: ['GET'])]
    public function importPreview(Request $request, SessionInterface $session): Response
    {
        $context = $this->getImportContext($request, $session);
        if (null === $context) {
            $this->addFlash('error', "Le contexte d'import a expiré. Recommencez l'upload.");

            return $this->redirectToRoute('app_user_produits_import');
        }

        /** @var array{filename: string, filePath: string, mode: string, produitPrincipaleId: int|null, produitPrincipaleTitle: string|null, produitsSupplementairesIds: list<int>, campagneId: int|null, ciblesSupplementairesIds: list<int>} $context */
        $preview = $this->analyzer->analyze($context);

        if ($preview->isFatal()) {
            // Retour à l'écran 1 avec un message
            $this->addFlash('error', $preview->fatalError ?? 'Colonnes obligatoires manquantes.');
            $this->clearImportContext($request, $session);

            return $this->redirectToRoute('app_user_produits_import');
        }

        // Réécrire le contexte pour inclure la preview sérialisée
        $context['preview'] = $preview;
        $this->saveImportContext($request, $session, $context);

        return $this->renderLayout('produits/import_preview.html.twig', 'Pré-import : '.$context['filename'], [
            'preview' => $preview,
            'context' => $context,
            'routeimport' => 'app_user_produits_import',
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

    #[Route('/import/execute', name: 'app_user_produits_import_execute', methods: ['POST'])]
    public function importExecute(Request $request, SessionInterface $session): Response
    {
        if (!$this->isCsrfTokenValid('produit-import-execute', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Token CSRF invalide.');

            return $this->redirectToRoute('app_user_produits_import');
        }

        $context = $this->getImportContext($request, $session);
        if (null === $context || !isset($context['preview'])) {
            $this->addFlash('error', "Le contexte d'import a expiré. Recommencez l'upload.");

            return $this->redirectToRoute('app_user_produits_import');
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

        return $this->renderLayout('produits/import_report.html.twig', 'Rapport d\'import', [
            'result' => $result,
            'routeproduit' => 'app_user_produits_update',
            'retour' => $this->contexteRetour->listeDuModule($request, 'app_user_produits'),
        ]);
    }

    #[Route('/import/template', name: 'app_user_produits_import_template', methods: ['GET'])]
    public function importTemplate(): Response
    {
        $content = $this->templateGenerator->generate();
        $tmpPath = sys_get_temp_dir().'/nine_import_template_'.uniqid().'.xlsx';
        file_put_contents($tmpPath, $content);

        $response = new BinaryFileResponse($tmpPath);
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'modele-import-produit.xlsx',
        );
        $response->deleteFileAfterSend(true);

        return $response;
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function createImportForm(): \Symfony\Component\Form\FormInterface
    {
        $produits = $this->produits->findAllOrdered();
        $campagnes = $this->campagnes->findAll();

        $builder = $this->createFormBuilder()
            ->add('produit_nouvelle_titre', \Symfony\Component\Form\Extension\Core\Type\TextType::class, [
                'label' => 'Titre de le nouveau produit (optionnel — créez un nouveau produit si aucune ne convient)',
                'required' => false,
                'attr' => ['placeholder' => 'Ex : Conseils départementaux 2026'],
            ]);

        // Une seule zone "Produits existantes" : multi-select, peut être vide.
        // Si le champ est rempli, les prospects importés seront rattachés à ces produits.
        // Si le champ est vide ET que "Titre de le nouveau produit" est rempli, une nouvelle
        // produit sera créée. Sinon, l'import se fait sans rattachement à un produit.
        if ([] === $produits) {
            $builder->add('produits', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Produits existantes',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => [],
                'disabled' => true,
                'help' => 'Aucun produit existant — créez-en une via le champ ci-dessus.',
                'attr' => ['class' => 'select2'],
            ]);
        } else {
            $builder->add('produits', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Produits existantes (optionnel — laissez vide pour importer sans produit)',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => array_combine(
                    array_map(static fn (Produit $c) => (string) $c, $produits),
                    array_map(static fn (Produit $c) => (string) $c->getId(), $produits),
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

        // Cibles existantes (multi-select, optionnel) : tous les prospects
        // importés seront rattachés à ces cibles. Symétrique au multi-select
        // produits ci-dessus.
        $cibles = $this->cibles->findAllOrdered();
        if ([] === $cibles) {
            $builder->add('cibles', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Cibles (optionnel)',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => [],
                'disabled' => true,
                'help' => 'Aucune cible existante — créez-en une via /user/cibles.',
                'attr' => ['class' => 'select2'],
            ]);
        } else {
            $builder->add('cibles', \Symfony\Component\Form\Extension\Core\Type\ChoiceType::class, [
                'label' => 'Cibles (optionnel — laissez vide pour importer sans cible)',
                'required' => false,
                'multiple' => true,
                'expanded' => false,
                'choices' => array_combine(
                    array_map(static fn (\App\Entity\Cible $c) => (string) $c, $cibles),
                    array_map(static fn (\App\Entity\Cible $c) => (string) $c->getId(), $cibles),
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
