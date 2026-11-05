<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ProjectCategory;
use App\Entity\SortableInterface;
use App\Form\ProjectCategoryType;
use App\Repository\ProjectCategoryRepository;
use App\Service\Admin\PositionManager;
use App\Service\Content\SlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des catégories de projets dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Supprimer une catégorie la retire simplement des projets qui l'utilisaient.
 *
 * Liens :
 * - Routes : admin_project_category_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/categories-de-projets)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : ProjectCategory, ProjectCategoryType, SlugGenerator
 *
 * @extends AbstractCrudController<ProjectCategory>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/categories-de-projets', name: 'project_category_')]
final class ProjectCategoryController extends AbstractCrudController
{
    /**
     * Reçoit les dépendances.
     *
     * @param EntityManagerInterface    $entityManager   gestionnaire Doctrine
     * @param PositionManager           $positionManager calcul de l'ordre d'affichage
     * @param SlugGenerator             $slugGenerator   calcul du slug laissé vide
     * @param ProjectCategoryRepository $repository      recherche des slugs déjà pris
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        PositionManager $positionManager,
        private readonly SlugGenerator $slugGenerator,
        private readonly ProjectCategoryRepository $repository,
    ) {
        parent::__construct($entityManager, $positionManager);
    }

    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return ProjectCategory::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new ProjectCategory();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return ProjectCategoryType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_project_category';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.project_category';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.project_category.field.name', 'field' => 'name'],
            ['label' => 'admin.project.field.slug', 'field' => 'slug'],
        ];
    }

    /**
     * Calcule le slug à partir du nom s'il est laissé vide.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::new(), ::edit()
     * - Utilise : SlugGenerator::unique()
     *
     * @param ProjectCategory      $entity catégorie en cours d'enregistrement
     * @param FormInterface<mixed> $form   formulaire soumis et valide
     */
    protected function beforeSave(SortableInterface $entity, FormInterface $form): bool
    {
        if ('' === $entity->getSlug()) {
            $entity->setSlug($this->slugGenerator->unique($entity->getName(), function (string $slug) use ($entity): bool {
                $existing = $this->repository->findOneBySlug($slug);

                return null !== $existing && $existing !== $entity;
            }));
        }

        return true;
    }
}
