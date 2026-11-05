<?php

declare(strict_types=1);

namespace App\Entity;

use App\Entity\Trait\TestTraceTrait;
use App\Repository\UnansweredQuestionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Question d'un recruteur à laquelle l'assistant n'a pas pu répondre : information absente du site et des
 * fiches, ou réponse bloquée par le contrôle avant affichage. Gardée SANS donnée du recruteur (ni nom, ni
 * entreprise, ni lien vers sa fiche ; e-mails et téléphones déjà retirés de la question), pour que l'admin
 * crée une fiche (« Créer une fiche », pré-remplie) ou l'ignore.
 *
 * Liens :
 * - Créée par : App\Service\Ai\ChatAssistant::ask()
 * - Gérée par : Admin\AiKnowledgeController (unanswered, ignore, createFromQuestion)
 * - Comptée par : App\Service\Dashboard\DashboardMetrics (tuile « Cerveau de l'IA »)
 */
#[ORM\Entity(repositoryClass: UnansweredQuestionRepository::class)]
#[ORM\Table(name: 'unanswered_question')]
#[ORM\Index(name: 'idx_unanswered_question_status', columns: ['status'])]
class UnansweredQuestion implements TestTraceableInterface
{
    use TestTraceTrait;

    /** Information absente du site et des fiches. */
    public const REASON_MISSING = 'missing';

    /** Réponse bloquée par le contrôle avant affichage (élément absent du contexte). */
    public const REASON_BLOCKED = 'blocked';

    /** À traiter. */
    public const STATUS_PENDING = 'pending';

    /** Ignorée par l'admin. */
    public const STATUS_IGNORED = 'ignored';

    /** Une fiche a été créée à partir de la question. */
    public const STATUS_HANDLED = 'handled';

    /** Identifiant technique. */
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** État : à traiter, ignorée ou traitée. */
    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_PENDING;

    /**
     * Enregistre une question sans réponse.
     *
     * @param \DateTimeImmutable $createdAt      date de la question
     * @param string             $question       question (déjà sans e-mail ni téléphone)
     * @param string             $reason         self::REASON_*
     * @param string|null        $blockedElement élément qui a fait bloquer la réponse, ou null
     */
    public function __construct(
        #[ORM\Column]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::TEXT)]
        private string $question,
        #[ORM\Column(length: 20)]
        private string $reason = self::REASON_MISSING,
        #[ORM\Column(length: 120, nullable: true)]
        private ?string $blockedElement = null,
    ) {
    }

    /** Renvoie l'identifiant. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la date de la question. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie la question. */
    public function getQuestion(): string
    {
        return $this->question;
    }

    /** Renvoie le motif (self::REASON_*). */
    public function getReason(): string
    {
        return $this->reason;
    }

    /** Renvoie l'élément qui a fait bloquer la réponse, ou null. */
    public function getBlockedElement(): ?string
    {
        return $this->blockedElement;
    }

    /** Renvoie l'état (self::STATUS_*). */
    public function getStatus(): string
    {
        return $this->status;
    }

    /** Change l'état (self::STATUS_*). */
    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }
}
