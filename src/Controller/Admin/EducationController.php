<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Education;
use App\Entity\SortableInterface;
use App\Form\EducationType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des formations dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_education_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/formations)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Education, EducationType
 *
 * @extends AbstractCrudController<Education>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/formations', name: 'education_')]
final class EducationController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Education::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Education();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return EducationType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_education';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.education';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.education.field.year', 'field' => 'year'],
            ['label' => 'admin.education.field.title', 'field' => 'title'],
            ['label' => 'admin.education.field.institution', 'field' => 'institution'],
            ['label' => 'admin.education.field.location', 'field' => 'location'],
        ];
    }
}
