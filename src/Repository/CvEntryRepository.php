<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CvEntry;
use App\Entity\SortableInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des éléments des rubriques « liste » du CV : ordre par rubrique (ordre du CV), puis par
 * position à l'intérieur de la rubrique.
 *
 * Liens :
 * - Utilisé par : Admin\CvEntryController (via AbstractCrudController), PositionManager,
 *   CvContentProvider, CvContentSynchronizer
 *
 * @extends AbstractSortableRepository<CvEntry>
 */
class CvEntryRepository extends AbstractSortableRepository
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
        parent::__construct($registry, CvEntry::class);
    }

    /**
     * Renvoie les éléments dans l'ordre du CV : rubrique, puis position.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::index(), findGroupedBySection()
     *
     * @return list<CvEntry>
     */
    public function findAllOrdered(): array
    {
        $entries = parent::findAllOrdered();
        // Tri stable : l'ordre des positions est conservé à l'intérieur d'une rubrique
        usort($entries, static fn (CvEntry $a, CvEntry $b): int => $a->getSection()->rank() <=> $b->getSection()->rank());

        return $entries;
    }

    /**
     * Renvoie les éléments regroupés par rubrique (clé : valeur de CvEntrySection), rubriques vides absentes.
     *
     * Liens :
     * - Appelée par : CvContentProvider::getContent()
     *
     * @return array<string, list<CvEntry>>
     */
    public function findGroupedBySection(): array
    {
        $groups = [];
        foreach ($this->findAllOrdered() as $entry) {
            $groups[$entry->getSection()->value][] = $entry;
        }

        return $groups;
    }

    /**
     * Trouve la voisine dans la même rubrique (les flèches de l'admin ne changent pas de rubrique).
     *
     * Liens :
     * - Appelée par : PositionManager::move()
     *
     * @param SortableInterface $entity ligne de référence
     * @param bool              $before vrai pour la voisine précédente, faux pour la suivante
     *
     * @return CvEntry|null la voisine, ou null en tête (ou en fin) de rubrique
     */
    public function findNeighbor(SortableInterface $entity, bool $before): ?SortableInterface
    {
        if (!$entity instanceof CvEntry) {
            return parent::findNeighbor($entity, $before);
        }

        /** @var CvEntry|null $neighbor */
        $neighbor = $this->createQueryBuilder('e')
            ->where($before ? 'e.position < :position' : 'e.position > :position')
            ->andWhere('e.section = :section')
            ->setParameter('position', $entity->getPosition())
            ->setParameter('section', $entity->getSection())
            ->orderBy('e.position', $before ? 'DESC' : 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $neighbor;
    }
}
