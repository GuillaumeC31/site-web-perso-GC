<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EnergyProvider;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des offres d'électricité de référence de la calculatrice (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\EnergyProviderController (via AbstractCrudController), PositionManager, LoadReferenceDataCommand
 *
 * @extends AbstractSortableRepository<EnergyProvider>
 */
class EnergyProviderRepository extends AbstractSortableRepository
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
        parent::__construct($registry, EnergyProvider::class);
    }
}
