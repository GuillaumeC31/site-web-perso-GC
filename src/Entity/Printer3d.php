<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\Printer3dRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Imprimante 3D de référence (outil « Coût d'une impression 3D ») : puissance consommée.
 *
 * Liens :
 * - Utilisée par : Admin\Printer3dController, Printer3dType, LoadReferenceDataCommand,
 *   outil impression-3d/cout-impression (lot 5b)
 */
#[ORM\Entity(repositoryClass: Printer3dRepository::class)]
#[ORM\Table(name: 'printer3d')]
#[ORM\UniqueConstraint(name: 'uniq_printer3d_brand_model', columns: ['brand', 'model'])]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['brand', 'model'])]
class Printer3d implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Marque (ex. « Prusa Research »). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 80)]
    private string $brand = '';

    /** Modèle (ex. « MK4 »). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $model = '';

    /** Puissance consommée pendant l'impression, en W. */
    #[ORM\Column]
    #[Assert\Positive]
    private int $powerConsumption = 0;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la marque. */
    public function getBrand(): string
    {
        return $this->brand;
    }

    /** Définit la marque. */
    public function setBrand(?string $brand): static
    {
        $this->brand = trim((string) $brand);

        return $this;
    }

    /** Renvoie le modèle. */
    public function getModel(): string
    {
        return $this->model;
    }

    /** Définit le modèle. */
    public function setModel(?string $model): static
    {
        $this->model = trim((string) $model);

        return $this;
    }

    /** Renvoie la puissance consommée (W). */
    public function getPowerConsumption(): int
    {
        return $this->powerConsumption;
    }

    /** Définit la puissance consommée (W). */
    public function setPowerConsumption(?int $powerConsumption): static
    {
        $this->powerConsumption = (int) $powerConsumption;

        return $this;
    }

    /** Renvoie « Marque Modèle » (listes de l'admin, confirmations). */
    public function __toString(): string
    {
        return $this->brand.' '.$this->model;
    }
}
