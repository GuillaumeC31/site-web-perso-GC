<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\ProjectType;
use App\Repository\ProjectCategoryRepository;
use App\Repository\ProjectRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages publiques des projets (CDC §6) : études de cas en premier, Labo en second,
 * onglets par type et filtre par catégorie (liens simples : fonctionne sans JavaScript).
 *
 * Seuls les projets publiés, visibles de tous et complets sont affichés
 * (ProjectRepository::findPublic(), Project::isPubliclyVisible()). Pas de compteur de projets.
 *
 * Liens :
 * - Routes : project_list (/projets), project_show (/projets/{slug})
 * - Utilise : ProjectRepository, ProjectCategoryRepository
 * - Rend : templates/project/index.html.twig, templates/project/show.html.twig
 */
final class ProjectController extends AbstractController
{
    /**
     * Affiche la liste des projets, éventuellement filtrée.
     *
     * Paramètres d'URL : type (« etudes-de-cas » ou « labo »), categorie (slug).
     * Une valeur inconnue est ignorée (liste complète). Un onglet de type n'est proposé que si ce type a au
     * moins un projet visible (ex. études de cas encore en brouillon : ni onglet, ni titre de section vide).
     *
     * Liens :
     * - Route : project_list (/projets)
     * - Utilise : ProjectRepository::findPublic(), ProjectCategoryRepository::findOneBySlug()
     * - Rend : templates/project/index.html.twig
     *
     * @param Request                   $request            requête (filtres)
     * @param ProjectRepository         $projectRepository  projets visibles
     * @param ProjectCategoryRepository $categoryRepository catégories du filtre
     *
     * @return Response la page HTML
     */
    #[Route('/projets', name: 'project_list', methods: ['GET'])]
    public function index(Request $request, ProjectRepository $projectRepository, ProjectCategoryRepository $categoryRepository): Response
    {
        $type = ProjectType::fromUrlValue($request->query->getString('type'));
        $category = $categoryRepository->findOneBySlug($request->query->getString('categorie'));

        // Catégories proposées : celles des projets visibles du type choisi (jamais de filtre vide).
        $categories = [];
        foreach ($projectRepository->findPublic($type) as $project) {
            foreach ($project->getCategories() as $projectCategory) {
                $categories[$projectCategory->getId()] = $projectCategory;
            }
        }
        usort($categories, static fn ($a, $b): int => [$a->getPosition(), $a->getId()] <=> [$b->getPosition(), $b->getId()]);

        $projects = $projectRepository->findPublic($type, $category?->getSlug());

        // Onglets : seulement les types qui ont au moins un projet visible (jamais d'onglet vers une liste vide)
        $visibleTypes = array_map(static fn ($project): ProjectType => $project->getType(), $projectRepository->findPublic());
        $types = array_values(array_filter(ProjectType::cases(), static fn (ProjectType $case): bool => \in_array($case, $visibleTypes, true)));

        return $this->render('project/index.html.twig', [
            'current_type' => $type,
            'current_category' => $category,
            'categories' => $categories,
            'case_studies' => array_values(array_filter($projects, static fn ($project): bool => ProjectType::CaseStudy === $project->getType())),
            'lab_projects' => array_values(array_filter($projects, static fn ($project): bool => ProjectType::Lab === $project->getType())),
            'types' => $types,
        ]);
    }

    /**
     * Affiche le détail d'un projet visible.
     *
     * Liens :
     * - Route : project_show (/projets/{slug})
     * - Utilise : ProjectRepository::findPublicBySlug()
     * - Rend : templates/project/show.html.twig
     *
     * @param string            $slug              identifiant d'URL
     * @param ProjectRepository $projectRepository projets visibles
     *
     * @return Response la page HTML
     *
     * @throws NotFoundHttpException si le projet n'existe pas ou n'est pas visible (brouillon, recruteurs, incomplet)
     */
    #[Route('/projets/{slug}', name: 'project_show', requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'], methods: ['GET'])]
    public function show(string $slug, ProjectRepository $projectRepository): Response
    {
        $project = $projectRepository->findPublicBySlug($slug) ?? throw $this->createNotFoundException();

        return $this->render('project/show.html.twig', [
            'project' => $project,
            'is_preview' => false,
        ]);
    }
}
