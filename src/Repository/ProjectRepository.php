<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Project;
use App\Enum\ProjectType;
use App\Enum\ProjectVisibility;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des projets (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Les requêtes publiques écartent en base les brouillons et les projets réservés aux
 * recruteurs, puis en PHP les projets incomplets (Project::isPubliclyVisible()).
 *
 * Liens :
 * - Utilisé par : Admin\ProjectController (via AbstractCrudController), PositionManager,
 *   ProjectController (pages publiques), HomeController (études de cas), ProjectImporter (slug libre)
 *
 * @extends AbstractSortableRepository<Project>
 */
class ProjectRepository extends AbstractSortableRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring)
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Project::class);
    }

    /**
     * Renvoie les projets visibles sur le site public : mis en avant d'abord, puis dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : ProjectController::index(), findHomeCaseStudies()
     *
     * @param ProjectType|null $type         type demandé, ou null pour tous
     * @param string|null      $categorySlug catégorie demandée, ou null pour toutes
     *
     * @return list<Project>
     */
    public function findPublic(?ProjectType $type = null, ?string $categorySlug = null): array
    {
        $queryBuilder = $this->createQueryBuilder('p')
            ->leftJoin('p.categories', 'c')
            ->addSelect('c')
            ->where('p.isPublished = true')
            ->andWhere('p.visibility = :visibility')
            ->setParameter('visibility', ProjectVisibility::Public->value)
            ->orderBy('p.isFeatured', 'DESC')
            ->addOrderBy('p.position', 'ASC')
            ->addOrderBy('p.id', 'ASC');

        if (null !== $type) {
            $queryBuilder->andWhere('p.type = :type')->setParameter('type', $type->value);
        }
        if (null !== $categorySlug) {
            // Sous-requête : le résultat garde toutes les catégories de chaque projet (badges).
            $queryBuilder->andWhere('p.id IN (SELECT p2.id FROM '.Project::class.' p2 JOIN p2.categories c2 WHERE c2.slug = :category)')
                ->setParameter('category', $categorySlug);
        }

        /** @var list<Project> $projects */
        $projects = $queryBuilder->getQuery()->getResult();

        return array_values(array_filter($projects, static fn (Project $project): bool => $project->isPubliclyVisible()));
    }

    /**
     * Renvoie les études de cas présentées sur l'accueil (mises en avant d'abord).
     *
     * Liens :
     * - Appelée par : HomeController::index()
     *
     * @param int $limit nombre maximal d'études de cas
     *
     * @return list<Project>
     */
    public function findHomeCaseStudies(int $limit): array
    {
        return \array_slice($this->findPublic(ProjectType::CaseStudy), 0, $limit);
    }

    /**
     * Retrouve un projet visible sur le site public par son identifiant d'URL.
     *
     * Liens :
     * - Appelée par : ProjectController::show()
     *
     * @param string $slug identifiant d'URL
     *
     * @return Project|null le projet, ou null s'il n'existe pas ou n'est pas visible
     */
    public function findPublicBySlug(string $slug): ?Project
    {
        $project = $this->findOneBy(['slug' => $slug]);

        return null !== $project && $project->isPubliclyVisible() ? $project : null;
    }

    /**
     * Renvoie les projets réservés aux recruteurs, publiés et complets (espace recruteur).
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::home(), Admin\RecruiterController::preview()
     *
     * @return list<Project>
     */
    public function findRecruiterOnly(): array
    {
        /** @var list<Project> $projects */
        $projects = $this->findBy(['isPublished' => true, 'visibility' => ProjectVisibility::Recruiter], ['isFeatured' => 'DESC', 'position' => 'ASC', 'id' => 'ASC']);

        return array_values(array_filter($projects, static fn (Project $project): bool => $project->isVisibleToRecruiters()));
    }

    /**
     * Retrouve un projet visible dans l'espace recruteur (public ou réservé), par son identifiant d'URL.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController::project()
     *
     * @return Project|null le projet, ou null s'il n'existe pas ou n'est pas visible
     */
    public function findRecruiterVisibleBySlug(string $slug): ?Project
    {
        $project = $this->findOneBy(['slug' => $slug]);

        return null !== $project && $project->isVisibleToRecruiters() ? $project : null;
    }

    /**
     * Indique si un identifiant d'URL est déjà pris (hors projet donné).
     *
     * Liens :
     * - Appelée par : ProjectSlugger::uniqueSlug()
     *
     * @param string   $slug      identifiant d'URL
     * @param int|null $excludeId projet à ignorer (celui qu'on modifie)
     */
    public function isSlugTaken(string $slug, ?int $excludeId = null): bool
    {
        $project = $this->findOneBy(['slug' => $slug]);

        return null !== $project && $project->getId() !== $excludeId;
    }
}
