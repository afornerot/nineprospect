<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Entity\Config;
use App\Form\ConfigType;
use App\Repository\ConfigRepository;
use App\Service\ConfigParameterBag;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class ConfigController extends AbstractController
{
    use LayoutRenderTrait;

    private const ROUTE_PREFIX = 'app_admin_config';

    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
    ) {}

    #[Route('/admin/config', name: self::ROUTE_PREFIX)]
    public function list(): Response
    {
        $configs = $this->configRepository->findBy([], ['configGroup' => 'ASC', 'order' => 'ASC', 'id' => 'ASC']);

        return $this->renderLayout('config/list.html.twig', 'Configuration', [
            'configs' => $configs,
            'routeupdate' => self::ROUTE_PREFIX.'_update',
        ]);
    }

    #[Route('/admin/config/update/{id}', name: self::ROUTE_PREFIX.'_update')]
    public function update(int $id, Request $request, ConfigParameterBag $configParameterBag): Response
    {
        $config = $this->configRepository->find($id);
        if (!$config) {
            return $this->redirectToRoute(self::ROUTE_PREFIX);
        }

        $form = $this->createForm(ConfigType::class, $config);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $rawValue = $form->get('rawValue')->getData();
            $config->setRawValue($rawValue);
            $this->em->flush();
            $configParameterBag->reload();

            return $this->redirectToRoute(self::ROUTE_PREFIX);
        }

        return $this->renderLayout('config/edit.html.twig', 'Modifier Configuration = '.$config->getCode(), [
            'config' => $config,
            'routecancel' => self::ROUTE_PREFIX,
            'routedelete' => self::ROUTE_PREFIX.'_delete',
            'form' => $form,
        ]);
    }

    #[Route('/admin/config/delete/{id}', name: self::ROUTE_PREFIX.'_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, ConfigParameterBag $configParameterBag): Response
    {
        $config = $this->configRepository->find($id);
        if (!$config) {
            return $this->redirectToRoute(self::ROUTE_PREFIX);
        }

        if (!$this->isCsrfTokenValid('delete-config-'.$id, $request->request->get('_token'))) {
            return $this->redirectToRoute(self::ROUTE_PREFIX);
        }

        $config->setRawValue(null);
        $this->em->flush();
        $configParameterBag->reload();

        return $this->redirectToRoute(self::ROUTE_PREFIX);
    }
}