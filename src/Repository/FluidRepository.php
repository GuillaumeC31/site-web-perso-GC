<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Fluid;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des fluides de référence de la calculatrice (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\FluidController (via AbstractCrudController), PositionManager, LoadReferenceDataCommand
 *
 * @extends AbstractSortableRepository<Fluid>
 */
class FluidRepository extends AbstractSortableRepository
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
        parent::__construct($registry, Fluid::class);
    }
}
