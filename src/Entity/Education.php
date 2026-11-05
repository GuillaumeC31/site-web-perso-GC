<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\EducationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Formation ou diplôme du CV (ex. 1999, « Bac Pro Énergétique », CFA Industrie, Colomiers).
 *
 * Liens :
 * - Utilisée par : Admin\EducationController, EducationType
 * - Affichée par : CV public (lot 3)
 */
#[ORM\Entity(repositoryClass: EducationRepository::class)]
#[ORM\Table(name: 'education')]
#[ORM\HasLifecycleCallbacks]
class Education implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Année d'obtention. */
    #[ORM\Column(type: Types::SMALLINT)]
    #[Assert\NotNull]
    #[Assert\Range(min: 1950, max: 2100)]
    private ?int $year = null;

    /** Intitulé du diplôme ou de la formation. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $title = '';

    /** Organisme (ex. « CFA de l'Industrie »). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $institution = null;

    /** Lieu (ex. « Colomiers »). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $location = null;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le lieu. */
    public function getLocation(): ?string
    {
        return $this->location;
    }

    /** Définit le lieu (texte vide : aucun). */
    public function setLocation(?string $location): static
    {
        $this->location = '' === trim((string) $location) ? null : trim((string) $location);

        return $this;
    }

    /** Renvoie l'année. */
    public function getYear(): ?int
    {
        return $this->year;
    }

    /** Définit l'année. */
    public function setYear(?int $year): static
    {
        $this->year = $year;

        return $this;
    }

    /** Renvoie l'intitulé. */
    public function getTitle(): string
    {
        return $this->title;
    }

    /** Définit l'intitulé. */
    public function setTitle(?string $title): static
    {
        $this->title = (string) $title;

        return $this;
    }

    /** Renvoie l'organisme et le lieu. */
    public function getInstitution(): ?string
    {
        return $this->institution;
    }

    /** Définit l'organisme et le lieu. */
    public function setInstitution(?string $institution): static
    {
        $this->institution = $institution;

        return $this;
    }

    /** Texte affiché dans les listes de l'admin. */
    public function __toString(): string
    {
        return $this->year.' — '.$this->title;
    }
}
