<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\AiKnowledgeCategory;
use App\Repository\AiKnowledgeRevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ancienne version d'une fiche de connaissances (historique simple : quand, ancien contenu), créée à
 * chaque modification réelle de la fiche ; supprimée avec elle.
 *
 * Liens :
 * - Créée par : Admin\AiKnowledgeController::edit()
 * - Affichée par : templates/admin/ai_knowledge/history.html.twig
 */
#[ORM\Entity(repositoryClass: AiKnowledgeRevisionRepository::class)]
#[ORM\Table(name: 'ai_knowledge_revision')]
class AiKnowledgeRevision
{
    /** Identifiant technique. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Titre avant la modification. */
    #[ORM\Column(length: 120)]
    private string $title;

    /** Contenu avant la modification. */
    #[ORM\Column(type: Types::TEXT)]
    private string $content;

    /** Catégorie avant la modification. */
    #[ORM\Column(length: 30, enumType: AiKnowledgeCategory::class)]
    private AiKnowledgeCategory $category;

    /** État actif avant la modification. */
    #[ORM\Column]
    private bool $active;

    /**
     * Garde l'état d'une fiche juste avant sa modification.
     *
     * @param AiKnowledgeEntry                                                      $entry     fiche concernée
     * @param \DateTimeImmutable                                                    $changedAt date de la modification
     * @param array{title: string, content: string, category: string, active: bool} $before    état précédent (AiKnowledgeEntry::snapshot())
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: AiKnowledgeEntry::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private AiKnowledgeEntry $entry,
        #[ORM\Column]
        private \DateTimeImmutable $changedAt,
        array $before,
    ) {
        $this->title = $before['title'];
        $this->content = $before['content'];
        $this->category = AiKnowledgeCategory::from($before['category']);
        $this->active = $before['active'];
    }

    /** Renvoie l'identifiant. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la fiche concernée. */
    public function getEntry(): AiKnowledgeEntry
    {
        return $this->entry;
    }

    /** Renvoie la date de la modification. */
    public function getChangedAt(): \DateTimeImmutable
    {
        return $this->changedAt;
    }

    /** Renvoie le titre avant la modification. */
    public function getTitle(): string
    {
        return $this->title;
    }

    /** Renvoie le contenu avant la modification. */
    public function getContent(): string
    {
        return $this->content;
    }

    /** Renvoie la catégorie avant la modification. */
    public function getCategory(): AiKnowledgeCategory
    {
        return $this->category;
    }

    /** Indique si la fiche était active avant la modification. */
    public function isActive(): bool
    {
        return $this->active;
    }
}
