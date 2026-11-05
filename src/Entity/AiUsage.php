<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TestTraceTrait;
use App\Enum\AiPurpose;
use App\Repository\AiUsageRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Un appel au fournisseur d'IA, avec les jetons réels renvoyés par l'API (usage) : base du plafond
 * mensuel et de la consommation affichée dans l'admin. Aucun contenu (ni question, ni réponse, ni clé).
 *
 * Liens :
 * - Créée par : AiUsageRecorder
 * - Lue par : AiUsageRepository (consommation du jour et du mois), AiStatus, admin
 * - Purgée par : app:data:purge (12 mois)
 */
#[ORM\Entity(repositoryClass: AiUsageRepository::class)]
#[ORM\Index(name: 'idx_ai_usage_created_at', columns: ['created_at'])]
class AiUsage implements TestTraceableInterface
{
    use TestTraceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /**
     * Enregistre un appel.
     *
     * @param \DateTimeImmutable $createdAt        date de l'appel
     * @param AiPurpose          $purpose          usage (chatbot, import de CV…)
     * @param string             $model            modèle appelé
     * @param int                $promptTokens     jetons envoyés
     * @param int                $completionTokens jetons de la réponse
     * @param string|null        $error            motif d'erreur (AiProviderException::reason), null si succès
     */
    public function __construct(
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(length: 30, enumType: AiPurpose::class)]
        private AiPurpose $purpose,
        #[ORM\Column(length: 80)]
        private string $model,
        #[ORM\Column]
        private int $promptTokens = 0,
        #[ORM\Column]
        private int $completionTokens = 0,
        #[ORM\Column(length: 40, nullable: true)]
        private ?string $error = null,
    ) {
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la date de l'appel. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie l'usage. */
    public function getPurpose(): AiPurpose
    {
        return $this->purpose;
    }

    /** Renvoie le modèle appelé. */
    public function getModel(): string
    {
        return $this->model;
    }

    /** Renvoie les jetons envoyés. */
    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    /** Renvoie les jetons de la réponse. */
    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    /** Renvoie le motif d'erreur (null si succès). */
    public function getError(): ?string
    {
        return $this->error;
    }
}
