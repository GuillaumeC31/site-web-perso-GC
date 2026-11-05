<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ClimaticProfile;
use App\Entity\SortableInterface;
use App\Form\ClimaticProfileType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des profils de consigne de l'enceinte climatique dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Valeurs de départ : app:calculator:load-reference-data (config/calculator/climatic_profiles.json).
 *
 * Liens :
 * - Routes : admin_climatic_profile_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/calculatrice/profils-climatiques)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : ClimaticProfile, ClimaticProfileType
 *
 * @extends AbstractCrudController<ClimaticProfile>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/profils-climatiques', name: 'climatic_profile_')]
final class ClimaticProfileController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return ClimaticProfile::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new ClimaticProfile();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return ClimaticProfileType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_climatic_profile';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.climatic_profile';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.climatic_profile.field.sector', 'field' => 'sector'],
            ['label' => 'admin.climatic_profile.field.name', 'field' => 'name'],
            ['label' => 'admin.climatic_profile.field.temperature', 'field' => 'temperature'],
            ['label' => 'admin.climatic_profile.field.humidity', 'field' => 'humidity'],
            ['label' => 'admin.climatic_profile.field.default_choice', 'field' => 'defaultChoice', 'type' => 'bool'],
            ['label' => 'admin.climatic_profile.field.verified', 'field' => 'verified', 'type' => 'bool'],
        ];
    }
}
