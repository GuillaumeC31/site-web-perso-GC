<?php

declare(strict_types=1);

namespace App\Entity\Trait;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ajoute les dates de création et de dernière modification à une entité.
 *
 * L'entité qui l'utilise doit porter #[ORM\HasLifecycleCallbacks] : les dates sont
 * alors remplies automatiquement à l'enregistrement.
 *
 * Liens :
 * - Utilisé par : User, Profile, Experience, Skill, SkillPillar, Education,
 *   Certification, Language, KeyFigure
 */
trait TimestampTrait
{
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Remplit les deux dates à la première insertion.
     *
     * Liens :
     * - Appelée par : Doctrine (évènement prePersist)
     */
    #[ORM\PrePersist]
    public function initializeTimestamps(): void
    {
        $now = new \DateTimeImmutable();
        $this->createdAt ??= $now;
        $this->updatedAt = $now;
    }

    /**
     * Met à jour la date de modification à chaque enregistrement.
     *
     * Liens :
     * - Appelée par : Doctrine (évènement preUpdate)
     */
    #[ORM\PreUpdate]
    public function refreshUpdatedAt(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** Renvoie la date de création (null avant le premier enregistrement). */
    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie la date de dernière modification (null avant le premier enregistrement). */
    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
