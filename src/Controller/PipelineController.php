<?php

namespace App\Controller;

use App\Controller\Trait\CrudDeleteTrait;
use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Pipeline;
use App\Entity\PipelineEtape;
use App\Form\PipelineEtapeType;
use App\Form\PipelineType;
use App\Repository\PipelineRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/user/pipelines')]
class PipelineController extends AbstractController
{
    use CrudDeleteTrait;
    use LayoutRenderTrait;

    public function __construct(
        private PipelineRepository $pipelines,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_pipelines')]
    public function list(): Response
    {
        $lignes = [];
        foreach ($this->pipelines->findAllOrdered() as $pipeline) {
            $lignes[] = [
                'pipeline' => $pipeline,
                'etapes' => $pipeline->getEtapes()->count(),
                'vagues' => $this->pipelines->countSprints((int) $pipeline->getId()),
            ];
        }

        return $this->renderLayout('pipelines/list.html.twig', 'Pipelines', [
            'routesubmit' => 'app_user_pipelines_submit',
            'routeupdate' => 'app_user_pipelines_update',
            'routevague' => 'app_user_vagues',
            'lignes' => $lignes,
        ]);
    }

    #[Route('/submit', name: 'app_user_pipelines_submit')]
    public function submit(Request $request): Response
    {
        $pipeline = new Pipeline();
        $etaitDefaut = false;

        $form = $this->createForm(PipelineType::class, $pipeline);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->finaliserPipeline($pipeline, $form, $etaitDefaut)) {
            $this->em->persist($pipeline);
            $this->em->flush();

            return $this->redirectToRoute('app_user_pipelines_update', ['id' => $pipeline->getId()]);
        }

        return $this->renderLayout('pipelines/edit.html.twig', 'Nouveau pipeline', [
            'routecancel' => 'app_user_pipelines',
            'mode' => 'submit',
            'form' => $form,
            'pipeline' => $pipeline,
            'formEtapes' => [],
            'formAjout' => null,
        ]);
    }

    #[Route('/update/{id}', name: 'app_user_pipelines_update')]
    public function update(int $id, Request $request): Response
    {
        $pipeline = $this->pipelines->find($id);
        if (!$pipeline) {
            return $this->redirectToRoute('app_user_pipelines');
        }

        $etaitDefaut = $pipeline->isParDefaut();
        $form = $this->createForm(PipelineType::class, $pipeline);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->finaliserPipeline($pipeline, $form, $etaitDefaut)) {
            $this->em->flush();

            return $this->redirectToRoute('app_user_pipelines');
        }

