<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\KeyFigure;
use App\Entity\SortableInterface;
use App\Form\KeyFigureType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des chiffres clés de l'accueil dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_key_figure_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/chiffres-cles)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : KeyFigure, KeyFigureType
 *
 * @extends AbstractCrudController<KeyFigure>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/chiffres-cles', name: 'key_figure_')]
final class KeyFigureController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return KeyFigure::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new KeyFigure();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return KeyFigureType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_key_figure';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.key_figure';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.key_figure.field.value', 'field' => 'value'],
            ['label' => 'admin.key_figure.field.label', 'field' => 'label'],
        ];
    }
}
