<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecruiterStatus;
use App\Repository\RecruiterRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Recruiter : demande d'accès à l'espace recruteur, puis compte connecté par lien magique (CDC §7).
 *
 * Cycle : demande (en attente) → validée par l'admin (accès de 90 jours, renouvelable) ou refusée ;
 * révocation possible ; échéance gérée par la commande app:recruiter:daily (rappel 7 jours avant).
 * Aucun mot de passe : la connexion passe par un lien de connexion (login_link) de 30 minutes, à
 * usage unique. Les propriétés signées (identifiant, statut, échéance, dernière connexion) rendent
 * caducs les liens en circulation dès qu'elles changent.
 *
 * Liens :
 * - Utilisée par : RecruiterAccessManager, RecruiterAreaController, Admin\RecruiterController,
 *   RecruiterUserChecker, RecruiterDailyCommand, DataPurgeCommand
 */
#[ORM\Entity(repositoryClass: RecruiterRepository::class)]
#[ORM\Table(name: 'recruiter')]
// Unicité contrôlée seulement dans l'admin : le formulaire public ne doit pas révéler une adresse connue
#[UniqueEntity(fields: ['email'], groups: ['admin'])]
class Recruiter implements UserInterface
{
    /** Durée d'un accès validé (jours), renouvelable depuis l'admin. */
    public const ACCESS_DAYS = 90;

    /** Rappel envoyé ce nombre de jours avant l'échéance. */
    public const REMINDER_DAYS = 7;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** E-mail professionnel (en minuscules), identifiant de connexion. */
    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email = '';

    /** Nom et prénom. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $fullName = '';

    /** Entreprise ou cabinet. */
    #[ORM\Column(length: 120)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 120)]
    private string $company = '';

    /** Fonction du demandeur (facultative : « Chargée de recrutement »). */
    #[ORM\Column(length: 120, nullable: true)]
    #[Assert\Length(max: 120)]
    private ?string $jobTitle = null;

