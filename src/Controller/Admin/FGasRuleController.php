<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\FGasRule;
use App\Entity\SortableInterface;
use App\Form\FGasRuleType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des règles F-Gas de la chambre froide (affichées sur le site une fois vérifiées) dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Valeurs de départ : app:calculator:load-reference-data (config/calculator/fgas_rules.json).
 *
 * Liens :
 * - Routes : admin_fgas_rule_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/calculatrice/regles-fgas)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : FGasRule, FGasRuleType
 *
 * @extends AbstractCrudController<FGasRule>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/calculatrice/regles-fgas', name: 'fgas_rule_')]
final class FGasRuleController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return FGasRule::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new FGasRule();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return FGasRuleType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_fgas_rule';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.fgas_rule';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.fgas_rule.field.code', 'field' => 'code'],
            ['label' => 'admin.fgas_rule.field.kind', 'field' => 'kindLabel'],
            ['label' => 'admin.fgas_rule.field.equipment', 'field' => 'equipment'],
            ['label' => 'admin.fgas_rule.field.gwp_threshold', 'field' => 'gwpThreshold'],
            ['label' => 'admin.fgas_rule.field.since', 'field' => 'since', 'type' => 'date'],
            ['label' => 'admin.fgas_rule.field.verified', 'field' => 'verified', 'type' => 'bool'],
        ];
    }
}
