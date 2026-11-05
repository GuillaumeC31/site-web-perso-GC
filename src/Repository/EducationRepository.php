<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Education;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des formations (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\EducationController (via AbstractCrudController), PositionManager
 *
 * @extends AbstractSortableRepository<Education>
 */
class EducationRepository extends AbstractSortableRepository
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
        parent::__construct($registry, Education::class);
    }
}
