<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SortableInterface;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;

/**
 * Requêtes communes aux entités dont l'ordre se règle dans l'admin.
 *
 * Liens :
 * - Étendue par : KeyFigureRepository, ExperienceRepository, SkillPillarRepository,
 *   SkillRepository, EducationRepository, CertificationRepository, LanguageRepository
 * - Utilisée par : PositionManager, AbstractCrudController
 *
 * @template T of SortableInterface
 *
 * @extends ServiceEntityRepository<T>
 */
abstract class AbstractSortableRepository extends ServiceEntityRepository
{
    /**
     * Renvoie toutes les lignes dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::index()
     *
     * @return list<T>
     */
    public function findAllOrdered(): array
    {
        /** @var list<T> $result */
        $result = $this->createQueryBuilder('e')
            ->orderBy('e.position', 'ASC')
            ->addOrderBy('e.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $result;
    }

    /**
     * Renvoie la plus grande position utilisée (0 si la table est vide).
     *
     * Liens :
     * - Appelée par : PositionManager::placeLast()
     */
    public function findMaxPosition(): int
    {
        $max = $this->createQueryBuilder('e')
            ->select('MAX(e.position)')
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $max;
    }

    /**
     * Trouve la ligne voisine (juste avant ou juste après) dans l'ordre d'affichage.
     *
     * Liens :
     * - Appelée par : PositionManager::move()
     *
     * @param SortableInterface $entity ligne de référence
     * @param bool              $before vrai pour la voisine précédente, faux pour la suivante
     *
     * @return T|null la voisine, ou null si la ligne est déjà en tête (ou en fin)
     */
    public function findNeighbor(SortableInterface $entity, bool $before): ?SortableInterface
    {
        /** @var T|null $neighbor */
        $neighbor = $this->createQueryBuilder('e')
            ->where($before ? 'e.position < :position' : 'e.position > :position')
            ->setParameter('position', $entity->getPosition())
            ->orderBy('e.position', $before ? 'DESC' : 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $neighbor;
    }
}
