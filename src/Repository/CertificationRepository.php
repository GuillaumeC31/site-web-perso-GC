<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Certification;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des certifications et habilitations (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\CertificationController (via AbstractCrudController), PositionManager
 *
 * @extends AbstractSortableRepository<Certification>
 */
class CertificationRepository extends AbstractSortableRepository
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
        parent::__construct($registry, Certification::class);
    }
}
