<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\SortableTrait;
use App\Entity\Trait\TimestampTrait;
use App\Enum\ProjectType;
use App\Enum\ProjectVisibility;
use App\Repository\ProjectRepository;
use App\Service\Content\PlaceholderDetector;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Projet : étude de cas professionnelle ou projet personnel du Labo (CDC §6).
 *
 * Étude de cas : plan imposé (contexte, problème, solution, résultat, compétences
 * mobilisées) ; le client n'est désigné que par un libellé public (ex. « un laboratoire
 * pharmaceutique »), jamais par son nom.
 *
 * Règle de publication : un projet n'apparaît sur le site public que s'il est publié,
 * visible de tous ET complet (aucune section vide ni « à compléter ») — voir
 * isPubliclyVisible(). Les manques sont listés dans l'admin (ContentCompletenessChecker).
 *
 * Liens :
 * - Utilisée par : Admin\ProjectController, ProjectType (formulaire), ProjectController (public),
 *   HomeController (études de cas), ProjectPackage (export et import)
 * - Liée à : ProjectCategory (catégories), ProjectImage (galerie)
 */
#[ORM\Entity(repositoryClass: ProjectRepository::class)]
#[ORM\Table(name: 'project')]
#[ORM\HasLifecycleCallbacks]
#[UniqueEntity(fields: ['slug'], message: 'project.slug_taken')]
class Project implements SortableInterface
{
    use SortableTrait;
    use TimestampTrait;

    /** Sections du plan imposé des études de cas (champ => clé de traduction). */
    public const CASE_STUDY_SECTIONS = [
        'context' => 'project.section.context',
        'problem' => 'project.section.problem',
        'solution' => 'project.section.solution',
        'result' => 'project.section.result',
        'competences' => 'project.section.competences',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Étude de cas ou Labo. */
    #[ORM\Column(length: 20, enumType: ProjectType::class)]
    private ProjectType $type = ProjectType::CaseStudy;

    /** Titre. */
    #[ORM\Column(length: 160)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 160)]
    private string $title = '';

    /** Identifiant d'URL (/projets/{slug}) ; calculé à partir du titre s'il est laissé vide. */
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\Length(max: 180)]
    #[Assert\Regex(pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', message: 'project.slug_format')]
    private string $slug = '';

    /** Résumé court (cartes et introduction de la page). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 600)]
    private ?string $summary = null;

    /** Client désigné publiquement (ex. « Un laboratoire pharmaceutique ») ; jamais le vrai nom. */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $clientLabel = null;

    /** Étude de cas : contexte. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $context = null;

    /** Étude de cas : problème à résoudre. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $problem = null;

    /** Étude de cas : solution apportée. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $solution = null;

    /** Étude de cas : résultat chiffré. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 3000)]
    private ?string $result = null;

    /** Compétences mobilisées, une par ligne. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $competences = null;

    /** Contenu riche en Markdown (nettoyé à l'affichage par MarkdownRenderer). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 50000)]
    private ?string $content = null;

    /** Technologies, une par ligne. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $technologies = null;

    /** Date du projet (seuls le mois et l'année sont affichés). */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $projectDate = null;

    /** Image de couverture (WebP 1200 × 675 dans public/uploads/projects/). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $coverImage = null;

    /** Texte alternatif de la couverture. */
    #[ORM\Column(length: 160, nullable: true)]
    #[Assert\Length(max: 160)]
    private ?string $coverAlt = null;

    /** Lien vers le code source (Labo). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Url(protocols: ['https'], requireTld: true)]
    private ?string $repositoryUrl = null;

    /** Lien vers une démonstration (Labo). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Url(protocols: ['https'], requireTld: true)]
    private ?string $demoUrl = null;

    /** Vrai : publié (sinon brouillon). */
    #[ORM\Column(options: ['default' => false])]
    private bool $isPublished = false;

    /** Public ou recruteurs seulement. */
    #[ORM\Column(length: 20, enumType: ProjectVisibility::class, options: ['default' => 'public'])]
    private ProjectVisibility $visibility = ProjectVisibility::Public;

    /** Vrai : mis en avant (accueil, haut de liste). */
    #[ORM\Column(options: ['default' => false])]
    private bool $isFeatured = false;

    /** @var Collection<int, ProjectCategory> catégories du filtre */
    #[ORM\ManyToMany(targetEntity: ProjectCategory::class)]
    #[ORM\JoinTable(name: 'project_project_category')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $categories;

    /** @var Collection<int, ProjectImage> galerie */
    #[ORM\OneToMany(targetEntity: ProjectImage::class, mappedBy: 'project', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC', 'id' => 'ASC'])]
    #[Assert\Valid]
    private Collection $images;

