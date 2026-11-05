<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PanelModel;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des modèles de panneaux isolants de marques de la calculatrice (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\PanelModelController (via AbstractCrudController), PositionManager, CalculatorController
 *
 * @extends AbstractSortableRepository<PanelModel>
 */
class PanelModelRepository extends AbstractSortableRepository
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
        parent::__construct($registry, PanelModel::class);
    }
}
