<?php

namespace App\Controller;

use App\Controller\Trait\LayoutRenderTrait;
use App\Repository\ActionRepository;
use App\Repository\ProspectRepository;
use App\Repository\SprintRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Dompdf\Dompdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;

#[Route('/user/reporting')]
class ReportingController extends AbstractController
{
    use LayoutRenderTrait;

    public function __construct(
        private ProspectRepository $prospects,
        private ActionRepository $actions,
        private SprintRepository $sprints,
        private UserRepository $users,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'app_user_reporting')]
    public function index(): Response
    {
        $stats = [
            'totalProspects' => $this->prospects->count([]),
            'prospectsContactes' => $this->prospects->countContacte(),
            'prospectsQualifies' => $this->prospects->countQualifies(),
            'actionsEnRetard' => $this->actions->countDue(),
            'actionsAvenir' => $this->actions->countUpcoming(),
        ];

        $topDepartements = $this->prospects->countByDepartement();

        $pipelineStats = [];
        $sprints = $this->sprints->findAllOrdered();
        foreach ($sprints as $sprint) {
            $pipelineStats[] = [
                'sprint' => $sprint,
                'total' => $this->prospects->countBySprint((int) $sprint->getId()),
            ];
        }

        return $this->renderLayout('reporting/index.html.twig', 'Reporting', [
            'stats' => $stats,
            'topDepartements' => $topDepartements,
            'pipelineStats' => $pipelineStats,
        ]);
    }

    #[Route('/pdf', name: 'app_user_reporting_pdf')]
    public function pdf(Request $request, #[CurrentUser] ?User $user = null): Response
    {
        $stats = [
            'totalProspects' => $this->prospects->count([]),
            'prospectsContactes' => $this->prospects->countContacte(),
            'prospectsQualifies' => $this->prospects->countQualifies(),
            'actionsEnRetard' => $this->actions->countDue(),
            'actionsAvenir' => $this->actions->countUpcoming(),
            'generatedAt' => new \DateTime(),
            'generatedBy' => $user?->getUsername(),
        ];

        $topDepartements = $this->prospects->countByDepartement();
        $pipelineStats = [];
        foreach ($this->sprints->findAllOrdered() as $sprint) {
            $pipelineStats[] = [
                'sprint' => $sprint,
                'total' => $this->prospects->countBySprint((int) $sprint->getId()),
            ];
        }

        $html = $this->renderView('reporting/dashboard_pdf.html.twig', [
            'stats' => $stats,
            'topDepartements' => $topDepartements,
            'pipelineStats' => $pipelineStats,
        ]);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="reporting-' . date('Y-m-d') . '.pdf"',
            ]
        );
    }

    #[Route('/prospect/{id}/pdf', name: 'app_user_reporting_prospect_pdf')]
    public function prospectPdf(int $id): Response
    {
        $prospect = $this->prospects->find($id);
        if (!$prospect) {
            $this->addFlash('error', 'Prospect introuvable.');

            return $this->redirectToRoute('app_user_reporting');
        }

        $html = $this->renderView('reporting/prospect_pdf.html.twig', [
            'prospect' => $prospect,
            'generatedAt' => new \DateTime(),
        ]);

        $dompdf = new Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return new Response(
            $dompdf->output(),
            200,
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="prospect-' . $id . '-' . date('Y-m-d') . '.pdf"',
            ]
        );
    }
}
