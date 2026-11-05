<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Refrigerant;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des fluides frigorigènes de référence de la calculatrice (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\RefrigerantController (via AbstractCrudController), PositionManager, LoadReferenceDataCommand, CalculatorController
 *
 * @extends AbstractSortableRepository<Refrigerant>
 */
class RefrigerantRepository extends AbstractSortableRepository
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
        parent::__construct($registry, Refrigerant::class);
    }
}
