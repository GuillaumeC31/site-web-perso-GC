<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\NotificationChannel;
use App\Enum\RemoteDecision;
use App\Repository\RemoteDecisionTokenRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton d'une décision à distance (bouton Telegram ou lien Discord) : lié à UNE demande et à UNE action,
 * à usage unique, valable 72 h. Seule l'empreinte SHA-256 du jeton est gardée : le jeton lui-même n'existe
 * que dans la notification envoyée.
 *
 * Telegram : l'appui sur « Accepter » ou « Refuser » envoie la demande du code PIN (message en réponse forcée,
 * telegramPromptMessageId ; message d'origine dans telegramMessageId) ; la réponse portant le bon code applique la
 * décision. Telegram et Discord : chaque code PIN faux compte un essai ; au 5e, le jeton est bloqué.
 *
 * Jeton d'essai (sans demande) : boutons et liens de la notification de test, qui suivent tout le parcours
 * (deux appuis, code PIN) sans rien décider.
 *
 * Liens :
 * - Créé par : Service\Recruiter\RemoteDecisionTokens::issue()
 * - Utilisé par : RemoteDecisionExecutor, TelegramWebhookController, RemoteDecisionController
 * - Supprimé avec le recruteur (clé étrangère en cascade)
 */
#[ORM\Entity(repositoryClass: RemoteDecisionTokenRepository::class)]
#[ORM\Table(name: 'remote_decision_token')]
class RemoteDecisionToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Date de blocage après trop de codes PIN faux (null : non bloqué). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $blockedAt = null;

    /** Date d'utilisation (null : pas encore utilisé). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $usedAt = null;

    /** Telegram : date du premier appui (confirmation demandée), null sinon. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $confirmRequestedAt = null;

    /** Telegram : message des boutons (modifié avec le résultat), null sinon. */
    #[ORM\Column(nullable: true)]
    private ?int $telegramMessageId = null;

    /** Telegram : message qui demande le code PIN (la réponse à ce message porte le code), null sinon. */
    #[ORM\Column(nullable: true)]
    private ?int $telegramPromptMessageId = null;

    /** Codes PIN faux saisis avec ce jeton (Telegram, Discord). */
    #[ORM\Column(options: ['default' => 0])]
    private int $failedAttempts = 0;

    /**
     * Crée un jeton (l'empreinte seulement).
     *
     * Liens :
     * - Appelée par : RemoteDecisionTokens::issue()
     *
     * @param Recruiter|null      $recruiter demande concernée ; null : jeton d'essai (notification de test)
     * @param RemoteDecision      $decision  action liée au jeton
     * @param NotificationChannel $channel   canal qui porte le jeton (Telegram ou Discord)
     * @param string              $tokenHash empreinte SHA-256 (hexadécimale) du jeton
     */
    public function __construct(
        #[ORM\ManyToOne(targetEntity: Recruiter::class)]
        #[ORM\JoinColumn(nullable: true, onDelete: 'CASCADE')]
        private ?Recruiter $recruiter,
        #[ORM\Column(length: 10, enumType: RemoteDecision::class)]
        private RemoteDecision $decision,
        #[ORM\Column(length: 10, enumType: NotificationChannel::class)]
        private NotificationChannel $channel,
        #[ORM\Column(length: 64, unique: true)]
        private string $tokenHash,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private \DateTimeImmutable $expiresAt,
    ) {
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la demande concernée (null : jeton d'essai). */
    public function getRecruiter(): ?Recruiter
    {
        return $this->recruiter;
    }

    /** Indique si le jeton est un essai (notification de test, aucune demande). */
    public function isTrial(): bool
    {
        return null === $this->recruiter;
    }

    /** Renvoie l'action liée au jeton. */
    public function getDecision(): RemoteDecision
    {
        return $this->decision;
    }

    /** Renvoie le canal qui porte le jeton. */
    public function getChannel(): NotificationChannel
    {
        return $this->channel;
    }

    /** Renvoie la date de création. */
    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** Renvoie la date d'expiration (création + 72 h). */
    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /** Indique si le jeton a expiré à la date donnée. */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    /** Renvoie la date d'utilisation (null : pas encore utilisé). */
    public function getUsedAt(): ?\DateTimeImmutable
    {
        return $this->usedAt;
    }

    /** Marque le jeton comme utilisé (il ne servira plus). */
    public function markUsed(\DateTimeImmutable $now): static
    {
        $this->usedAt = $now;

        return $this;
    }

    /** Renvoie la date du premier appui Telegram (null : aucune confirmation demandée). */
    public function getConfirmRequestedAt(): ?\DateTimeImmutable
    {
        return $this->confirmRequestedAt;
    }

    /** Note ou annule la demande de confirmation (Telegram). */
    public function setConfirmRequestedAt(?\DateTimeImmutable $confirmRequestedAt): static
    {
        $this->confirmRequestedAt = $confirmRequestedAt;

        return $this;
    }

    /** Renvoie le message Telegram des boutons (null : aucun). */
    public function getTelegramMessageId(): ?int
    {
        return $this->telegramMessageId;
    }

    /** Renvoie le message Telegram qui demande le code PIN (null : aucun). */
    public function getTelegramPromptMessageId(): ?int
    {
        return $this->telegramPromptMessageId;
    }

    /**
     * Note la demande du code PIN sur Telegram : message des boutons et message de la demande.
     *
     * Liens :
     * - Appelée par : TelegramCallbackHandler
     */
    public function setTelegramPrompt(?int $messageId, ?int $promptMessageId): static
    {
        $this->telegramMessageId = $messageId;
        $this->telegramPromptMessageId = $promptMessageId;

        return $this;
    }

    /** Renvoie le nombre de codes PIN faux. */
    public function getFailedAttempts(): int
    {
        return $this->failedAttempts;
    }

    /**
     * Compte un code PIN faux ; bloque le lien au-delà du nombre d'essais permis.
     *
     * Liens :
     * - Appelée par : RemoteDecisionExecutor::executeWithPin()
     *
     * @param int $maxAttempts essais permis (5)
     *
     * @return int essais restants (0 : lien bloqué)
     */
    public function recordFailedAttempt(\DateTimeImmutable $now, int $maxAttempts): int
    {
        ++$this->failedAttempts;
        if ($this->failedAttempts >= $maxAttempts) {
            $this->blockedAt = $now;
        }

        return max(0, $maxAttempts - $this->failedAttempts);
    }

    /** Indique si le lien est bloqué (trop de codes PIN faux). */
    public function isBlocked(): bool
    {
        return null !== $this->blockedAt;
    }
}
