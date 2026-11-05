<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\SkillRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compétence rattachée à un pilier, sans niveau (décision du 2026-10-21 : les
 * compétences sont prouvées par les expériences liées, pas par des étoiles).
 *
 * Liens :
 * - Utilisée par : Admin\SkillController, SkillType, ExperienceType (compétences liées)
 * - Appartient à : SkillPillar ; liée à : Experience (relation plusieurs-à-plusieurs)
 */
#[ORM\Entity(repositoryClass: SkillRepository::class)]
#[ORM\Table(name: 'skill')]
#[ORM\HasLifecycleCallbacks]
class Skill implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Intitulé de la compétence (ex. « Froid industriel et commercial »). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    private string $name = '';

    /** Pilier de rattachement (un pilier qui a des compétences ne peut pas être supprimé). */
    #[ORM\ManyToOne(targetEntity: SkillPillar::class, inversedBy: 'skills')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    #[Assert\NotNull]
    private ?SkillPillar $pillar = null;

    /** @var Collection<int, Experience> expériences qui mobilisent cette compétence */
    #[ORM\ManyToMany(targetEntity: Experience::class, mappedBy: 'skills')]
    private Collection $experiences;

    /** Initialise la collection des expériences liées. */
    public function __construct()
    {
        $this->experiences = new ArrayCollection();
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie l'intitulé. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit l'intitulé. */
    public function setName(?string $name): static
    {
        $this->name = (string) $name;

        return $this;
    }

    /** Renvoie le pilier. */
    public function getPillar(): ?SkillPillar
    {
        return $this->pillar;
    }

    /** Définit le pilier. */
    public function setPillar(?SkillPillar $pillar): static
    {
        $this->pillar = $pillar;

        return $this;
    }

    /**
     * Renvoie les expériences liées.
     *
     * @return Collection<int, Experience>
     */
    public function getExperiences(): Collection
    {
        return $this->experiences;
    }

    /** Texte affiché dans les listes et les menus déroulants. */
    public function __toString(): string
    {
        return $this->name;
    }
}
