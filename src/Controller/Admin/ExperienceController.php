<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Experience;
use App\Entity\SortableInterface;
use App\Form\ExperienceType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des expériences professionnelles dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_experience_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/experiences)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Experience, ExperienceType
 *
 * @extends AbstractCrudController<Experience>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/experiences', name: 'experience_')]
final class ExperienceController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Experience::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Experience();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return ExperienceType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_experience';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.experience';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.experience.field.career_section', 'field' => 'careerSection', 'type' => 'enum'],
            ['label' => 'admin.experience.field.job_title', 'field' => 'jobTitle'],
            ['label' => 'admin.experience.field.company_name', 'field' => 'companyName'],
            ['label' => 'admin.experience.field.is_company_anonymized', 'field' => 'isCompanyAnonymized', 'type' => 'bool'],
            ['label' => 'admin.experience.field.start_date', 'field' => 'startDate', 'type' => 'date_month'],
            ['label' => 'admin.experience.field.end_date', 'field' => 'endDate', 'type' => 'date_month'],
            ['label' => 'admin.experience.field.is_published', 'field' => 'isPublished', 'type' => 'bool'],
            ['label' => 'admin.experience.column.achievements', 'field' => 'missingAchievements', 'type' => 'warning'],
        ];
    }
}
