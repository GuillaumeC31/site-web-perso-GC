<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Refrigerant;
use App\Entity\SortableInterface;
use App\Form\RefrigerantType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des fluides frigorigènes de référence de la calculatrice (outils « Fluides frigorigènes » et « Chambre froide ») dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Valeurs de départ : app:calculator:load-reference-data (config/calculator/refrigerants.json).
 *
 * Liens :
 * - Routes : admin_refrigerant_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/calculatrice/fluides-frigorigenes)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Refrigerant, RefrigerantType
 *
 * @extends AbstractCrudController<Refrigerant>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/fluides-frigorigenes', name: 'refrigerant_')]
final class RefrigerantController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Refrigerant::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Refrigerant();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return RefrigerantType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_refrigerant';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.refrigerant';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.refrigerant.field.name', 'field' => 'name'],
            ['label' => 'admin.refrigerant.field.family', 'field' => 'family'],
            ['label' => 'admin.refrigerant.field.gwp', 'field' => 'gwp'],
            ['label' => 'admin.refrigerant.field.safety_class', 'field' => 'safetyClass'],
            ['label' => 'admin.refrigerant.field.default_choice', 'field' => 'defaultChoice', 'type' => 'bool'],
            ['label' => 'admin.refrigerant.field.verified', 'field' => 'verified', 'type' => 'bool'],
        ];
    }
}
