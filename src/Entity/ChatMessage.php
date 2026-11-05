<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TestTraceTrait;
use App\Enum\ChatOutcome;
use App\Repository\ChatMessageRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des conversations avec l'assistant de l'espace recruteur : question (déjà filtrée : ni e-mail ni
 * téléphone), réponse affichée, issue, modèle utilisé, jetons, erreur, détournement suspecté.
 *
 * Supprimé avec le recruteur (CASCADE) et après 12 mois (app:data:purge). Les essais de l'admin (banc
 * d'essai du « Cerveau de l'IA ») n'ont pas de recruteur et ne comptent pas dans les plafonds de questions.
 *
 * Liens :
 * - Créé par : ChatAssistant
 * - Lu par : RecruiterAreaController (historique du recruteur), Admin\AssistantController (journal),
 *   ChatMessageRepository (plafonds du jour)
 */
#[ORM\Entity(repositoryClass: ChatMessageRepository::class)]
#[ORM\Index(name: 'idx_chat_message_created_at', columns: ['created_at'])]
class ChatMessage implements TestTraceableInterface
{
    use TestTraceTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Modèle d'IA qui a répondu (null : assistant guidé ou refus). */
    #[ORM\Column(length: 80, nullable: true)]
    private ?string $model = null;

    /** Jetons envoyés (consignes, contenu public, question). */
    #[ORM\Column]
    private int $promptTokens = 0;

    /** Jetons de la réponse. */
    #[ORM\Column]
    private int $completionTokens = 0;

    /** Durée de l'appel au modèle (millisecondes). */
    #[ORM\Column(nullable: true)]
    private ?int $durationMs = null;

    /** Erreurs du fournisseur rencontrées (ex. « principal : timeout ; secours : rate_limited »), sans clé ni contenu. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $error = null;

    /** Motif d'un détournement suspecté (consignes demandées, changement de rôle…), null sinon. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $suspiciousReason = null;

    /** Élément de la réponse absent du contexte (date, nombre, nom…) : réponse bloquée et remplacée ; null sinon. */
    #[ORM\Column(length: 120, nullable: true)]
    private ?string $blockedElement = null;

    /** Vrai pour un essai de l'admin (banc d'essai) : hors plafonds des recruteurs. */
    #[ORM\Column(options: ['default' => false])]
    private bool $adminTest = false;

    /**
     * Crée une entrée du journal.
     *
     * @param Recruiter|null     $recruiter recruteur connecté ; null pour un essai de l'admin
     * @param \DateTimeImmutable $createdAt date de la question
     * @param string             $question  question (filtrée) ou intitulé de la question guidée
     * @param string             $answer    réponse affichée
     * @param ChatOutcome        $outcome   issue
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Recruiter::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Recruiter $recruiter,
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::TEXT)]
        private string $question,
        #[ORM\Column(type: Types::TEXT)]
        private string $answer,
        #[ORM\Column(length: 30, enumType: ChatOutcome::class)]
        private ChatOutcome $outcome,
    ) {
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le recruteur (null pour un essai de l'admin). */
    public function getRecruiter(): ?Recruiter
    {
        return $this->recruiter;
    }

    /** Renvoie la date de la question. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie la question (filtrée). */
    public function getQuestion(): string
    {
        return $this->question;
    }

    /** Renvoie la réponse affichée. */
    public function getAnswer(): string
    {
        return $this->answer;
    }

    /** Renvoie l'issue. */
    public function getOutcome(): ChatOutcome
    {
        return $this->outcome;
    }

    /** Renvoie le modèle qui a répondu. */
    public function getModel(): ?string
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

    /** Renvoie le total des jetons. */
    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    /** Renvoie la durée de l'appel. */
    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /** Renvoie les erreurs du fournisseur rencontrées. */
    public function getError(): ?string
    {
        return $this->error;
    }

    /** Renvoie le motif du détournement suspecté. */
    public function getSuspiciousReason(): ?string
    {
        return $this->suspiciousReason;
    }

    /** Indique si un détournement est suspecté. */
    public function isSuspicious(): bool
    {
        return null !== $this->suspiciousReason;
    }

    /**
     * Renseigne la réponse du modèle (modèle, jetons, durée).
     */
    public function setAiResult(string $model, int $promptTokens, int $completionTokens, int $durationMs): static
    {
        $this->model = $model;
        $this->promptTokens = $promptTokens;
        $this->completionTokens = $completionTokens;
        $this->durationMs = $durationMs;

        return $this;
    }

    /** Renseigne les erreurs rencontrées (texte court, sans clé ni contenu). */
    public function setError(?string $error): static
    {
        $this->error = null === $error ? null : mb_substr($error, 0, 255);

        return $this;
    }

    /** Signale un détournement suspecté. */
    public function setSuspiciousReason(?string $reason): static
    {
        $this->suspiciousReason = null === $reason ? null : mb_substr($reason, 0, 120);

        return $this;
    }

    /** Renvoie l'élément qui a fait bloquer la réponse, ou null. */
    public function getBlockedElement(): ?string
    {
        return $this->blockedElement;
    }

    /** Indique si la réponse du modèle a été bloquée par le contrôle avant affichage. */
    public function isBlocked(): bool
    {
        return null !== $this->blockedElement;
    }

    /** Signale une réponse bloquée et l'élément fautif. */
    public function setBlockedElement(?string $element): static
    {
        $this->blockedElement = null === $element ? null : mb_substr($element, 0, 120);

        return $this;
    }

    /** Indique un essai de l'admin (banc d'essai). */
    public function isAdminTest(): bool
    {
        return $this->adminTest;
    }

    /** Marque l'échange comme essai de l'admin. */
    public function setAdminTest(bool $adminTest): static
    {
        $this->adminTest = $adminTest;

        return $this;
    }

    /**
     * Indique si la réponse est « Ce point n'est pas précisé… » (information absente ou réponse bloquée) :
     * l'assistant affiche alors le lien vers le formulaire de question de l'espace recruteur.
     *
     * Liens :
     * - Appelée par : templates/recruiter_area/_assistant.html.twig
     */
    public function isNotSpecified(): bool
    {
        return $this->outcome->isAi() && \App\Service\Ai\ChatAssistant::isNotSpecifiedAnswer($this->answer);
    }
}
