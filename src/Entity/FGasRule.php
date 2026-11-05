<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\FGasRuleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Règle d'interdiction du règlement (UE) 2024/573 appliquée par la chambre froide : mise sur le marché
 * (annexe IV, selon le type d'installation : fluide grisé avec la raison et la référence) ou entretien
 * (article 13, selon la charge en t éq. CO₂ : avertissement).
 *
 * Données modifiables (jamais codées en dur) ; une règle n'est affichée sur le site qu'une fois cochée
 * « Vérifiée » dans l'admin. Données initiales : config/calculator/fgas_rules.json.
 *
 * Liens :
 * - Utilisée par : Admin\FGasRuleController, FGasRuleType, LoadReferenceDataCommand, CalculatorController
 */
#[ORM\Entity(repositoryClass: FGasRuleRepository::class)]
#[ORM\Table(name: 'fgas_rule')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'])]
class FGasRule implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    /** Types d'installation de la chambre froide auxquels une règle peut s'appliquer. */
    public const INSTALLATION_TYPES = ['self_contained', 'split', 'centralised'];

    /** Types de règle : interdiction de mise sur le marché (annexe IV) ou d'entretien (article 13). */
    public const KINDS = ['placing', 'servicing'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Code court (ex. « R3 »). */
    #[ORM\Column(length: 10, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 10)]
    private string $code = '';

    /** Type de règle (KINDS). */
    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: self::KINDS)]
    private string $kind = 'placing';

    /** Équipement concerné, en clair. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    private string $equipment = '';

    /**
     * Types d'installation concernés (INSTALLATION_TYPES).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    #[Assert\Count(min: 1)]
    #[Assert\All([new Assert\Choice(choices: self::INSTALLATION_TYPES)])]
    private array $installationTypes = [];

    /** Puissance frigorifique minimale concernée (kW), ou null. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $minCapacityKw = null;

    /** Charge minimale concernée (t éq. CO₂), ou null (règles d'entretien). */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $minChargeTonnes = null;

    /** Seuil de PRG : interdit à partir de cette valeur (PRG ≥ seuil). */
    #[ORM\Column]
    #[Assert\Positive]
    private int $gwpThreshold = 150;

    /** Date d'entrée en vigueur. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $since = null;

    /** Exception éventuelle (texte). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $exception = null;

    /** Article du règlement (ex. « art. 13, paragraphe 1 »). */
    #[ORM\Column(length: 80)]
    #[Assert\NotBlank]
    private string $article = '';

    /** Point de l'annexe (ex. « annexe IV, point 13 »), ou null. */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $annexPoint = null;

    /** Source consultée (document, version, date). */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    private string $source = '';

    /** Vrai une fois la règle vérifiée sur le texte officiel : elle est alors affichée sur le site. */
    #[ORM\Column]
    private bool $verified = false;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le code court. */
    public function getCode(): string
    {
        return $this->code;
    }

    /** Définit le code court. */
    public function setCode(?string $code): static
    {
        $this->code = trim((string) $code);

        return $this;
    }

    /** Renvoie le type de règle. */
    public function getKind(): string
    {
        return $this->kind;
    }

    /** Définit le type de règle. */
    public function setKind(?string $kind): static
    {
        $this->kind = (string) $kind;

        return $this;
    }

    /**
     * Clé de traduction du type de règle (colonne de la liste de l'admin).
     *
     * Liens :
     * - Appelée par : Admin\FGasRuleController::getColumns() (via admin/crud/index.html.twig)
     */
    public function getKindLabel(): string
    {
        return 'admin.fgas_rule.kind.'.$this->kind;
    }

    /** Renvoie l'équipement concerné. */
    public function getEquipment(): string
    {
        return $this->equipment;
    }

    /** Définit l'équipement concerné. */
    public function setEquipment(?string $equipment): static
    {
        $this->equipment = trim((string) $equipment);

        return $this;
    }

    /**
     * Renvoie les types d'installation concernés.
     *
     * @return list<string>
     */
    public function getInstallationTypes(): array
    {
        return $this->installationTypes;
    }

    /**
     * Définit les types d'installation concernés.
     *
     * @param array<int, string> $installationTypes types cochés (les clés du formulaire sont réindexées)
     */
    public function setInstallationTypes(array $installationTypes): static
    {
        $this->installationTypes = array_values($installationTypes);

        return $this;
    }

    /** Renvoie la puissance minimale concernée (kW), ou null. */
    public function getMinCapacityKw(): ?float
    {
        return $this->minCapacityKw;
    }

    /** Définit la puissance minimale concernée (kW). */
    public function setMinCapacityKw(?float $minCapacityKw): static
    {
        $this->minCapacityKw = $minCapacityKw;

        return $this;
    }

    /** Renvoie la charge minimale concernée (t éq. CO₂), ou null. */
    public function getMinChargeTonnes(): ?float
    {
        return $this->minChargeTonnes;
    }

    /** Définit la charge minimale concernée (t éq. CO₂). */
    public function setMinChargeTonnes(?float $minChargeTonnes): static
    {
        $this->minChargeTonnes = $minChargeTonnes;

        return $this;
    }

    /** Renvoie le seuil de PRG. */
    public function getGwpThreshold(): int
    {
        return $this->gwpThreshold;
    }

    /** Définit le seuil de PRG. */
    public function setGwpThreshold(?int $gwpThreshold): static
    {
        $this->gwpThreshold = (int) $gwpThreshold;

        return $this;
    }

    /** Renvoie la date d'entrée en vigueur. */
    public function getSince(): ?\DateTimeImmutable
    {
        return $this->since;
    }

    /** Définit la date d'entrée en vigueur. */
    public function setSince(?\DateTimeImmutable $since): static
    {
        $this->since = $since;

        return $this;
    }

    /** Renvoie l'exception, ou null. */
    public function getException(): ?string
    {
        return $this->exception;
    }

    /** Définit l'exception. */
    public function setException(?string $exception): static
    {
        $this->exception = null === $exception || '' === trim($exception) ? null : trim($exception);

        return $this;
    }

    /** Renvoie l'article. */
    public function getArticle(): string
    {
        return $this->article;
    }

    /** Définit l'article. */
    public function setArticle(?string $article): static
    {
        $this->article = trim((string) $article);

        return $this;
    }

    /** Renvoie le point de l'annexe, ou null. */
    public function getAnnexPoint(): ?string
    {
        return $this->annexPoint;
    }

    /** Définit le point de l'annexe. */
    public function setAnnexPoint(?string $annexPoint): static
    {
        $this->annexPoint = null === $annexPoint || '' === trim($annexPoint) ? null : trim($annexPoint);

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

    /** Indique si la règle est vérifiée (et donc affichée). */
    public function isVerified(): bool
    {
        return $this->verified;
    }

    /** Marque la règle comme vérifiée (ou non). */
    public function setVerified(bool $verified): static
    {
        $this->verified = $verified;

        return $this;
    }

    /**
     * Données transmises à la chambre froide (JSON).
     *
     * Liens :
     * - Appelée par : CalculatorController::toolData()
     *
     * @return array{code: string, kind: string, equipment: string, types: list<string>, minCapacityKw: ?float, minChargeTonnes: ?float, gwp: int, since: ?string, exception: ?string, article: string, annexPoint: ?string}
     */
    public function toCalculatorArray(): array
    {
        return [
            'code' => $this->code, 'kind' => $this->kind, 'equipment' => $this->equipment, 'types' => $this->installationTypes,
            'minCapacityKw' => $this->minCapacityKw, 'minChargeTonnes' => $this->minChargeTonnes,
            'gwp' => $this->gwpThreshold, 'since' => $this->since?->format('Y-m-d'), 'exception' => $this->exception,
            'article' => $this->article, 'annexPoint' => $this->annexPoint,
        ];
    }

    /** Renvoie le code et l'équipement de la règle (listes de l'admin, confirmation de suppression). */
    public function __toString(): string
    {
        return trim($this->code.' — '.$this->equipment, ' —');
    }
}
