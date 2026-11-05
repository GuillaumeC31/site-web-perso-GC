<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Fluid;
use App\Entity\SortableInterface;
use App\Form\FluidType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des fluides de référence de la calculatrice dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Valeurs de départ : app:calculator:load-reference-data (config/calculator/reference_data.yaml).
 *
 * Liens :
 * - Routes : admin_fluid_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/calculatrice/fluides)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Fluid, FluidType
 *
 * @extends AbstractCrudController<Fluid>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/fluides', name: 'fluid_')]
final class FluidController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Fluid::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Fluid();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return FluidType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_fluid';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.fluid';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.fluid.field.name', 'field' => 'name'],
            ['label' => 'admin.fluid.field.state', 'field' => 'state', 'type' => 'enum'],
            ['label' => 'admin.fluid.field.density', 'field' => 'density'],
            ['label' => 'admin.fluid.field.specific_heat_capacity', 'field' => 'specificHeatCapacity'],
        ];
    }
}
