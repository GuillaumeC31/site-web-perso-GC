<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\EnergyProviderRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Offre d'électricité de référence (outil « Coût d'une impression 3D ») : prix du kWh.
 *
 * Liens :
 * - Utilisée par : Admin\EnergyProviderController, EnergyProviderType, LoadReferenceDataCommand,
 *   outil impression-3d/cout-impression (lot 5b)
 */
#[ORM\Entity(repositoryClass: EnergyProviderRepository::class)]
#[ORM\Table(name: 'energy_provider')]
#[ORM\UniqueConstraint(name: 'uniq_energy_provider_name_plan', columns: ['name', 'plan_name'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['name', 'planName'])]
class EnergyProvider implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Fournisseur (ex. « EDF »). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $name = '';

    /** Offre (ex. « Tarif Bleu (Base) »). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $planName = '';

    /** Prix du kWh, en euros. */
    #[ORM\Column]
    #[Assert\Positive]
    private float $priceKwh = 0.0;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le fournisseur. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit le fournisseur. */
    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    /** Renvoie l'offre. */
    public function getPlanName(): string
    {
        return $this->planName;
    }

    /** Définit l'offre. */
    public function setPlanName(?string $planName): static
    {
        $this->planName = trim((string) $planName);

        return $this;
    }

    /** Renvoie le prix du kWh (€). */
    public function getPriceKwh(): float
    {
        return $this->priceKwh;
    }

    /** Définit le prix du kWh (€). */
    public function setPriceKwh(?float $priceKwh): static
    {
        $this->priceKwh = (float) $priceKwh;

        return $this;
    }

    /** Renvoie « Fournisseur — Offre » (listes de l'admin, confirmations). */
    public function __toString(): string
    {
        return $this->name.' — '.$this->planName;
    }
}
