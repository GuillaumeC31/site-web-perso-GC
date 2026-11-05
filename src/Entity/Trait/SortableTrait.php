<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ajoute un ordre d'affichage modifiable depuis l'admin (boutons « monter » / « descendre »).
 *
 * Liens :
 * - Utilisé par : KeyFigure, Experience, SkillPillar, Skill, Education, Certification, Language
 * - Voir aussi : App\Service\Admin\PositionManager (calcul et échange des positions)
 */
trait SortableTrait
{
    /** Rang d'affichage : le plus petit s'affiche en premier. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Renvoie le rang d'affichage. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Définit le rang d'affichage. */
    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
