<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Redirections permanentes (301) des adresses publiques de l'ancien site (guillaume_c, v1) vers leur
 * équivalent v2, pour garder les liens et le référencement existants.
 *
 * Adresses inchangées (aucune redirection) : /, /mentions-legales, /calculatrice et ses outils (même
 * adresse ; outil fusionné : 301 ; outil pas encore publié : 302, voir CalculatorController).
 * Non reprises (404) : ancienne administration (/admin/…), appels techniques (/private/api/…,
 * /pro/api/…), actions rapides des anciens e-mails (/quick-action/…), pages de développement.
 *
 * Liens :
 * - Routes : legacy_* (voir self::REDIRECTS et projectShow())
 * - Testé par : tests/Controller/LegacyRedirectTest
 */
final class LegacyRedirectController extends AbstractController
{
    /**
     * Ancienne adresse => nom de la route v2 (paramètres éventuels).
     *
     * @var array<string, array{0: string, 1?: array<string, string>}>
     */
    public const REDIRECTS = [
        '/projects' => ['project_list'],
        '/pro' => ['recruiter_access'],
        '/pro/request-access' => ['recruiter_access'],
        '/pro/login' => ['recruiter_access'],
        '/pro/login-check' => ['recruiter_access'],
        '/pro/link-expired' => ['recruiter_link_invalid'],
        '/private' => ['recruiter_home'],
        '/private/profile' => ['recruiter_home'],
        '/private/cv/ats' => ['recruiter_cv_pdf', ['variant' => 'ats']],
        '/private/cv/design' => ['recruiter_cv_pdf', ['variant' => 'design']],
        '/private/cv/design/preview' => ['recruiter_cv_pdf', ['variant' => 'design']],
        '/politique-de-confidentialite' => ['legal_privacy'],
        '/maintenance' => ['home'],
        '/logout' => ['home'],
    ];

    /**
     * Redirige une ancienne adresse fixe vers son équivalent v2 (301).
     *
     * Liens :
     * - Routes : legacy_projects, legacy_pro, legacy_pro_request_access, … (une par ligne de self::REDIRECTS)
     */
    #[Route('/projects', name: 'legacy_projects', methods: ['GET'])]
    #[Route('/pro', name: 'legacy_pro', methods: ['GET'])]
    #[Route('/pro/request-access', name: 'legacy_pro_request_access', methods: ['GET'])]
    #[Route('/pro/login', name: 'legacy_pro_login', methods: ['GET'])]
    #[Route('/pro/login-check', name: 'legacy_pro_login_check', methods: ['GET'])]
    #[Route('/pro/link-expired', name: 'legacy_pro_link_expired', methods: ['GET'])]
    #[Route('/private', name: 'legacy_private', methods: ['GET'])]
    #[Route('/private/profile', name: 'legacy_private_profile', methods: ['GET'])]
    #[Route('/private/cv/ats', name: 'legacy_private_cv_ats', methods: ['GET'])]
    #[Route('/private/cv/design', name: 'legacy_private_cv_design', methods: ['GET'])]
    #[Route('/private/cv/design/preview', name: 'legacy_private_cv_design_preview', methods: ['GET'])]
    #[Route('/politique-de-confidentialite', name: 'legacy_privacy', methods: ['GET'])]
    #[Route('/maintenance', name: 'legacy_maintenance', methods: ['GET'])]
    #[Route('/logout', name: 'legacy_logout', methods: ['GET'])]
    public function redirectFixed(Request $request): RedirectResponse
    {
        $target = self::REDIRECTS[rtrim($request->getPathInfo(), '/') ?: '/'];

        return $this->redirectToRoute($target[0], $target[1] ?? [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * Redirige un ancien projet (/projects/{slug}) vers sa page v2 s'il est public, sinon vers la liste.
     *
     * Liens :
     * - Route : legacy_project_show (/projects/{slug})
     * - Utilise : ProjectRepository::findPublicBySlug()
     */
    #[Route('/projects/{slug}', name: 'legacy_project_show', requirements: ['slug' => '[a-z0-9-]+'], methods: ['GET'])]
    public function projectShow(string $slug, ProjectRepository $projectRepository): RedirectResponse
    {
        if (null !== $projectRepository->findPublicBySlug($slug)) {
            return $this->redirectToRoute('project_show', ['slug' => $slug], Response::HTTP_MOVED_PERMANENTLY);
        }

        return $this->redirectToRoute('project_list', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
