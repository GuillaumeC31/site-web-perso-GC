<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\KeyFigure;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des chiffres clés (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\KeyFigureController (via AbstractCrudController), PositionManager
 *
 * @extends AbstractSortableRepository<KeyFigure>
 */
class KeyFigureRepository extends AbstractSortableRepository
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
        parent::__construct($registry, KeyFigure::class);
    }
}
