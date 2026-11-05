<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\CvEntry;
use App\Entity\SortableInterface;
use App\Form\CvEntryType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des rubriques « liste » du CV dans l'admin : outils techniques, expertises métiers,
 * logiciels, savoir-être, centres d'intérêt (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_cv_entry_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/rubriques-cv)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : CvEntry, CvEntryType, CvEntryRepository (ordre par rubrique, flèches dans la rubrique)
 *
 * @extends AbstractCrudController<CvEntry>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/rubriques-cv', name: 'cv_entry_')]
final class CvEntryController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return CvEntry::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new CvEntry();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return CvEntryType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_cv_entry';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.cv_entry';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.cv_entry.field.section', 'field' => 'section', 'type' => 'enum'],
            ['label' => 'admin.cv_entry.field.title', 'field' => 'title'],
            ['label' => 'admin.cv_entry.field.content', 'field' => 'content'],
        ];
    }
}
