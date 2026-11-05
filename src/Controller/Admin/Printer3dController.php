<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Printer3d;
use App\Entity\SortableInterface;
use App\Form\Printer3dType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des imprimantes 3D de référence de la calculatrice dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Valeurs de départ : app:calculator:load-reference-data (config/calculator/reference_data.yaml).
 *
 * Liens :
 * - Routes : admin_printer3d_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/calculatrice/imprimantes-3d)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Printer3d, Printer3dType
 *
 * @extends AbstractCrudController<Printer3d>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/imprimantes-3d', name: 'printer3d_')]
final class Printer3dController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Printer3d::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Printer3d();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return Printer3dType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_printer3d';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.printer3d';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.printer3d.field.brand', 'field' => 'brand'],
            ['label' => 'admin.printer3d.field.model', 'field' => 'model'],
            ['label' => 'admin.printer3d.field.power_consumption', 'field' => 'powerConsumption'],
        ];
    }
}
