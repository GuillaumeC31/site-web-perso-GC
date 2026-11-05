<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TimestampTrait;
use App\Enum\AiKnowledgeCategory;
use App\Repository\AiKnowledgeEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Fiche de connaissances de l'assistant IA de l'espace recruteur (page « Cerveau de l'IA ») : complète le
 * résumé automatique du site dans le contexte envoyé à Mistral AI, après le socle de sécurité fixe.
 *
 * Réservée à l'assistant : jamais affichée en public, jamais dans les PDF. Une fiche ne peut contenir ni
 * coordonnées, ni adresse, ni plancher de rémunération, ni donnée de santé (AiKnowledgeValidator).
 *
 * Liens :
 * - Gérée par : Admin\AiKnowledgeController ; importée par Command\AiImportDirectivesCommand
 * - Lue par : ChatContextBuilder (fiches actives), AiKnowledgeBudget (jetons)
 * - Historique : AiKnowledgeRevision
 */
#[ORM\Entity(repositoryClass: AiKnowledgeEntryRepository::class)]
#[ORM\Table(name: 'ai_knowledge_entry')]
#[ORM\HasLifecycleCallbacks]
class AiKnowledgeEntry
{
    use TimestampTrait;

    /** Identifiant technique. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Titre (repère pour l'admin, envoyé aussi au modèle). */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $title = '';

    /** Contenu envoyé à Mistral AI. */
    #[ORM\Column(type: Types::TEXT)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 4000)]
    private string $content = '';

    /** Catégorie. */
    #[ORM\Column(length: 30, enumType: AiKnowledgeCategory::class)]
    private AiKnowledgeCategory $category = AiKnowledgeCategory::Faq;

    /** Vrai : envoyée à l'assistant ; faux : brouillon. */
    #[ORM\Column]
    private bool $active = false;

    /** Ordre dans sa catégorie (croissant). */
    #[ORM\Column]
    #[Assert\PositiveOrZero]
    private int $position = 0;

    /** Renvoie l'identifiant (null avant l'enregistrement). */
    public function getId(): ?int
    {
        return $this->id;
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

    /** Renvoie le contenu. */
    public function getContent(): string
    {
        return $this->content;
    }

    /** Définit le contenu. */
    public function setContent(?string $content): static
    {
        $this->content = trim(str_replace("\r\n", "\n", (string) $content));

        return $this;
    }

    /** Renvoie la catégorie. */
    public function getCategory(): AiKnowledgeCategory
    {
        return $this->category;
    }

    /** Définit la catégorie. */
    public function setCategory(AiKnowledgeCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    /** Indique si la fiche est envoyée à l'assistant. */
    public function isActive(): bool
    {
        return $this->active;
    }

    /** Active ou désactive la fiche. */
    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    /** Renvoie l'ordre dans la catégorie. */
    public function getPosition(): int
    {
        return $this->position;
    }

    /** Définit l'ordre dans la catégorie. */
    public function setPosition(?int $position): static
    {
        $this->position = max(0, (int) $position);

        return $this;
    }

    /**
     * Renvoie l'état comparable de la fiche (historique : une révision n'est créée que s'il change).
     *
     * @return array{title: string, content: string, category: string, active: bool}
     */
    public function snapshot(): array
    {
        return ['title' => $this->title, 'content' => $this->content, 'category' => $this->category->value, 'active' => $this->active];
    }
}