        return $this->vueEdition($pipeline, $form);
    }

    #[Route('/delete/{id}', name: 'app_user_pipelines_delete', methods: ['POST'])]
    public function delete(int $id, Request $request): Response
    {
        $pipeline = $this->pipelines->find($id);
        if (!$pipeline) {
            return $this->redirectToRoute('app_user_pipelines');
        }

        if ($pipeline->isParDefaut()) {
            $this->addFlash('error', 'Suppression impossible : un pipeline par défaut doit rester en place.');

            return $this->redirectToRoute('app_user_pipelines_update', ['id' => $pipeline->getId()]);
        }

        return $this->deleteEntity($request, $pipeline, $id, 'delete-pipeline', 'pipelines', $this->em, [
            'list' => 'app_user_pipelines',
            'update' => 'app_user_pipelines_update',
            'conflictMessage' => 'Suppression impossible : le pipeline est encore affecté à des vagues de traitement.',
        ]);
    }

    #[Route('/{id}/etapes/submit', name: 'app_user_pipeline_etapes_submit', methods: ['POST'])]
    public function etapeSubmit(int $id, Request $request): Response
    {
        $pipeline = $this->pipelines->find($id);
        if (!$pipeline) {
            return $this->redirectToRoute('app_user_pipelines');
        }

        $etape = new PipelineEtape();
        $etape->setOrdre($pipeline->nextOrdre());
        $pipeline->addEtape($etape);

        $form = $this->createForm(PipelineEtapeType::class, $etape);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->sauverEtape($pipeline, $etape, $form)) {
            return $this->redirectToRoute('app_user_pipelines_update', ['id' => $pipeline->getId()]);
        }

        $formPrincipal = $this->createForm(PipelineType::class, $pipeline);

        return $this->vueEdition($pipeline, $formPrincipal, [], $form);
    }

    #[Route('/{id}/etapes/update/{etapeId}', name: 'app_user_pipeline_etapes_update', methods: ['POST'])]
    public function etapeUpdate(int $id, int $etapeId, Request $request): Response
    {
        $pipeline = $this->pipelines->find($id);
        if (!$pipeline) {
            return $this->redirectToRoute('app_user_pipelines');
        }

        $etape = $this->trouverEtape($pipeline, $etapeId);
        if (!$etape) {
            return $this->redirectToRoute('app_user_pipelines_update', ['id' => $pipeline->getId()]);
        }

        $form = $this->createForm(PipelineEtapeType::class, $etape);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid() && $this->sauverEtape($pipeline, $etape, $form)) {
            return $this->redirectToRoute('app_user_pipelines_update', ['id' => $pipeline->getId()]);
        }

        $formPrincipal = $this->createForm(PipelineType::class, $pipeline);

        return $this->vueEdition($pipeline, $formPrincipal, [$etapeId => $form]);
    }

    #[Route('/{id}/etapes/delete/{etapeId}', name: 'app_user_pipeline_etapes_delete', methods: ['POST'])]
    public function etapeDelete(int $id, int $etapeId, Request $request): Response
    {
        $pipeline = $this->pipelines->find($id);
        if (!$pipeline) {
            return $this->redirectToRoute('app_user_pipelines');
        }

        $etape = $this->trouverEtape($pipeline, $etapeId);
        if (!$etape) {
            return $this->redirectToRoute('app_user_pipelines_update', ['id' => $pipeline->getId()]);
        }

        return $this->deleteEntity($request, $etape, $id, 'delete-pipeline-etape', 'etapes de pipeline', $this->em, [
            'list' => 'app_user_pipelines',
            'update' => 'app_user_pipelines_update',
            'successRoute' => 'app_user_pipelines_update',
            'conflictMessage' => 'Suppression impossible : l\'étape porte déjà des valeurs de pipeline.',
        ]);
    }

    /**
     * Enregistre une étape en contrôlant d'abord l'unicité de son ordre
     * (contrainte `uniq_pipeline_etape_ordre`) : la violation devient une
     * erreur de formulaire au lieu d'une exception SQL.
     *
     * @param FormInterface<PipelineEtape> $form
     */
    private function sauverEtape(Pipeline $pipeline, PipelineEtape $etape, FormInterface $form): bool
    {
        foreach ($pipeline->getEtapes() as $autre) {
            if ($autre !== $etape && $autre->getOrdre() === $etape->getOrdre()) {
                $form->get('ordre')->addError(new FormError('Cet ordre est déjà utilisé dans ce pipeline.'));

                return false;
            }
        }

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException) {
            $form->get('ordre')->addError(new FormError('Cet ordre est déjà utilisé dans ce pipeline.'));

            return false;
        }

        return true;
    }

    /**
     * Contrôles communs création/màj : nom unique, pipeline par défaut unique,
     * puis retrait du défaut sur les autres pipelines si besoin.
     *
     * @param FormInterface<Pipeline> $form
     */
    private function finaliserPipeline(Pipeline $pipeline, FormInterface $form, bool $etaitDefaut): bool
    {
        $nom = trim((string) $pipeline->getNom());
        $doublon = $this->pipelines->findOneBy(['nom' => $nom]);
        if (null !== $doublon && $doublon->getId() !== $pipeline->getId()) {
            $form->get('nom')->addError(new FormError('Ce nom de pipeline est déjà utilisé.'));

            return false;
        }

        if (!$pipeline->isParDefaut()) {
            if ($etaitDefaut || null === $this->pipelines->findOneBy(['parDefaut' => true])) {
                $form->get('parDefaut')->addError(new FormError('Il doit exister un pipeline par défaut.'));

                return false;
            }

            return true;
        }

        foreach ($this->pipelines->findAllOrdered() as $autre) {
            if ($autre->getId() !== $pipeline->getId()) {
                $autre->setParDefaut(false);
            }
        }

        return true;
    }

    private function trouverEtape(Pipeline $pipeline, int $etapeId): ?PipelineEtape
    {
        foreach ($pipeline->getEtapes() as $etape) {
            if ($etape->getId() === $etapeId) {
                return $etape;
            }
        }

        return null;
    }

    /**
     * Mini-formulaires d'étape, convertis en FormView (le rendu Twig attend
     * des FormView, le contrôleur ne convertit que les formulaires de premier
     * niveau).
     *
     * @param array<int, FormInterface<PipelineEtape>> $formEtapesSoumis
     *
     * @return array<int, FormView>
     */
    private function formEtapes(Pipeline $pipeline, array $formEtapesSoumis = []): array
    {
        $formEtapes = [];
        foreach ($pipeline->getEtapes() as $etape) {
            $idEtape = (int) $etape->getId();
            $formEtapes[$idEtape] = ($formEtapesSoumis[$idEtape] ?? $this->createForm(PipelineEtapeType::class, $etape, [
                'action' => $this->generateUrl('app_user_pipeline_etapes_update', ['id' => $pipeline->getId(), 'etapeId' => $idEtape]),
            ]))->createView();
        }

        return $formEtapes;
    }

    /**
     * Vue d'édition : formulaire pipeline + un mini-formulaire par étape +
     * formulaire d'ajout. Les formulaires déjà soumis sont réutilisés tels
     * quels pour afficher leurs erreurs.
     *
     * @param FormInterface<Pipeline>                  $form
     * @param array<int, FormInterface<PipelineEtape>> $formEtapesSoumis
     * @param FormInterface<PipelineEtape>|null        $formAjout
     */
    private function vueEdition(Pipeline $pipeline, FormInterface $form, array $formEtapesSoumis = [], ?FormInterface $formAjout = null): Response
    {
        if (null === $formAjout) {
            $nouvelle = new PipelineEtape();
            $nouvelle->setOrdre($pipeline->nextOrdre());
            $formAjout = $this->createForm(PipelineEtapeType::class, $nouvelle, [
                'action' => $this->generateUrl('app_user_pipeline_etapes_submit', ['id' => $pipeline->getId()]),
            ]);
        }

        $response = $this->renderLayout('pipelines/edit.html.twig', null !== $pipeline->getId() ? 'Pipeline — '.$pipeline->getNom() : 'Nouveau pipeline', [
            'routecancel' => 'app_user_pipelines',
            'routedelete' => 'app_user_pipelines_delete',
            'routeEtapeDelete' => 'app_user_pipeline_etapes_delete',
            'mode' => 'update',
            'form' => $form,
            'pipeline' => $pipeline,
            'formEtapes' => $this->formEtapes($pipeline, $formEtapesSoumis),
            'formAjout' => $formAjout,
        ]);

        // Le 422 automatique du contrôleur ne couvre que les formulaires de
        // premier niveau : les mini-formulaires d'étape partent en FormView,
        // on pose donc le statut à la main.
        foreach ($formEtapesSoumis as $formSoumis) {
            if ($formSoumis->isSubmitted() && !$formSoumis->isValid()) {
                $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

                break;
            }
        }

        return $response;
    }
}
