<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RecruitmentArgument;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des arguments « Pourquoi me recruter » (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\RecruitmentArgumentController (via AbstractCrudController), Service\Content\RecruitmentArguments
 *
 * @extends AbstractSortableRepository<RecruitmentArgument>
 */
class RecruitmentArgumentRepository extends AbstractSortableRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecruitmentArgument::class);
    }

    /**
     * Renvoie les arguments marqués « Afficher », dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : Service\Content\RecruitmentArguments::getDisplayed(), getVisible()
     *
     * @return list<RecruitmentArgument>
     */
    public function findVisibleOrdered(): array
    {
        /** @var list<RecruitmentArgument> $arguments */
        $arguments = $this->createQueryBuilder('a')
            ->andWhere('a.visible = true')
            ->orderBy('a.position', 'ASC')
            ->addOrderBy('a.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $arguments;
    }
}
