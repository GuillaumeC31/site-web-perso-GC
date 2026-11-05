<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProjectCategory;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des catégories de projets (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\ProjectCategoryController (via AbstractCrudController), PositionManager,
 *   ProjectController (filtre), ProjectImporter (catégories des archives)
 *
 * @extends AbstractSortableRepository<ProjectCategory>
 */
class ProjectCategoryRepository extends AbstractSortableRepository
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
        parent::__construct($registry, ProjectCategory::class);
    }

    /**
     * Retrouve une catégorie par son identifiant d'URL.
     *
     * Liens :
     * - Appelée par : ProjectController::index(), ProjectImporter
     *
     * @param string $slug identifiant d'URL
     */
    public function findOneBySlug(string $slug): ?ProjectCategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
