<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\ClimaticProfileRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Profil de consigne de l'enceinte climatique v2 (secteur, consigne, tolérances, source) : ICH Q1A(R2),
 * IEC 60068, conservation, cosmétique… Le profil remplit les consignes de la page, qui restent modifiables.
 *
 * Données initiales : config/calculator/climatic_profiles.json (chargées par LoadReferenceDataCommand) ;
 * valeurs à vérifier dans l'admin (case « Valeurs vérifiées »). Aucun profil alimentaire.
 *
 * Liens :
 * - Utilisée par : Admin\ClimaticProfileController, ClimaticProfileType, LoadReferenceDataCommand,
 *   CalculatorController (outil « Enceinte climatique »)
 */
#[ORM\Entity(repositoryClass: ClimaticProfileRepository::class)]
#[ORM\Table(name: 'climatic_profile')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'])]
class ClimaticProfile implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Code technique (ex. « ich_long_term »), identifiant stable. */
    #[ORM\Column(length: 40, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'climatic_profile.code_format')]
    private string $code = '';

    /** Secteur (Pharmaceutique, Électronique…), qui regroupe les profils dans la liste. */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    private string $sector = '';

    /** Nom du profil (ex. « Long terme »). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $name = '';

    /** Température de consigne (°C). */
    #[ORM\Column]
    #[Assert\GreaterThan(-273.15)]
    private float $temperature = 25.0;

    /** Humidité de consigne (% HR), ou null si l'humidité n'est pas régulée. */
    #[ORM\Column(nullable: true)]
    #[Assert\Range(min: 1, max: 100)]
    private ?float $humidity = null;

    /** Tolérance en température (± K). */
    #[ORM\Column]
    #[Assert\Positive]
    private float $temperatureTolerance = 2.0;

    /** Tolérance en humidité (± % HR), ou null. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $humidityTolerance = null;

    /** Source (texte de référence, ex. « ICH Q1A(R2) »). */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $source = '';

    /** Profil présélectionné sur la page (un seul). */
    #[ORM\Column]
    private bool $defaultChoice = false;

    /** Valeurs vérifiées par l'administrateur (sinon « à vérifier » sur la page). */
    #[ORM\Column]
    private bool $verified = false;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le code. */
    public function getCode(): string
    {
        return $this->code;
    }

    /** Définit le code. */
    public function setCode(?string $code): static
    {
        $this->code = trim((string) $code);

        return $this;
    }

    /** Renvoie le secteur. */
    public function getSector(): string
    {
        return $this->sector;
    }

    /** Définit le secteur. */
    public function setSector(?string $sector): static
    {
        $this->sector = trim((string) $sector);

        return $this;
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

    /** Renvoie la température de consigne (°C). */
    public function getTemperature(): float
    {
        return $this->temperature;
    }

    /** Définit la température de consigne (°C). */
    public function setTemperature(?float $temperature): static
    {
        $this->temperature = (float) $temperature;

        return $this;
    }

    /** Renvoie l'humidité de consigne (% HR), ou null si elle n'est pas régulée. */
    public function getHumidity(): ?float
    {
        return $this->humidity;
    }

    /** Définit l'humidité de consigne (null : non régulée). */
    public function setHumidity(?float $humidity): static
    {
        $this->humidity = $humidity;

        return $this;
    }

    /** Renvoie la tolérance en température (± K). */
    public function getTemperatureTolerance(): float
    {
        return $this->temperatureTolerance;
    }

    /** Définit la tolérance en température (± K). */
    public function setTemperatureTolerance(?float $temperatureTolerance): static
    {
        $this->temperatureTolerance = (float) $temperatureTolerance;

        return $this;
    }

    /** Renvoie la tolérance en humidité (± % HR), ou null. */
    public function getHumidityTolerance(): ?float
    {
        return $this->humidityTolerance;
    }

    /** Définit la tolérance en humidité (± % HR). */
    public function setHumidityTolerance(?float $humidityTolerance): static
    {
        $this->humidityTolerance = $humidityTolerance;

        return $this;
    }

    /** Renvoie la source. */
    public function getSource(): string
    {
        return $this->source;
    }

    /** Définit la source. */
    public function setSource(?string $source): static
    {
        $this->source = trim((string) $source);

        return $this;
    }

    /** Indique si le profil est présélectionné sur la page. */
    public function isDefaultChoice(): bool
    {
        return $this->defaultChoice;
    }

    /** Définit le profil présélectionné. */
    public function setDefaultChoice(bool $defaultChoice): static
    {
        $this->defaultChoice = $defaultChoice;

        return $this;
    }

    /** Indique si les valeurs ont été vérifiées. */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /** Marque les valeurs comme vérifiées (ou non). */
    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    /**
     * Données transmises à l'enceinte climatique (JSON), au format de config/calculator/climatic_profiles.json.
     *
     * Liens :
     * - Appelée par : templates/calculator/tools/thermodynamique/enceinte-climatique.html.twig
     *
     * @return array{code: string, sector: string, name: string, tSet: float, rhSet: ?float, tolT: float, tolRh: ?float, source: string, default: bool, verified: bool}
     */
    public function toCalculatorArray(): array
    {
        return [
            'code' => $this->code, 'sector' => $this->sector, 'name' => $this->name, 'tSet' => $this->temperature, 'rhSet' => $this->humidity,
            'tolT' => $this->temperatureTolerance, 'tolRh' => $this->humidityTolerance, 'source' => $this->source, 'default' => $this->defaultChoice, 'verified' => $this->verified,
        ];
    }

    /** Renvoie le nom du profil (listes de l'admin, confirmation de suppression). */
    public function __toString(): string
    {
        return $this->name;
    }
}
