<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\SkillPillarRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Pilier de compétences (ex. « Génie climatique et frigorifique »), modifiable dans l'admin.
 *
 * Les quatre piliers de départ (TEXTES_SITE §5) sont créés par la commande
 * app:skill-pillar:create-defaults.
 *
 * Liens :
 * - Utilisée par : Admin\SkillPillarController, SkillPillarType, SkillType (choix du pilier)
 * - Contient : Skill (relation un-à-plusieurs)
 */
#[ORM\Entity(repositoryClass: SkillPillarRepository::class)]
#[ORM\Table(name: 'skill_pillar')]
#[ORM\HasLifecycleCallbacks]
class SkillPillar implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom du pilier. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /** Résumé d'une phrase affiché sur la carte du pilier (accueil). Vide : liste des compétences. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 300)]
    private ?string $summary = null;

    /** Étiquette facultative affichée sous le nom (ex. « mon plus »). */
    #[ORM\Column(length: 40, nullable: true)]
    #[Assert\Length(max: 40)]
    private ?string $badge = null;

    /** Icône de la carte, parmi self::ICONS. */
    #[ORM\Column(length: 20, options: ['default' => 'generic'])]
    #[Assert\Choice(choices: self::ICONS)]
    private string $icon = 'generic';

    /** Icônes disponibles (dessins définis dans templates/_partials/_pillar_icon.html.twig). */
    public const ICONS = ['climate', 'electric', 'design', 'digital', 'project', 'generic'];

    /** @var Collection<int, Skill> compétences du pilier */
    #[ORM\OneToMany(targetEntity: Skill::class, mappedBy: 'pillar')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $skills;

    /** Initialise la collection des compétences. */
    public function __construct()
    {
        $this->skills = new ArrayCollection();
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le nom. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit le nom. */
    public function setName(?string $name): static
    {
        $this->name = (string) $name;

        return $this;
    }

    /** Renvoie le résumé de la carte, ou null. */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** Définit le résumé de la carte. */
    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    /** Renvoie l'étiquette, ou null. */
    public function getBadge(): ?string
    {
        return $this->badge;
    }

    /** Définit l'étiquette (null pour aucune). */
    public function setBadge(?string $badge): static
    {
        $this->badge = $badge;

        return $this;
    }

    /** Renvoie l'icône de la carte. */
    public function getIcon(): string
    {
        return $this->icon;
    }

    /** Définit l'icône de la carte (une valeur de self::ICONS). */
    public function setIcon(string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    /**
     * Renvoie les compétences du pilier, dans l'ordre d'affichage.
     *
     * @return Collection<int, Skill>
     */
    public function getSkills(): Collection
    {
        return $this->skills;
    }

    /** Texte affiché dans les listes et les menus déroulants. */
    public function __toString(): string
    {
        return $this->name;
    }
}