    /** Initialise les collections. */
    public function __construct()
    {
        $this->categories = new ArrayCollection();
        $this->images = new ArrayCollection();
    }

    /**
     * Indique si le projet est visible dans l'espace recruteur : publié et complet, quelle que soit
     * sa visibilité (public ou réservé aux recruteurs).
     *
     * Liens :
     * - Appelée par : ProjectRepository::findRecruiterOnly(), ProjectRepository::findRecruiterVisibleBySlug()
     */
    public function isVisibleToRecruiters(): bool
    {
        return $this->isPublished && [] === $this->getMissingSections();
    }

    /**
     * Indique si le projet peut être affiché sur le site public.
     *
     * Liens :
     * - Appelée par : ProjectRepository::findPublic(), ProjectController::show()
     *
     * @return bool vrai si publié, visible de tous et complet
     */
    public function isPubliclyVisible(): bool
    {
        return $this->isPublished
            && ProjectVisibility::Public === $this->visibility
            && [] === $this->getMissingSections();
    }

    /**
     * Liste les sections vides ou « à compléter » (résumé, et plan imposé des études de cas).
     *
     * Liens :
     * - Appelée par : isPubliclyVisible(), ContentCompletenessChecker, admin (liste des projets)
     * - Utilise : PlaceholderDetector::isComplete()
     *
     * @return list<string> clés de traduction des sections manquantes
     */
    public function getMissingSections(): array
    {
        $missing = [];
        if (!PlaceholderDetector::isComplete($this->summary)) {
            $missing[] = 'project.section.summary';
        }

        if (ProjectType::CaseStudy === $this->type) {
            foreach (self::CASE_STUDY_SECTIONS as $field => $label) {
                if (!PlaceholderDetector::isComplete($this->{$field})) {
                    $missing[] = $label;
                }
            }
        }

        return $missing;
    }

    /**
     * Indique s'il manque au moins une section (colonne « à compléter » de l'admin).
     *
     * Liens :
     * - Appelée par : Admin\ProjectController (colonne de la liste)
     */
    public function isIncomplete(): bool
    {
        return [] !== $this->getMissingSections();
    }

    /**
     * Renvoie les compétences mobilisées, une par élément.
     *
     * @return list<string>
     */
    public function getCompetenceList(): array
    {
        return self::splitLines($this->competences);
    }

    /**
     * Renvoie les technologies, une par élément.
     *
     * @return list<string>
     */
    public function getTechnologyList(): array
    {
        return self::splitLines($this->technologies);
    }

    /**
     * Découpe un champ « une ligne par élément » en liste, sans lignes vides.
     *
     * Liens :
     * - Appelée par : getCompetenceList(), getTechnologyList()
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

    /** Renvoie le type. */
    public function getType(): ProjectType
    {
        return $this->type;
    }

    /** Définit le type. */
    public function setType(ProjectType $type): static
    {
        $this->type = $type;

        return $this;
    }

    /** Renvoie le titre. */
    public function getTitle(): string
    {
        return $this->title;
    }

    /** Définit le titre. */
    public function setTitle(?string $title): static
    {
        $this->title = trim((string) $title);

        return $this;
    }

    /** Renvoie l'identifiant d'URL. */
    public function getSlug(): string
    {
        return $this->slug;
    }

    /** Définit l'identifiant d'URL. */
    public function setSlug(?string $slug): static
    {
        $this->slug = trim((string) $slug);

        return $this;
    }

    /** Renvoie le résumé. */
    public function getSummary(): ?string
    {
        return $this->summary;
    }

    /** Définit le résumé. */
    public function setSummary(?string $summary): static
    {
        $this->summary = $summary;

        return $this;
    }

    /** Renvoie le libellé public du client. */
    public function getClientLabel(): ?string
    {
        return $this->clientLabel;
    }

    /** Définit le libellé public du client. */
    public function setClientLabel(?string $clientLabel): static
    {
        $this->clientLabel = $clientLabel;

        return $this;
    }

    /** Renvoie le contexte. */
    public function getContext(): ?string
    {
        return $this->context;
    }

    /** Définit le contexte. */
    public function setContext(?string $context): static
    {
        $this->context = $context;

        return $this;
    }

    /** Renvoie le problème. */
    public function getProblem(): ?string
    {
        return $this->problem;
    }

    /** Définit le problème. */
    public function setProblem(?string $problem): static
    {
        $this->problem = $problem;

        return $this;
    }

    /** Renvoie la solution. */
    public function getSolution(): ?string
    {
        return $this->solution;
    }

    /** Définit la solution. */
    public function setSolution(?string $solution): static
    {
        $this->solution = $solution;

        return $this;
    }

    /** Renvoie le résultat. */
    public function getResult(): ?string
    {
        return $this->result;
    }

