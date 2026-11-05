<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Experience;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des expériences (ordre d'affichage géré par AbstractSortableRepository).
 *
 * Liens :
 * - Utilisé par : Admin\ExperienceController (via AbstractCrudController), PositionManager
 *
 * @extends AbstractSortableRepository<Experience>
 */
class ExperienceRepository extends AbstractSortableRepository
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
        parent::__construct($registry, Experience::class);
    }

    /**
     * Renvoie les expériences publiées, dans l'ordre d'affichage, avec leurs compétences.
     *
     * Liens :
     * - Appelée par : CvContentProvider::getContent()
     *
     * @return list<Experience>
     */
    public function findPublishedWithSkills(): array
    {
        /** @var list<Experience> $experiences */
        $experiences = $this->createQueryBuilder('e')
            ->leftJoin('e.skills', 's')
            ->addSelect('s')
            ->where('e.isPublished = true')
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $experiences;
    }
}
