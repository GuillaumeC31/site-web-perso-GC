<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Admin\ChangelogRenderer;
use App\Service\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Journal des versions dans l'admin, en lecture seule (contenu de CHANGELOG.md). Il n'y a pas de module
 * de saisie : le journal se tient dans le dépôt, à chaque tag (écart volontaire au CDC, décision du
 * 24/10/2026).
 *
 * Liens :
 * - Route : admin_changelog (/{ADMIN_PATH}/journal-des-versions) ; lien depuis la version du pied de page
 * - Rend : templates/admin/changelog/index.html.twig
 * - Utilise : ChangelogRenderer, AppVersion
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
final class ChangelogController extends AbstractController
{
    /**
     * Affiche le journal des versions.
     *
     * Liens :
     * - Route : admin_changelog (/{ADMIN_PATH}/journal-des-versions)
     * - Rend : templates/admin/changelog/index.html.twig
     */
    #[Route('/journal-des-versions', name: 'changelog', methods: ['GET'])]
    public function index(ChangelogRenderer $renderer, AppVersion $appVersion): Response
    {
        return $this->render('admin/changelog/index.html.twig', [
            'changelog' => $renderer->renderChangelog(),
            'app_version' => $appVersion->get(),
        ]);
    }
}
