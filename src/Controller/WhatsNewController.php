<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Admin\ChangelogRenderer;
use App\Service\AppVersion;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique « Nouveautés » : résumé des versions du site en langage courant (NOUVEAUTES.md), ouvert depuis
 * le numéro de version du pied de page. Aucun lien dans le menu principal. Le CHANGELOG, technique (fichiers,
 * variables, commandes), reste réservé à l'admin.
 *
 * Liens :
 * - Route : whats_new (/nouveautes) ; lien du pied de page (templates/_partials/_footer.html.twig)
 * - Rend : templates/whats_new/index.html.twig
 * - Utilise : ChangelogRenderer::renderWhatsNew() (même rendu sécurisé que le journal de l'admin), AppVersion
 * - Testé par : tests/Controller/WhatsNewTest, tests/Security/WhatsNewContentTest
 */
final class WhatsNewController extends AbstractController
{
    /**
     * Affiche les nouveautés du site.
     *
     * Liens :
     * - Route : whats_new (/nouveautes)
     * - Rend : templates/whats_new/index.html.twig
     */
    #[Route('/nouveautes', name: 'whats_new', methods: ['GET'])]
    public function index(ChangelogRenderer $renderer, AppVersion $appVersion): Response
    {
        return $this->render('whats_new/index.html.twig', [
            'content' => $renderer->renderWhatsNew(),
            'public_version' => $appVersion->getPublic(),
        ]);
    }
}
