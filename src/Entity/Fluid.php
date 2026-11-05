<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Enum\FluidState;
use App\Repository\FluidRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fluide de référence de la calculatrice (outil « Chauffe d'un fluide ») : masse volumique
 * et capacité thermique massique.
 *
 * Liens :
 * - Utilisée par : Admin\FluidController, FluidType, LoadReferenceDataCommand,
 *   outil thermodynamique/chauffe-fluide (lot 5b)
 */
#[ORM\Entity(repositoryClass: FluidRepository::class)]
#[ORM\Table(name: 'fluid')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name'])]
class Fluid implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Nom affiché (ex. « Eau liquide »). */
    #[ORM\Column(length: 120, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $name = '';

    /** Liquide ou gaz. */
    #[ORM\Column(length: 20, enumType: FluidState::class)]
    private FluidState $state = FluidState::Liquid;

    /** Masse volumique, en kg/m³. */
    #[ORM\Column]
    #[Assert\Positive]
    private float $density = 0.0;

    /** Capacité thermique massique, en J/(kg·K). */
    #[ORM\Column]
    #[Assert\Positive]
    private float $specificHeatCapacity = 0.0;

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
        $this->name = trim((string) $name);

        return $this;
    }

    /** Renvoie l'état physique. */
    public function getState(): FluidState
    {
        return $this->state;
    }

    /** Définit l'état physique. */
    public function setState(FluidState $state): static
    {
        $this->state = $state;

        return $this;
    }

    /** Renvoie la masse volumique (kg/m³). */
    public function getDensity(): float
    {
        return $this->density;
    }

    /** Définit la masse volumique (kg/m³). */
    public function setDensity(?float $density): static
    {
        $this->density = (float) $density;

        return $this;
    }

    /** Renvoie la capacité thermique massique (J/(kg·K)). */
    public function getSpecificHeatCapacity(): float
    {
        return $this->specificHeatCapacity;
    }

    /** Définit la capacité thermique massique (J/(kg·K)). */
    public function setSpecificHeatCapacity(?float $specificHeatCapacity): static
    {
        $this->specificHeatCapacity = (float) $specificHeatCapacity;

        return $this;
    }

    /** Renvoie le nom (listes de l'admin, confirmations). */
    public function __toString(): string
    {
        return $this->name;
    }
}