    /** Définit le résultat. */
    public function setResult(?string $result): static
    {
        $this->result = $result;

        return $this;
    }

    /** Renvoie les compétences mobilisées (une par ligne). */
    public function getCompetences(): ?string
    {
        return $this->competences;
    }

    /** Définit les compétences mobilisées (une par ligne). */
    public function setCompetences(?string $competences): static
    {
        $this->competences = $competences;

        return $this;
    }

    /** Renvoie le contenu Markdown. */
    public function getContent(): ?string
    {
        return $this->content;
    }

    /** Définit le contenu Markdown. */
    public function setContent(?string $content): static
    {
        $this->content = $content;

        return $this;
    }

    /** Renvoie les technologies (une par ligne). */
    public function getTechnologies(): ?string
    {
        return $this->technologies;
    }

    /** Définit les technologies (une par ligne). */
    public function setTechnologies(?string $technologies): static
    {
        $this->technologies = $technologies;

        return $this;
    }

    /** Renvoie la date du projet. */
    public function getProjectDate(): ?\DateTimeImmutable
    {
        return $this->projectDate;
    }

    /** Définit la date du projet. */
    public function setProjectDate(?\DateTimeImmutable $projectDate): static
    {
        $this->projectDate = $projectDate;

        return $this;
    }

    /** Renvoie le nom du fichier de couverture. */
    public function getCoverImage(): ?string
    {
        return $this->coverImage;
    }

    /** Définit le nom du fichier de couverture. */
    public function setCoverImage(?string $coverImage): static
    {
        $this->coverImage = $coverImage;

        return $this;
    }

    /** Renvoie le texte alternatif de la couverture. */
    public function getCoverAlt(): ?string
    {
        return $this->coverAlt;
    }

    /** Définit le texte alternatif de la couverture. */
    public function setCoverAlt(?string $coverAlt): static
    {
        $this->coverAlt = $coverAlt;

        return $this;
    }

    /** Renvoie le lien vers le code source. */
    public function getRepositoryUrl(): ?string
    {
        return $this->repositoryUrl;
    }

    /** Définit le lien vers le code source. */
    public function setRepositoryUrl(?string $repositoryUrl): static
    {
        $this->repositoryUrl = $repositoryUrl;

        return $this;
    }

    /** Renvoie le lien vers la démonstration. */
    public function getDemoUrl(): ?string
    {
        return $this->demoUrl;
    }

    /** Définit le lien vers la démonstration. */
    public function setDemoUrl(?string $demoUrl): static
    {
        $this->demoUrl = $demoUrl;

        return $this;
    }

    /** Indique si le projet est publié. */
    public function isPublished(): bool
    {
        return $this->isPublished;
    }

    /** Publie ou dépublie le projet. */
    public function setIsPublished(bool $isPublished): static
    {
        $this->isPublished = $isPublished;

        return $this;
    }

    /** Renvoie la visibilité. */
    public function getVisibility(): ProjectVisibility
    {
        return $this->visibility;
    }

    /** Définit la visibilité. */
    public function setVisibility(ProjectVisibility $visibility): static
    {
        $this->visibility = $visibility;

        return $this;
    }

    /** Indique si le projet est mis en avant. */
    public function isFeatured(): bool
    {
        return $this->isFeatured;
    }

    /** Met en avant ou non le projet. */
    public function setIsFeatured(bool $isFeatured): static
    {
        $this->isFeatured = $isFeatured;

        return $this;
    }

    /** @return Collection<int, ProjectCategory> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    /** Ajoute une catégorie. */
    public function addCategory(ProjectCategory $category): static
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
        }

        return $this;
    }

    /** Retire une catégorie. */
    public function removeCategory(ProjectCategory $category): static
    {
        $this->categories->removeElement($category);

        return $this;
    }

    /** @return Collection<int, ProjectImage> */
    public function getImages(): Collection
    {
        return $this->images;
    }

    /** Ajoute une image à la galerie (en dernière position). */
    public function addImage(ProjectImage $image): static
    {
        if (!$this->images->contains($image)) {
            $last = 0;
            foreach ($this->images as $existing) {
                $last = max($last, $existing->getPosition());
            }
            $image->setProject($this)->setPosition($last + 1);
            $this->images->add($image);
        }

        return $this;
    }

    /** Retire une image de la galerie (supprimée en base par orphanRemoval). */
    public function removeImage(ProjectImage $image): static
    {
        if ($this->images->removeElement($image) && $image->getProject() === $this) {
            $image->setProject(null);
        }

        return $this;
    }

    /** Renvoie le titre (listes de l'admin, confirmations). */
    public function __toString(): string
    {
        return $this->title;
    }
}
