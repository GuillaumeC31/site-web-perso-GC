<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Image de la galerie d'un projet.
 *
 * Le fichier est un WebP produit par ProjectImageStorage (grande taille + variante
 * 600 px pour srcset) ; les dimensions réelles sont gardées pour les attributs
 * width et height (pas de décalage de mise en page au chargement).
 *
 * Liens :
 * - Utilisée par : Project (galerie), ProjectImageType (texte alternatif, retrait),
 *   Admin\ProjectController, ProjectPackage (export et import)
 */
#[ORM\Entity]
#[ORM\Table(name: 'project_image')]
class ProjectImage
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Projet auquel l'image appartient (supprimée avec lui). */
    #[ORM\ManyToOne(targetEntity: Project::class, inversedBy: 'images')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Project $project = null;

    /** Nom du fichier WebP dans public/uploads/projects/. */
    #[ORM\Column(length: 64)]
    private string $filename = '';

    /** Largeur réelle du fichier, en pixels. */
    #[ORM\Column]
    private int $width = 0;

    /** Hauteur réelle du fichier, en pixels. */
    #[ORM\Column]
    private int $height = 0;

    /** Texte alternatif (accessibilité). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $alt = null;

    /** Rang dans la galerie : le plus petit s'affiche en premier. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le projet. */
    public function getProject(): ?Project
    {
        return $this->project;
    }

    /** Rattache l'image à un projet. */
    public function setProject(?Project $project): static
    {
        $this->project = $project;

        return $this;
    }

    /** Renvoie le nom du fichier. */
    public function getFilename(): string
    {
        return $this->filename;
    }

    /** Définit le nom du fichier. */
    public function setFilename(string $filename): static
    {
        $this->filename = $filename;

        return $this;
    }

    /** Renvoie la largeur en pixels. */
    public function getWidth(): int
    {
        return $this->width;
    }

    /** Renvoie la hauteur en pixels. */
    public function getHeight(): int
    {
        return $this->height;
    }

    /** Définit les dimensions réelles du fichier. */
    public function setDimensions(int $width, int $height): static
    {
        $this->width = $width;
        $this->height = $height;

        return $this;
    }

    /** Renvoie le texte alternatif. */
    public function getAlt(): ?string
    {
        return $this->alt;
    }

    /** Définit le texte alternatif. */
    public function setAlt(?string $alt): static
    {
        $alt = trim((string) $alt);
        $this->alt = '' === $alt ? null : $alt;

        return $this;
    }

    /** Renvoie le rang dans la galerie. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Définit le rang dans la galerie. */
    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
