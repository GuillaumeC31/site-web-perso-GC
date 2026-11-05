<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\SecurityEventRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Journal des connexions à l'administration (lecture seule, 50 lignes par page).
 *
 * Les lignes de plus de 90 jours sont supprimées chaque jour par app:security-event:purge.
 *
 * Liens :
 * - Rend : templates/admin/security_event/index.html.twig
 * - Utilise : SecurityEventRepository
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/securite/journal', name: 'security_event_')]
final class SecurityEventController extends AbstractController
{
    /**
     * Affiche une page du journal, du plus récent au plus ancien.
     *
     * Liens :
     * - Route : admin_security_event_index (/{ADMIN_PATH}/securite/journal?page=N)
     * - Rend : admin/security_event/index.html.twig
     *
     * @param SecurityEventRepository $securityEventRepository dépôt du journal
     * @param int                     $page                    numéro de page (1 par défaut)
     *
     * @return Response la page HTML
     */
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(SecurityEventRepository $securityEventRepository, #[MapQueryParameter] int $page = 1): Response
    {
        $total = $securityEventRepository->countAll();
        $pageCount = max(1, (int) ceil($total / SecurityEventRepository::PAGE_SIZE));
        $page = min(max(1, $page), $pageCount);

        return $this->render('admin/security_event/index.html.twig', [
            'events' => $securityEventRepository->findPage($page),
            'page' => $page,
            'page_count' => $pageCount,
            'total' => $total,
        ]);
    }
}