    /** Site web de l'entreprise (facultatif ; http ou https seulement, jamais rendu cliquable dans les notifications). */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Assert\Url(protocols: ['http', 'https'], requireTld: true)]
    private ?string $companyWebsite = null;

    /** Message facultatif joint à la demande. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 2000)]
    private ?string $message = null;

    #[ORM\Column(length: 20, enumType: RecruiterStatus::class)]
    private RecruiterStatus $status = RecruiterStatus::Pending;

    /** Score d'aide à la décision (RecruiterScorer), jamais une validation à lui seul par défaut. */
    #[ORM\Column]
    private int $score = 0;

    /**
     * Détail des contrôles du score.
     *
     * @var list<array{step: string, label: string, result: string, level: string}>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $scoringDetails = [];

    /** Adresse IP de la demande (score, limite d'envoi), effacée après 30 jours (app:data:purge). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    /** Date du consentement au traitement des données (case du formulaire). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $consentAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $requestedAt;

    /** Date de la validation, du refus ou de la révocation. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    /** Fin de l'accès validé. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $accessExpiresAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $lastLoginAt = null;

    /** Rappel d'échéance déjà envoyé pour l'échéance en cours. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $reminderSentAt = null;

    /** Rappels d'échéance refusés par le recruteur (lien de l'e-mail de rappel, RGPD) : plus aucun rappel. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $remindersStoppedAt = null;

    /**
     * Dernier e-mail « Votre demande est déjà en cours d'examen » (nouvelle demande envoyée alors que la
     * précédente attend encore) : un au plus par 24 h et par adresse.
     */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $pendingNoticeSentAt = null;

    /** Prolongation demandée par le recruteur (lien de l'e-mail de rappel). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $extensionRequestedAt = null;

    /** Dernière activité (demande, décision, connexion) : point de départ de la durée de conservation. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastActivityAt;

    /** Compte créé à la main dans l'admin (sans demande). */
    #[ORM\Column]
    private bool $createdManually = false;

    /** Consentement aux questions libres à l'assistant IA (Mistral AI), null tant que non donné. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $aiConsentAt = null;

    /** Synthèse de l'entreprise générée par l'IA dans l'admin (Type, Secteur, Activité, Crédibilité). */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $companySynthesis = null;

    /** Date de la synthèse de l'entreprise. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $companySynthesisAt = null;

    /**
     * Crée une demande à la date donnée.
     *
     * @param \DateTimeImmutable $now date de la demande
     */
    public function __construct(\DateTimeImmutable $now)
    {
        $this->requestedAt = $now;
        $this->lastActivityAt = $now;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie l'e-mail. */
    public function getEmail(): string
    {
        return $this->email;
    }

    /** Définit l'e-mail (mis en minuscules, sans espaces). */
    public function setEmail(?string $email): static
    {
        $this->email = mb_strtolower(trim((string) $email));

        return $this;
    }

    /** Renvoie le nom. */
    public function getFullName(): string
    {
        return $this->fullName;
    }

    /** Définit le nom. */
    public function setFullName(?string $fullName): static
    {
        $this->fullName = trim((string) $fullName);

        return $this;
    }

    /** Renvoie l'entreprise. */
    public function getCompany(): string
    {
        return $this->company;
    }

    /** Définit l'entreprise. */
    public function setCompany(?string $company): static
    {
        $this->company = trim((string) $company);

        return $this;
    }

    /** Renvoie la fonction du demandeur (null : non renseignée). */
    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    /** Définit la fonction du demandeur (vide : null). */
    public function setJobTitle(?string $jobTitle): static
    {
        $jobTitle = trim((string) $jobTitle);
        $this->jobTitle = '' === $jobTitle ? null : $jobTitle;

        return $this;
    }

    /** Renvoie le site web de l'entreprise (null : non renseigné). */
    public function getCompanyWebsite(): ?string
    {
        return $this->companyWebsite;
    }

    /** Définit le site web de l'entreprise (vide : null). */
    public function setCompanyWebsite(?string $companyWebsite): static
    {
        $companyWebsite = trim((string) $companyWebsite);
        $this->companyWebsite = '' === $companyWebsite ? null : $companyWebsite;

        return $this;
    }

    /**
     * Renvoie le domaine de l'adresse e-mail (« @entreprise.fr »), seule partie de l'adresse reprise dans les
     * notifications.
     *
     * Liens :
     * - Appelée par : Service\Recruiter\AccessRequestSummary
     */
    public function getEmailDomain(): string
    {
        $at = strrpos($this->email, '@');

        return false === $at ? '' : mb_strtolower(substr($this->email, $at));
    }

    /** Renvoie le message facultatif. */
    public function getMessage(): ?string
    {
        return $this->message;
    }

    /** Définit le message facultatif (vide : null). */
    public function setMessage(?string $message): static
    {
        $message = trim((string) $message);
        $this->message = '' === $message ? null : $message;

        return $this;
    }

    /** Renvoie le statut. */
    public function getStatus(): RecruiterStatus
    {
        return $this->status;
    }

    /** Renvoie la valeur du statut (propriété signée du lien de connexion). */
    public function getStatusValue(): string
    {
        return $this->status->value;
    }

    /** Définit le statut. */
    public function setStatus(RecruiterStatus $status): static
    {
        $this->status = $status;

        return $this;
    }

    /** Renvoie le score. */
    public function getScore(): int
    {
        return $this->score;
    }

    /**
     * Enregistre le score et son détail.
     *
     * @param list<array{step: string, label: string, result: string, level: string}> $details
     */
    public function setScoring(int $score, array $details): static
    {
        $this->score = $score;
        $this->scoringDetails = $details;

        return $this;
    }

    /**
     * Renvoie le détail du score.
     *
     * @return list<array{step: string, label: string, result: string, level: string}>
     */
    public function getScoringDetails(): array
    {
        return $this->scoringDetails;
    }

    /** Renvoie l'adresse IP de la demande (null après 30 jours). */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /** Définit l'adresse IP de la demande. */
    public function setIpAddress(?string $ipAddress): static
    {
        $this->ipAddress = $ipAddress;

        return $this;
    }

    /** Renvoie la date du consentement. */
    public function getConsentAt(): ?\DateTimeImmutable
    {
        return $this->consentAt;
    }

    /** Définit la date du consentement. */
    public function setConsentAt(?\DateTimeImmutable $consentAt): static
    {
        $this->consentAt = $consentAt;

        return $this;
    }

    /** Renvoie la date de la demande. */
    public function getRequestedAt(): \DateTimeImmutable
    {
        return $this->requestedAt;
    }

    /** Renvoie la date de la dernière décision. */
    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    /** Définit la date de la dernière décision. */
    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    /** Renvoie la fin de l'accès. */
    public function getAccessExpiresAt(): ?\DateTimeImmutable
    {
        return $this->accessExpiresAt;
    }

    /** Renvoie la fin de l'accès en secondes (propriété signée du lien de connexion). */
    public function getAccessExpiresAtTimestamp(): int
    {
        return $this->accessExpiresAt?->getTimestamp() ?? 0;
    }

    /** Définit la fin de l'accès. */
    public function setAccessExpiresAt(?\DateTimeImmutable $accessExpiresAt): static
    {
        $this->accessExpiresAt = $accessExpiresAt;

        return $this;
    }

    /** Renvoie la date de la dernière connexion. */
    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    /** Renvoie la dernière connexion en secondes (propriété signée : le lien ne sert qu'une fois). */
    public function getLastLoginAtTimestamp(): int
    {
        return $this->lastLoginAt?->getTimestamp() ?? 0;
    }

    /** Définit la date de la dernière connexion. */
    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): static
    {
        $this->lastLoginAt = $lastLoginAt;

        return $this;
    }

    /** Renvoie la date d'envoi du rappel d'échéance. */
    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    /** Définit la date d'envoi du rappel d'échéance. */
    public function setReminderSentAt(?\DateTimeImmutable $reminderSentAt): static
    {
        $this->reminderSentAt = $reminderSentAt;

        return $this;
    }

    /** Renvoie la date à laquelle le recruteur a refusé les rappels d'échéance, ou null (rappels envoyés). */
    public function getRemindersStoppedAt(): ?\DateTimeImmutable
    {
        return $this->remindersStoppedAt;
    }

    /** Indique si le recruteur accepte encore les rappels d'échéance. */
    public function acceptsReminders(): bool
    {
        return null === $this->remindersStoppedAt;
    }

    /** Note le refus des rappels d'échéance (lien « Ne plus recevoir ces rappels »). */
    public function setRemindersStoppedAt(?\DateTimeImmutable $remindersStoppedAt): static
    {
        $this->remindersStoppedAt = $remindersStoppedAt;

        return $this;
    }

    /** Renvoie la date du dernier e-mail « demande déjà en cours d'examen », ou null. */
    public function getPendingNoticeSentAt(): ?\DateTimeImmutable
    {
        return $this->pendingNoticeSentAt;
    }

    /** Définit la date du dernier e-mail « demande déjà en cours d'examen ». */
    public function setPendingNoticeSentAt(?\DateTimeImmutable $pendingNoticeSentAt): static
    {
        $this->pendingNoticeSentAt = $pendingNoticeSentAt;

        return $this;
    }

    /** Renvoie la date de la demande de prolongation. */
    public function getExtensionRequestedAt(): ?\DateTimeImmutable
    {
        return $this->extensionRequestedAt;
    }

    /** Définit la date de la demande de prolongation. */
    public function setExtensionRequestedAt(?\DateTimeImmutable $extensionRequestedAt): static
    {
        $this->extensionRequestedAt = $extensionRequestedAt;

        return $this;
    }

    /** Renvoie la date de la dernière activité. */
    public function getLastActivityAt(): \DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    /** Note une activité (point de départ de la durée de conservation). */
    public function touch(\DateTimeImmutable $now): static
    {
        $this->lastActivityAt = $now;

        return $this;
    }

    /** Renvoie la date du consentement à l'assistant IA (null : pas encore donné). */
    public function getAiConsentAt(): ?\DateTimeImmutable
    {
        return $this->aiConsentAt;
    }

    /** Enregistre le consentement à l'assistant IA. */
    public function setAiConsentAt(?\DateTimeImmutable $aiConsentAt): static
    {
        $this->aiConsentAt = $aiConsentAt;

        return $this;
    }

    /** Renvoie la synthèse de l'entreprise (null : pas encore générée). */
    public function getCompanySynthesis(): ?string
    {
        return $this->companySynthesis;
    }

    /** Renvoie la date de la synthèse de l'entreprise. */
    public function getCompanySynthesisAt(): ?\DateTimeImmutable
    {
        return $this->companySynthesisAt;
    }

    /**
     * Enregistre la synthèse de l'entreprise et sa date.
     *
     * Liens :
     * - Appelée par : CompanySynthesizer::synthesize()
     */
    public function setCompanySynthesis(?string $companySynthesis, ?\DateTimeImmutable $at): static
    {
        $this->companySynthesis = $companySynthesis;
        $this->companySynthesisAt = $at;

        return $this;
    }

    /** Indique si le compte a été créé à la main. */
    public function isCreatedManually(): bool
    {
        return $this->createdManually;
    }

    /** Définit si le compte a été créé à la main. */
    public function setCreatedManually(bool $createdManually): static
    {
        $this->createdManually = $createdManually;

        return $this;
    }

    /**
     * Indique si l'accès est ouvert à cette date : validé et non échu.
     *
     * Liens :
     * - Appelée par : RecruiterUserChecker, RecruiterAccessManager::sendLoginLink()
     */
    public function hasActiveAccess(\DateTimeImmutable $now): bool
    {
        return RecruiterStatus::Approved === $this->status && null !== $this->accessExpiresAt && $this->accessExpiresAt > $now;
    }

    /** Renvoie l'identifiant de connexion (l'e-mail). */
    public function getUserIdentifier(): string
    {
        return '' === $this->email ? 'recruteur' : $this->email;
    }

    /**
     * Renvoie les rôles : ROLE_RECRUITER seulement (jamais d'accès à l'admin).
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return ['ROLE_RECRUITER'];
    }

    /** Aucun secret à effacer (pas de mot de passe). */
    public function eraseCredentials(): void
    {
    }
}
