<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Skill;
use App\Entity\SortableInterface;
use App\Form\SkillType;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gestion des compétences dans l'admin (liste, ajout, modification, suppression, ordre).
 *
 * Liens :
 * - Routes : admin_skill_index, _new, _edit, _delete, _move (/{ADMIN_PATH}/competences)
 * - Hérite de : AbstractCrudController (actions et templates communs)
 * - Utilise : Skill, SkillType
 *
 * @extends AbstractCrudController<Skill>
 */
#[IsGranted('ADMIN_KEY_VERIFIED')]
#[Route('/competences', name: 'skill_')]
final class SkillController extends AbstractCrudController
{
    /** Renvoie la classe de l'entité gérée. */
    protected function getEntityClass(): string
    {
        return Skill::class;
    }

    /** Crée une entité vide pour le formulaire d'ajout. */
    protected function createEntity(): SortableInterface
    {
        return new Skill();
    }

    /** Renvoie la classe du formulaire. */
    protected function getFormType(): string
    {
        return SkillType::class;
    }

    /** Renvoie le préfixe des noms de routes. */
    protected function getRoutePrefix(): string
    {
        return 'admin_skill';
    }

    /** Renvoie le préfixe des clés de traduction. */
    protected function getTranslationPrefix(): string
    {
        return 'admin.skill';
    }

    /** Décrit les colonnes de la liste. */
    protected function getColumns(): array
    {
        return [
            ['label' => 'admin.skill.field.name', 'field' => 'name'],
            ['label' => 'admin.skill.field.pillar', 'field' => 'pillar.name'],
            ['label' => 'admin.skill.column.experiences', 'field' => 'experiences', 'type' => 'count'],
        ];
    }
}
