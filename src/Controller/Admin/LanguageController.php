<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Language;
use App\Entity\SortableInterface;
use App\Form\LanguageType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des langues dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_language_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/langues)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Language, LanguageType
 *
 * @extends AbstractCrudController<Language>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/langues', name: 'language_')]
final class LanguageController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Language::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Language();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return LanguageType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_language';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.language';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.language.field.name', 'field' => 'name'],
            ['label' => 'admin.language.field.proficiency', 'field' => 'proficiency'],
        ];
    }
}
