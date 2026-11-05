<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\PanelModelRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Modèle de panneau isolant d'une marque (chambre froide v2) : gamme, épaisseur, U ou λ, avec la
 * source et la date de la fiche technique. Aucun modèle de marque n'est codé en dur : ils sont
 * saisis dans l'admin.
 *
 * Liens :
 * - Utilisée par : Admin\PanelModelController, PanelModelType, CalculatorController (chambre froide)
 */
#[ORM\Entity(repositoryClass: PanelModelRepository::class)]
#[ORM\Table(name: 'panel_model')]
#[ORM\HasLifecycleCallbacks]
class PanelModel implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    /** Résistances superficielles intérieure et extérieure cumulées (m²·K/W), pour U = 1 / (0,17 + e/λ). */
    public const SURFACE_RESISTANCE = 0.17;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Marque. */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $brand = '';

    /** Gamme (nom commercial). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    private string $productRange = '';

    /** Isolant (PUR, PIR, laine de roche…). */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    private string $insulation = '';

    /** Épaisseur (mm). */
    #[ORM\Column]
    #[Assert\Positive]
    private int $thickness = 0;

    /** Coefficient U de la fiche (W/(m²·K)), ou null si seul λ est donné. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $uValue = null;

    /** Conductivité λ de l'isolant (W/(m·K)), ou null si U est donné. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $lambda = null;

    /** Fiche technique (titre ou adresse). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $sourceDocument = '';

    /** Date de la fiche technique. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $sourceDate = null;

    /**
     * Exige U ou λ.
     *
     * Liens :
     * - Appelée par : le validateur Symfony
     */
    #[Assert\Callback]
    public function validateThermalValue(ExecutionContextInterface $context): void
    {
        if (null === $this->uValue && null === $this->lambda) {
            $context->buildViolation('panel_model.u_or_lambda')->atPath('uValue')->addViolation();
        }
    }

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

    /** Renvoie la gamme. */
    public function getProductRange(): string
    {
        return $this->productRange;
    }

    /** Définit la gamme. */
    public function setProductRange(?string $productRange): static
    {
        $this->productRange = trim((string) $productRange);

        return $this;
    }

    /** Renvoie l'isolant. */
    public function getInsulation(): string
    {
        return $this->insulation;
    }

    /** Définit l'isolant. */
    public function setInsulation(?string $insulation): static
    {
        $this->insulation = trim((string) $insulation);

        return $this;
    }

    /** Renvoie l'épaisseur (mm). */
    public function getThickness(): int
    {
        return $this->thickness;
    }

    /** Définit l'épaisseur (mm). */
    public function setThickness(?int $thickness): static
    {
        $this->thickness = (int) $thickness;

        return $this;
    }

    /** Renvoie le U de la fiche, ou null. */
    public function getUValue(): ?float
    {
        return $this->uValue;
    }

    /** Définit le U de la fiche. */
    public function setUValue(?float $uValue): static
    {
        $this->uValue = $uValue;

        return $this;
    }

    /** Renvoie λ, ou null. */
    public function getLambda(): ?float
    {
        return $this->lambda;
    }

    /** Définit λ. */
    public function setLambda(?float $lambda): static
    {
        $this->lambda = $lambda;

        return $this;
    }

    /** Renvoie la fiche technique. */
    public function getSourceDocument(): string
    {
        return $this->sourceDocument;
    }

    /** Définit la fiche technique. */
    public function setSourceDocument(?string $sourceDocument): static
    {
        $this->sourceDocument = trim((string) $sourceDocument);

        return $this;
    }

    /** Renvoie la date de la fiche. */
    public function getSourceDate(): ?\DateTimeImmutable
    {
        return $this->sourceDate;
    }

    /** Définit la date de la fiche. */
    public function setSourceDate(?\DateTimeImmutable $sourceDate): static
    {
        $this->sourceDate = $sourceDate;

        return $this;
    }

    /**
     * Coefficient U utilisé par la chambre froide : U de la fiche, sinon 1 / (0,17 + e/λ).
     *
     * Liens :
     * - Appelée par : toCalculatorArray(), Admin\PanelModelController (colonne de la liste)
     */
    public function getEffectiveU(): float
    {
        if (null !== $this->uValue) {
            return $this->uValue;
        }

        return 1 / (self::SURFACE_RESISTANCE + $this->thickness / 1000 / (float) $this->lambda);
    }

    /**
     * Données transmises à la chambre froide (JSON).
     *
     * Liens :
     * - Appelée par : CalculatorController::toolData()
     *
     * @return array{id: ?int, label: string, insulation: string, thickness: int, u: float, source: string}
     */
    public function toCalculatorArray(): array
    {
        return [
            'id' => $this->id, 'label' => \sprintf('%s %s %d mm', $this->brand, $this->productRange, $this->thickness),
            'insulation' => $this->insulation, 'thickness' => $this->thickness, 'u' => $this->getEffectiveU(),
            'source' => \sprintf('%s (%s)', $this->sourceDocument, $this->sourceDate?->format('d/m/Y') ?? 'date inconnue'),
        ];
    }

    /** Renvoie la marque, la gamme et l'épaisseur du panneau (listes de l'admin, confirmation de suppression). */
    public function __toString(): string
    {
        return trim($this->brand.' '.$this->productRange).' — '.$this->thickness.' mm';
    }
}
