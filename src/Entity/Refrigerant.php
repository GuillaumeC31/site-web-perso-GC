<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Repository\RefrigerantRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fluide frigorigène de référence (base unique de la calculatrice) : PRG et rapport du GIEC d'origine,
 * statut F-Gas, classe de sécurité (ISO 817), LFL, glissement, huile, substituts, applications.
 *
 * Données initiales : config/calculator/refrigerants.json (chargées par LoadReferenceDataCommand) ;
 * valeurs à vérifier dans l'admin (case « Valeurs vérifiées »).
 *
 * Liens :
 * - Utilisée par : Admin\RefrigerantController, RefrigerantType, LoadReferenceDataCommand,
 *   CalculatorController (outils « Fluides frigorigènes » et « Chambre froide »)
 */
#[ORM\Entity(repositoryClass: RefrigerantRepository::class)]
#[ORM\Table(name: 'refrigerant')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['code'])]
class Refrigerant implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Code technique sans tiret (ex. « R410A »), identifiant stable. */
    #[ORM\Column(length: 20, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^R[0-9A-Za-z()]+$/', message: 'refrigerant.code_format')]
    private string $code = '';

    /** Nom affiché (ex. « R-410A », « R-290 (propane) »). */
    #[ORM\Column(length: 60)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 60)]
    private string $name = '';

    /** Famille (CFC, HCFC, HFC, HFO, mélange HFO, naturel). */
    #[ORM\Column(length: 30)]
    #[Assert\NotBlank]
    private string $family = '';

    /** Potentiel de réchauffement planétaire (PRG / GWP). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $gwp = 0;

    /** Rapport du GIEC d'où vient le PRG (« AR4 » ou « AR5 »). */
    #[ORM\Column(length: 3)]
    #[Assert\Choice(choices: ['AR4', 'AR5'])]
    private string $gwpReport = 'AR4';

    /** Statut F-Gas affiché (« Interdit », « Interdit (neuf) », « En transition », « Avenir »). */
    #[ORM\Column(length: 40)]
    #[Assert\NotBlank]
    private string $status = '';

    /** Classe de sécurité selon l'ISO 817 (A1, A2L, A2, A3, B2L…). */
    #[ORM\Column(length: 4)]
    #[Assert\NotBlank]
    private string $safetyClass = '';

    /** Limite inférieure d'inflammabilité (kg/m³, ISO 817) des fluides A2L, A2, A3, B2L ; null sinon ou si non renseignée. */
    #[ORM\Column(nullable: true)]
    #[Assert\Positive]
    private ?float $lfl = null;

    /** Glissement de température (K) ; null si non renseigné. */
    #[ORM\Column(nullable: true)]
    #[Assert\PositiveOrZero]
    private ?float $glide = null;

    /** Huile compatible. */
    #[ORM\Column(length: 60)]
    private string $oil = '';

    /** Substituts conseillés. */
    #[ORM\Column(length: 120)]
    private string $replacement = '';

    /** Applications et régimes. */
    #[ORM\Column(length: 120)]
    private string $application = '';

    /** Fluide choisi par défaut dans la chambre froide (un seul attendu ; R-290 au départ). */
    #[ORM\Column]
    private bool $defaultChoice = false;

    /** Vrai une fois les valeurs vérifiées par l'administrateur (sinon : « à vérifier »). */
    #[ORM\Column]
    private bool $verified = false;

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le code technique. */
    public function getCode(): string
    {
        return $this->code;
    }

    /** Définit le code technique. */
    public function setCode(?string $code): static
    {
        $this->code = trim((string) $code);

        return $this;
    }

    /** Renvoie le nom affiché. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Définit le nom affiché. */
    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    /** Renvoie la famille. */
    public function getFamily(): string
    {
        return $this->family;
    }

    /** Définit la famille. */
    public function setFamily(?string $family): static
    {
        $this->family = trim((string) $family);

        return $this;
    }

    /** Renvoie le PRG. */
    public function getGwp(): int
    {
        return $this->gwp;
    }

    /** Définit le PRG. */
    public function setGwp(?int $gwp): static
    {
        $this->gwp = (int) $gwp;

        return $this;
    }

    /** Renvoie le rapport du GIEC du PRG. */
    public function getGwpReport(): string
    {
        return $this->gwpReport;
    }

    /** Définit le rapport du GIEC du PRG. */
    public function setGwpReport(?string $gwpReport): static
    {
        $this->gwpReport = (string) $gwpReport;

        return $this;
    }

    /** Renvoie le statut F-Gas affiché. */
    public function getStatus(): string
    {
        return $this->status;
    }

    /** Définit le statut F-Gas affiché. */
    public function setStatus(?string $status): static
    {
        $this->status = trim((string) $status);

        return $this;
    }

    /** Renvoie la classe de sécurité (ISO 817). */
    public function getSafetyClass(): string
    {
        return $this->safetyClass;
    }

    /** Définit la classe de sécurité (ISO 817). */
    public function setSafetyClass(?string $safetyClass): static
    {
        $this->safetyClass = trim((string) $safetyClass);

        return $this;
    }

    /** Renvoie la LFL (kg/m³), ou null. */
    public function getLfl(): ?float
    {
        return $this->lfl;
    }

    /** Définit la LFL (kg/m³). */
    public function setLfl(?float $lfl): static
    {
        $this->lfl = $lfl;

        return $this;
    }

    /** Renvoie le glissement (K), ou null. */
    public function getGlide(): ?float
    {
        return $this->glide;
    }

    /** Définit le glissement (K). */
    public function setGlide(?float $glide): static
    {
        $this->glide = $glide;

        return $this;
    }

    /** Renvoie l'huile compatible. */
    public function getOil(): string
    {
        return $this->oil;
    }

    /** Définit l'huile compatible. */
    public function setOil(?string $oil): static
    {
        $this->oil = trim((string) $oil);

        return $this;
    }

    /** Renvoie les substituts. */
    public function getReplacement(): string
    {
        return $this->replacement;
    }

    /** Définit les substituts. */
    public function setReplacement(?string $replacement): static
    {
        $this->replacement = trim((string) $replacement);

        return $this;
    }

    /** Renvoie les applications. */
    public function getApplication(): string
    {
        return $this->application;
    }

    /** Définit les applications. */
    public function setApplication(?string $application): static
    {
        $this->application = trim((string) $application);

        return $this;
    }

    /** Indique si c'est le fluide choisi par défaut. */
    public function isDefaultChoice(): bool
    {
        return $this->defaultChoice;
    }

    /** Fait de ce fluide le choix par défaut (ou non). */
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
     * Données transmises aux outils de la calculatrice (JSON).
     *
     * Liens :
     * - Appelée par : CalculatorController::toolData()
     *
     * @return array{code: string, name: string, family: string, gwp: int, gwpReport: string, status: string, class: string, lfl: ?float, glide: ?float, oil: string, replacement: string, application: string, default: bool, verified: bool}
     */
    public function toCalculatorArray(): array
    {
        return [
            'code' => $this->code, 'name' => $this->name, 'family' => $this->family, 'gwp' => $this->gwp, 'gwpReport' => $this->gwpReport,
            'status' => $this->status, 'class' => $this->safetyClass, 'lfl' => $this->lfl, 'glide' => $this->glide, 'oil' => $this->oil,
            'replacement' => $this->replacement, 'application' => $this->application, 'default' => $this->defaultChoice, 'verified' => $this->verified,
        ];
    }

    /** Renvoie le nom du fluide (listes de l'admin, confirmation de suppression). */
    public function __toString(): string
    {
        return $this->name;
    }
}
