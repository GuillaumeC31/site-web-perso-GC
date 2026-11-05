<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\PanelModel;
use App\Entity\SortableInterface;
use App\Form\PanelModelType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des modèles de panneaux isolants des marques (chambre froide) dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Aucun modèle n'est fourni au départ : chaque modèle est saisi depuis la fiche technique de la marque.
 *
 * Liens :
 * - Routes : admin_panel_model_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/calculatrice/panneaux)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : PanelModel, PanelModelType
 *
 * @extends AbstractCrudController<PanelModel>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/panneaux', name: 'panel_model_')]
final class PanelModelController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return PanelModel::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new PanelModel();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return PanelModelType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_panel_model';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.panel_model';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.panel_model.field.brand', 'field' => 'brand'],
            ['label' => 'admin.panel_model.field.product_range', 'field' => 'productRange'],
            ['label' => 'admin.panel_model.field.thickness', 'field' => 'thickness'],
            ['label' => 'admin.panel_model.field.effective_u', 'field' => 'effectiveU'],
            ['label' => 'admin.panel_model.field.source_date', 'field' => 'sourceDate', 'type' => 'date'],
        ];
    }
}
