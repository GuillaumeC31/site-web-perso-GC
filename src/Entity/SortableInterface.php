<?php

declare(strict_types=1);

namespace App\Entity;

/**
 * Contrat des entités dont l'ordre d'affichage se règle dans l'admin.
 *
 * Liens :
 * - Implémentée par : KeyFigure, Experience, SkillPillar, Skill, Education, Certification, Language
 *   (via SortableTrait)
 * - Utilisée par : PositionManager, AbstractCrudController
 */
interface SortableInterface
{
    /** Renvoie l'identifiant technique (null avant enregistrement). */
    public function getId(): ?int;

    /** Renvoie le rang d'affichage. */
    public function getPosition(): int;

    /** Définit le rang d'affichage. */
    public function setPosition(int $position): static;
}
