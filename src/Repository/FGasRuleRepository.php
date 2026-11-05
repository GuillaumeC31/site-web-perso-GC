<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FGasRule;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des règles F-Gas (interdictions de mise sur le marché) de la calculatrice (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\FGasRuleController (via AbstractCrudController), PositionManager, LoadReferenceDataCommand, CalculatorController
 *
 * @extends AbstractSortableRepository<FGasRule>
 */
class FGasRuleRepository extends AbstractSortableRepository
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
        parent::__construct($registry, FGasRule::class);
    }

    /**
     * Renvoie les règles vérifiées dans l'admin (les seules affichées sur le site), dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : CalculatorController::toolData() (chambre froide)
     *
     * @return list<FGasRule>
     */
    public function findVerifiedOrdered(): array
    {
        /** @var list<FGasRule> $result */
        $result = $this->createQueryBuilder('e')
            ->andWhere('e.verified = true')
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }
}
