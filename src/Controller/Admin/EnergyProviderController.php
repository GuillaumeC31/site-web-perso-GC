<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\EnergyProvider;
use App\Entity\SortableInterface;
use App\Form\EnergyProviderType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des offres d'électricité de référence de la calculatrice dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Valeurs de départ : app:calculator:load-reference-data (config/calculator/reference_data.yaml).
 *
 * Liens :
 * - Routes : admin_energy_provider_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/calculatrice/offres-electricite)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : EnergyProvider, EnergyProviderType
 *
 * @extends AbstractCrudController<EnergyProvider>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/offres-electricite', name: 'energy_provider_')]
final class EnergyProviderController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return EnergyProvider::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new EnergyProvider();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return EnergyProviderType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_energy_provider';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.energy_provider';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.energy_provider.field.name', 'field' => 'name'],
            ['label' => 'admin.energy_provider.field.plan_name', 'field' => 'planName'],
            ['label' => 'admin.energy_provider.field.price_kwh', 'field' => 'priceKwh'],
        ];
    }
}
