<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProjectRepository;
use App\Service\Calculator\CalculatorRegistry;
use App\Service\Seo\SeoContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Plan du site (sitemap.xml) et consignes aux robots (robots.txt), générés à la demande.
 *
 * - sitemap.xml : pages publiques, projets publiés et complets, outils de calcul publics ; jamais l'espace
 *   privé des recruteurs ni l'admin (son adresse reste secrète).
 * - robots.txt : exclut l'espace privé ; sur un environnement de test (SITE_NOINDEX=1), interdit tout.
 *
 * Liens :
 * - Routes : sitemap (/sitemap.xml), robots (/robots.txt)
 * - Utilise : ProjectRepository::findPublic(), CalculatorRegistry::getPublicCategories(), SeoContext
 * - Testé par : tests/Controller/SeoTest
 */
final class SitemapController extends AbstractController
{
    /** Pages fixes du plan du site (nom de route). */
    private const STATIC_ROUTES = ['home', 'cv_show', 'project_list', 'calculator_dashboard', 'contact_show', 'recruiter_access', 'legal_notice', 'legal_privacy'];

    /** Parties de l'espace recruteur fermées aux robots (pages privées, liens de connexion). */
    private const DISALLOWED_PATHS = [
        '/espace-recruteur/accueil',
        '/espace-recruteur/connexion/',
        '/espace-recruteur/lien-invalide',
        '/espace-recruteur/prolongation/',
        '/espace-recruteur/cv/',
        '/espace-recruteur/projets/',
        '/espace-recruteur/assistant/',
    ];

    /**
     * Reçoit le service de référencement.
     */
    public function __construct(private readonly SeoContext $seoContext)
    {
    }

    /**
     * Renvoie le plan du site au format XML.
     *
     * Liens :
     * - Route : sitemap (/sitemap.xml)
     * - Rend : templates/seo/sitemap.xml.twig
     */
    #[Route('/sitemap.xml', name: 'sitemap', methods: ['GET'], format: 'xml')]
    public function sitemap(ProjectRepository $projectRepository, CalculatorRegistry $registry): Response
    {
        $paths = array_map(fn (string $route): string => $this->generateUrl($route, [], UrlGeneratorInterface::ABSOLUTE_PATH), self::STATIC_ROUTES);
        foreach ($projectRepository->findPublic() as $project) {
            $paths[] = $this->generateUrl('project_show', ['slug' => $project->getSlug()]);
        }
        foreach ($registry->getPublicCategories() as $category) {
            $paths[] = $this->generateUrl('calculator_category', ['category' => $category->slug]);
            foreach ($category->getPublicTools() as $tool) {
                $paths[] = $this->generateUrl('calculator_tool', ['category' => $category->slug, 'tool' => $tool->slug]);
            }
        }

        $response = $this->render('seo/sitemap.xml.twig', [
            'urls' => array_map($this->seoContext->absoluteUrl(...), array_values(array_unique($paths))),
        ]);
        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return $response;
    }

    /**
     * Renvoie les consignes aux robots.
     *
     * Liens :
     * - Route : robots (/robots.txt)
     */
    #[Route('/robots.txt', name: 'robots', methods: ['GET'], format: 'txt')]
    public function robots(): Response
    {
        $lines = ['User-agent: *'];
        if ($this->seoContext->isSiteNoIndex()) {
            $lines[] = 'Disallow: /';
        } else {
            foreach (self::DISALLOWED_PATHS as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = '';
            $lines[] = 'Sitemap: '.$this->seoContext->absoluteUrl('/sitemap.xml');
        }

        return new Response(implode("\n", $lines)."\n", Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
