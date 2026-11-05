<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ClimaticProfile;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des profils de consigne de l'enceinte climatique (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\ClimaticProfileController (via AbstractCrudController), PositionManager, LoadReferenceDataCommand, CalculatorController
 *
 * @extends AbstractSortableRepository<ClimaticProfile>
 */
class ClimaticProfileRepository extends AbstractSortableRepository
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
        parent::__construct($registry, ClimaticProfile::class);
    }
}
