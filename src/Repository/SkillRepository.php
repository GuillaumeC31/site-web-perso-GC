<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Skill;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des compétences (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\SkillController (via AbstractCrudController), PositionManager
 *
 * @extends AbstractSortableRepository<Skill>
 */
class SkillRepository extends AbstractSortableRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring)
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Skill::class);
    }
}
