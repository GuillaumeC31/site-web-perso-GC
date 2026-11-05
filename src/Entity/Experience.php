<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Enum\CareerSection;
use App\Repository\ExperienceRepository;
use App\Service\Content\PlaceholderDetector;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Expérience professionnelle du CV.
 *
 * Anonymisation : si isCompanyAnonymized est vrai, le site public affiche
 * publicCompanyLabel (ex. « PME industrielle, 80 salariés ») à la place du vrai nom,
 * qui reste réservé à l'espace recruteur validé. Le contrôle se fait côté serveur.
 *
 * Les missions, réalisations chiffrées et outils se saisissent une ligne par élément.
 *
 * Rubrique (CareerSection, ordre du CV de référence) : « Expériences professionnelles »,
 * « Expérience fondatrice » (postes successifs d'une même entreprise, regroupés à l'affichage) ou
 * « Parcours terrain ». La note privée (ex. motif de fin de poste) n'est affichée que dans l'espace
 * recruteur et le CV PDF complet.
 *
 * Liens :
 * - Utilisée par : Admin\ExperienceController, ExperienceType
 * - Liée à : Skill (compétences mobilisées, côté propriétaire de la relation)
 * - Affichée par : CV public (lot 3)
 */
#[ORM\Entity(repositoryClass: ExperienceRepository::class)]
#[ORM\Table(name: 'experience')]
#[ORM\HasLifecycleCallbacks]
class Experience implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Intitulé du poste. */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $jobTitle = '';

    /**
     * Vrai nom de l'entreprise (privé si l'expérience est anonymisée). Vide pour une activité sans
     * employeur (ex. « Projet entrepreneurial & développement web ») : l'intitulé sert alors de titre.
     */
    #[ORM\Column(length: 160)]
    #[Assert\Length(max: 160)]
    private string $companyName = '';

    /** Vrai : le nom de l'entreprise est remplacé par publicCompanyLabel sur le site public. */
    #[ORM\Column(options: ['default' => false])]
    private bool $isCompanyAnonymized = false;

    /** Libellé public de remplacement (ex. « PME industrielle, 80 salariés »). */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $publicCompanyLabel = null;

    /** Précision sur l'entreprise (ex. « Ex-Beta Climat Industrie, rachetée en 02/2023 »). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $companyDetails = null;

    /** Secteur d'activité. */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $sector = null;

    /** Taille de l'entreprise (ex. « 80 salariés »). */
    #[ORM\Column(length: 80, nullable: true)]
    #[Assert\Length(max: 80)]
    private ?string $companySize = null;

    /** Lieu (ville ou région). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $location = null;

    /** Début du poste (seuls le mois et l'année sont affichés). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull]
    private ?\DateTimeImmutable $startDate = null;

    /** Fin du poste (null : poste actuel). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $endDate = null;

    /**
     * Vrai : seules les années sont connues (« 2017 – 2019 ») ; le mois n'est pas affiché,
     * pour ne pas laisser croire à une date précise.
     */
    #[ORM\Column(options: ['default' => false])]
    private bool $datesInYears = false;

    /** Résumé court, affiché avant le détail dépliable. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 1000)]
    private ?string $summary = null;

    /** Missions, une par ligne. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $missions = null;

    /** Réalisations chiffrées, une par ligne (verbe + quoi + résultat chiffré). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $achievements = null;

    /** Outils utilisés, un par ligne. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $tools = null;

    /**
     * Puces (réalisations ou missions) « Visible en public », par leur texte : les autres ne sont affichées que
     * dans l'espace recruteur et le CV complet (correction de recette n° 1, 2026-10-30).
     *
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON, options: ['default' => '[]'])]
    private array $publicItems = [];

    /** Rubrique du parcours (ordre du CV de référence). */
    #[ORM\Column(length: 20, enumType: CareerSection::class, options: ['default' => 'professional'])]
    #[Assert\NotNull]
    private CareerSection $careerSection = CareerSection::Professional;

    /** Note privée (ex. « Fin de mission en décembre 2025 ») : espace recruteur seulement. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    private ?string $privateNote = null;

    /** Vrai : visible sur le site public. */
    #[ORM\Column(options: ['default' => true])]
    private bool $isPublished = true;

    /** @var Collection<int, Skill> compétences mobilisées */
    #[ORM\ManyToMany(targetEntity: Skill::class, inversedBy: 'experiences')]
    #[ORM\JoinTable(name: 'experience_skill')]
    private Collection $skills;

    /** Initialise la collection des compétences liées. */
    public function __construct()
    {
        $this->skills = new ArrayCollection();
    }

    /**
     * Vérifie la cohérence : libellé public obligatoire si anonymisée, fin après début.
     *
     * Liens :
     * - Appelée par : le validateur Symfony (formulaire ExperienceType)
     *
     * @param ExecutionContextInterface $context contexte de validation
     */
    #[Assert\Callback]
    public function validateConsistency(ExecutionContextInterface $context): void
    {
        if ($this->isCompanyAnonymized && '' === trim((string) $this->publicCompanyLabel)) {
            $context->buildViolation('experience.public_company_label.required')
                ->atPath('publicCompanyLabel')
                ->addViolation();
        }

        if (null !== $this->startDate && null !== $this->endDate && $this->endDate < $this->startDate) {
            $context->buildViolation('experience.end_date.before_start')
                ->atPath('endDate')
                ->addViolation();
        }
    }

    /**
     * Renvoie le nom d'entreprise affichable publiquement (libellé de remplacement si anonymisée).
     *
     * Liens :
     * - Appelée par : templates de l'admin (aperçu), CV public (lot 3)
     *
     * @return string le libellé public ou le vrai nom
     */
    public function getPublicCompanyName(): string
    {
        return $this->isCompanyAnonymized ? (string) $this->publicCompanyLabel : $this->companyName;
    }

    /**
     * Découpe un champ « une ligne par élément » en liste, sans lignes vides.
     *
     * Liens :
     * - Appelée par : getMissionList(), getAchievementList(), getToolList()
     *
     * @param string|null $text texte saisi dans l'admin
     *
     * @return list<string> éléments nettoyés (liste vide si texte vide)
     */
    private static function splitLines(?string $text): array
    {
        $lines = preg_split('/\R/u', (string) $text) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $line): string => trim($line, " \t-•"), $lines),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie l'intitulé du poste. */
    public function getJobTitle(): string
    {
        return $this->jobTitle;
    }

    /** Définit l'intitulé du poste. */
    public function setJobTitle(?string $jobTitle): static
    {
        $this->jobTitle = (string) $jobTitle;

        return $this;
    }

    /** Renvoie le vrai nom de l'entreprise (donnée privée si anonymisée). */
    public function getCompanyName(): string
    {
        return $this->companyName;
    }

    /** Définit le vrai nom de l'entreprise. */
    public function setCompanyName(?string $companyName): static
    {
        $this->companyName = (string) $companyName;

        return $this;
    }

    /** Indique si le nom de l'entreprise est masqué sur le site public. */
    public function isCompanyAnonymized(): bool
    {
        return $this->isCompanyAnonymized;
    }

    /** Active ou désactive l'anonymisation. */
    public function setIsCompanyAnonymized(bool $isCompanyAnonymized): static
    {
        $this->isCompanyAnonymized = $isCompanyAnonymized;

        return $this;
    }

    /** Renvoie le libellé public de remplacement. */
    public function getPublicCompanyLabel(): ?string
    {
        return $this->publicCompanyLabel;
    }

    /** Définit le libellé public de remplacement. */
    public function setPublicCompanyLabel(?string $publicCompanyLabel): static
    {
        $this->publicCompanyLabel = $publicCompanyLabel;

        return $this;
    }

    /** Renvoie la précision sur l'entreprise. */
    public function getCompanyDetails(): ?string
    {
        return $this->companyDetails;
    }

    /** Définit la précision sur l'entreprise. */
    public function setCompanyDetails(?string $companyDetails): static
    {
        $this->companyDetails = $companyDetails;

        return $this;
    }

    /** Renvoie le secteur. */
    public function getSector(): ?string
    {
        return $this->sector;
    }

    /** Définit le secteur. */
    public function setSector(?string $sector): static
    {
        $this->sector = $sector;

        return $this;
    }

    /** Renvoie la taille de l'entreprise. */
    public function getCompanySize(): ?string
    {
        return $this->companySize;
    }

    /** Définit la taille de l'entreprise. */
    public function setCompanySize(?string $companySize): static
    {
        $this->companySize = $companySize;

        return $this;
    }

    /** Renvoie le lieu. */
    public function getLocation(): ?string
    {
        return $this->location;
    }

    /** Définit le lieu. */
    public function setLocation(?string $location): static
    {
        $this->location = $location;

        return $this;
    }

    /** Renvoie la date de début. */
    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    /** Définit la date de début. */
    public function setStartDate(?\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    /** Renvoie la date de fin (null : poste actuel). */
    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    /** Définit la date de fin (null : poste actuel). */
    public function setEndDate(?\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    /** Indique si c'est le poste actuel (pas de date de fin). */
    public function isCurrent(): bool
    {
        return null === $this->endDate;
    }

    /** Indique si seules les années des dates sont connues. */
    public function isDatesInYears(): bool
    {
        return $this->datesInYears;
    }

    /** Indique que seules les années sont connues (le mois ne sera pas affiché). */
    public function setDatesInYears(bool $datesInYears): static
    {
        $this->datesInYears = $datesInYears;

        return $this;
    }

    /**
     * Renvoie les réalisations chiffrées publiables : sans texte à compléter.
     *
     * Une ligne qui contient encore un repère « [À COMPLÉTER] », « [nb] »… n'est jamais
     * affichée publiquement ; l'admin la signale (ContentCompletenessChecker).
     *
     * Liens :
     * - Appelée par : templates/cv/*, templates/home/index.html.twig, PDF du CV
     * - Utilise : PlaceholderDetector::isComplete()
     *
     * @return list<string>
     */
    public function getPublicAchievementList(): array
    {
        return array_values(array_filter($this->getAchievementList(), PlaceholderDetector::isComplete(...)));
    }

    /**
     * Renvoie les missions publiables (sans texte à compléter).
     *
     * @return list<string>
     */
    public function getPublicMissionList(): array
    {
        return array_values(array_filter($this->getMissionList(), PlaceholderDetector::isComplete(...)));
    }

    /**
     * Indique si des réalisations restent à compléter (aucune, ou une ligne avec un repère).
     *
     * Liens :
     * - Appelée par : ContentCompletenessChecker, liste des expériences de l'admin
     */
    public function hasIncompleteAchievements(): bool
    {
        $achievements = $this->getAchievementList();

        return [] === $achievements || \count($achievements) !== \count($this->getPublicAchievementList());
    }

    /**
     * Indique si l'admin doit signaler des réalisations à compléter
     * (le « Parcours terrain » n'en demande pas).
     *
     * Liens :
     * - Appelée par : Admin\ExperienceController (colonne « Réalisations », chemin « missingAchievements »)
     */
    public function isMissingAchievements(): bool
    {
        return !$this->isFieldCareer() && $this->hasIncompleteAchievements();
    }

    /** Renvoie le résumé court. */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** Définit le résumé court. */
    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    /** Renvoie les missions (texte brut, une par ligne). */
    public function getMissions(): ?string
    {
        return $this->missions;
    }

    /** Définit les missions (une par ligne). */
    public function setMissions(?string $missions): static
    {
        $this->missions = $missions;

        return $this;
    }

    /**
     * Renvoie les missions sous forme de liste.
     *
     * @return list<string>
     */
    public function getMissionList(): array
    {
        return self::splitLines($this->missions);
    }

    /** Renvoie les réalisations chiffrées (texte brut, une par ligne). */
    public function getAchievements(): ?string
    {
        return $this->achievements;
    }

    /** Définit les réalisations chiffrées (une par ligne). */
    public function setAchievements(?string $achievements): static
    {
        $this->achievements = $achievements;

        return $this;
    }

    /**
     * Renvoie les réalisations chiffrées sous forme de liste.
     *
     * @return list<string>
     */
    public function getAchievementList(): array
    {
        return self::splitLines($this->achievements);
    }

    /** Renvoie les outils (texte brut, un par ligne). */
    public function getTools(): ?string
    {
        return $this->tools;
    }

    /** Définit les outils (un par ligne). */
    public function setTools(?string $tools): static
    {
        $this->tools = $tools;

        return $this;
    }

    /**
     * Renvoie les outils sous forme de liste.
     *
     * @return list<string>
     */
    public function getToolList(): array
    {
        return self::splitLines($this->tools);
    }

    /**
     * Renvoie toutes les puces de l'expérience, dans l'ordre d'affichage : réalisations, puis missions.
     *
     * Liens :
     * - Appelée par : ExperienceType (cases « Visible en public »), getPublicItems()
     *
     * @return list<string>
     */
    public function getItemList(): array
    {
        return [...$this->getAchievementList(), ...$this->getMissionList()];
    }

    /**
     * Renvoie les puces cochées « Visible en public » encore présentes dans l'expérience (une puce modifiée
     * ou supprimée n'est plus publique).
     *
     * Liens :
     * - Appelée par : CvContentProvider (CV public), ExperienceType, CvContentSynchronizer (comparaison)
     *
     * @return list<string>
     */
    public function getPublicItems(): array
    {
        return array_values(array_intersect($this->getItemList(), $this->publicItems));
    }

    /**
     * Coche les puces « Visible en public » (par leur texte).
     *
     * Liens :
     * - Appelée par : ExperienceType, CvContentSynchronizer (repère 🌐 de TEXTES_SITE.md)
     *
     * @param list<string> $publicItems textes des puces publiques
     */
    public function setPublicItems(array $publicItems): static
    {
        $this->publicItems = array_values(array_unique(array_map('trim', $publicItems)));

        return $this;
    }

    /**
     * Indique si une puce est visible en public.
     *
     * @param string $item texte de la puce
     */
    public function isItemPublic(string $item): bool
    {
        return \in_array($item, $this->getPublicItems(), true);
    }

    /** Renvoie la rubrique du parcours. */
    public function getCareerSection(): CareerSection
    {
        return $this->careerSection;
    }

    /** Range l'expérience dans une rubrique du parcours. */
    public function setCareerSection(CareerSection $careerSection): static
    {
        $this->careerSection = $careerSection;

        return $this;
    }

    /**
     * Indique si l'expérience fait partie du « Parcours terrain » (bloc regroupé, une ligne par poste).
     *
     * Liens :
     * - Appelée par : ContentCompletenessChecker (pas de réalisations chiffrées attendues)
     */
    public function isFieldCareer(): bool
    {
        return CareerSection::Field === $this->careerSection;
    }

    /** Renvoie la note privée (espace recruteur seulement). */
    public function getPrivateNote(): ?string
    {
        return $this->privateNote;
    }

    /** Définit la note privée (texte vide : aucune). */
    public function setPrivateNote(?string $privateNote): static
    {
        $this->privateNote = '' === trim((string) $privateNote) ? null : trim((string) $privateNote);

        return $this;
    }

    /** Indique si l'expérience est visible sur le site public. */
    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    /** Publie ou masque l'expérience. */
    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;

        return $this;
    }

    /**
     * Renvoie les compétences liées.
     *
     * @return Collection<int, Skill>
     */
    public function getSkills(): Collection
    {
        return $this->skills;
    }

    /** Ajoute une compétence liée. */
    public function addSkill(Skill $skill): static
    {
        if (!$this->skills->contains($skill)) {
            $this->skills->add($skill);
        }

        return $this;
    }

    /** Retire une compétence liée. */
    public function removeSkill(Skill $skill): static
    {
        $this->skills->removeElement($skill);

        return $this;
    }

    /** Texte affiché dans les listes de l'admin. */
    public function __toString(): string
    {
        return $this->jobTitle.' — '.$this->companyName;
    }
}
