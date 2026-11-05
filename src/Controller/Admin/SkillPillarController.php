<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\SkillPillar;
use App\Entity\SortableInterface;
use App\Form\SkillPillarType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des piliers de compétences dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_pillar_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/piliers)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : SkillPillar, SkillPillarType
 *
 * @extends AbstractCrudController<SkillPillar>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/piliers', name: 'pillar_')]
final class SkillPillarController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return SkillPillar::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new SkillPillar();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return SkillPillarType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_pillar';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.skill_pillar';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.skill_pillar.field.name', 'field' => 'name'],
            ['label' => 'admin.skill_pillar.column.skills', 'field' => 'skills', 'type' => 'count'],
        ];
    }

    /**
     * Refuse de supprimer un pilier qui contient encore des compétences.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::delete()
     *
     * @param SkillPillar $entity pilier à supprimer
     */
    protected function canDelete(SortableInterface $entity): bool
    {
        return $entity->getSkills()->isEmpty();
    }
}
