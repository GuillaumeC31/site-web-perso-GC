<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampTrait;
use App\Repository\CalculatorToolSettingRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Réglages d'un outil de la calculatrice modifiés dans l'admin (CDC § 8) : activation,
 * texte d'aide, norme citée et sa validation, ordre d'affichage.
 *
 * La définition de l'outil (titre, formules, statut) reste dans config/calculator/tools.yaml ;
 * une ligne n'existe ici que si l'admin a modifié quelque chose. Une valeur null reprend celle
 * du registre.
 *
 * Liens :
 * - Utilisée par : CalculatorRegistry (fusion avec le registre), Admin\CalculatorToolController,
 *   CalculatorToolSettingType
 */
#[ORM\Entity(repositoryClass: CalculatorToolSettingRepository::class)]
#[ORM\Table(name: 'calculator_tool_setting')]
#[ORM\HasLifecycleCallbacks]
class CalculatorToolSetting
{
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Clé de l'outil : « categorie/outil » (ex. « electricite/loi-ohm »). */
    #[ORM\Column(length: 120, unique: true)]
    private string $toolKey = '';

    /** Vrai : outil visible (s'il est porté) ; faux : masqué du site. */
    #[ORM\Column(options: ['default' => true])]
    private bool $enabled = true;

    /** Texte d'aide affiché sous l'outil. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $helpText = null;

    /** Norme ou source citée (null : celle du registre). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $reference = null;

    /** Vrai : la norme a été vérifiée et peut être affichée en public. */
    #[ORM\Column(options: ['default' => false])]
    private bool $referenceVerified = false;

    /** Rang d'affichage dans la catégorie (null : ordre du registre). */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 0, max: 9999)]
    private ?int $position = null;

    /**
     * Crée les réglages d'un outil.
     *
     * @param string $toolKey clé « categorie/outil »
     */
    public function __construct(string $toolKey = '')
    {
        $this->toolKey = $toolKey;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la clé de l'outil. */
    public function getToolKey(): string
    {
        return $this->toolKey;
    }

    /** Indique si l'outil est activé. */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /** Active ou désactive l'outil. */
    public function setEnabled(bool $enabled): static
    {
        $this->enabled = $enabled;

        return $this;
    }

    /** Renvoie le texte d'aide. */
    public function getHelpText(): ?string
    {
        return $this->helpText;
    }

    /** Définit le texte d'aide. */
    public function setHelpText(?string $helpText): static
    {
        $helpText = trim((string) $helpText);
        $this->helpText = '' === $helpText ? null : $helpText;

        return $this;
    }

    /** Renvoie la norme citée (null : celle du registre). */
    public function getReference(): ?string
    {
        return $this->reference;
    }

    /** Définit la norme citée. */
    public function setReference(?string $reference): static
    {
        $reference = trim((string) $reference);
        $this->reference = '' === $reference ? null : $reference;

        return $this;
    }

    /** Indique si la norme a été vérifiée. */
    public function isReferenceVerified(): bool
    {
        return $this->referenceVerified;
    }

    /** Marque la norme comme vérifiée ou non. */
    public function setReferenceVerified(bool $referenceVerified): static
    {
        $this->referenceVerified = $referenceVerified;

        return $this;
    }

    /** Renvoie le rang d'affichage (null : ordre du registre). */
    public function getPosition(): ?int
    {
        return $this->position;
    }

    /** Définit le rang d'affichage. */
    public function setPosition(?int $position): static
    {
        $this->position = $position;

        return $this;
    }
}
