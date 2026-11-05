<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SkillPillar;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des piliers de compétences (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\SkillPillarController (via AbstractCrudController), PositionManager
 *
 * @extends AbstractSortableRepository<SkillPillar>
 */
class SkillPillarRepository extends AbstractSortableRepository
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
        parent::__construct($registry, SkillPillar::class);
    }

    /**
     * Renvoie les piliers avec leurs compétences (triées), dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : CvContentProvider::getContent()
     *
     * @return list<SkillPillar>
     */
    public function findAllWithSkills(): array
    {
        /** @var list<SkillPillar> $pillars */
        $pillars = $this->createQueryBuilder('p')
            ->leftJoin('p.skills', 's')
            ->addSelect('s')
            ->orderBy('p.position', 'ASC')
            ->addOrderBy('s.position', 'ASC')
            ->getQuery()
            ->getResult();

        return $pillars;
    }
}
