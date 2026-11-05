<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Printer3d;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des imprimantes 3D de référence de la calculatrice (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\Printer3dController (via AbstractCrudController), PositionManager, LoadReferenceDataCommand
 *
 * @extends AbstractSortableRepository<Printer3d>
 */
class Printer3dRepository extends AbstractSortableRepository
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
        parent::__construct($registry, Printer3d::class);
    }
}
