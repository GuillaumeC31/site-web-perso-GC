<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Entity\SortableInterface;
use App\Repository\AbstractSortableRepository;

/**
 * Calcule l'ordre d'affichage des contenus gérés dans l'admin.
 *
 * Liens :
 * - Utilisé par : AbstractCrudController::new(), AbstractCrudController::move()
 * - Utilise : AbstractSortableRepository::findMaxPosition(), AbstractSortableRepository::findNeighbor()
 */
final class PositionManager
{
    /**
     * Place une nouvelle ligne en dernière position.
     *
     * Liens :
     * - Appelée par : AbstractCrudController::new()
     *
     * @template T of SortableInterface
     *
     * @param T                             $entity     nouvelle ligne (pas encore enregistrée)
     * @param AbstractSortableRepository<T> $repository dépôt de l'entité
     */
    public function placeLast(SortableInterface $entity, AbstractSortableRepository $repository): void
    {
        $entity->setPosition($repository->findMaxPosition() + 1);
    }

    /**
     * Échange la position d'une ligne avec sa voisine (au-dessus ou en dessous).
     *
     * L'appelant enregistre ensuite (flush).
     *
     * Liens :
     * - Appelée par : AbstractCrudController::move()
     *
     * @template T of SortableInterface
     *
     * @param T                             $entity     ligne à déplacer
     * @param AbstractSortableRepository<T> $repository dépôt de l'entité
     * @param bool                          $up         vrai pour monter, faux pour descendre
     *
     * @return bool faux si la ligne est déjà en tête (ou en fin) : rien n'a changé
     */
    public function move(SortableInterface $entity, AbstractSortableRepository $repository, bool $up): bool
    {
        $neighbor = $repository->findNeighbor($entity, $up);
        if (null === $neighbor) {
            return false;
        }

        $position = $entity->getPosition();
        $entity->setPosition($neighbor->getPosition());
        $neighbor->setPosition($position);

        return true;
    }
}
