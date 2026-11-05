<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\RecruiterEventType;
use App\Repository\RecruiterEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Signal d'intérêt d'un recruteur validé (première connexion, « Je suis intéressé », téléchargement
 * du CV complet). Notifié au plus une fois par jour et par recruteur : le premier signal du jour part
 * tout de suite, les suivants sont regroupés dans le récapitulatif du lendemain (app:recruiter:daily).
 *
 * Liens :
 * - Utilisée par : InterestSignalNotifier, RecruiterDailyCommand, Admin\RecruiterController (historique)
 */
#[ORM\Entity(repositoryClass: RecruiterEventRepository::class)]
#[ORM\Table(name: 'recruiter_event')]
#[ORM\Index(columns: ['notified_at'])]
class RecruiterEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Recruiter $recruiter;

    #[ORM\Column(length: 20, enumType: RecruiterEventType::class)]
    private RecruiterEventType $type;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** Date de la notification qui a inclus ce signal (null : en attente du récapitulatif). */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $notifiedAt = null;

    /**
     * Crée un signal.
     *
     * @param Recruiter          $recruiter  recruteur concerné
     * @param RecruiterEventType $type       type de signal
     * @param \DateTimeImmutable $occurredAt date du signal
     */
    public function __construct(Recruiter $recruiter, RecruiterEventType $type, \DateTimeImmutable $occurredAt)
    {
        $this->recruiter = $recruiter;
        $this->type = $type;
        $this->occurredAt = $occurredAt;
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie le recruteur. */
    public function getRecruiter(): Recruiter
    {
        return $this->recruiter;
    }

    /** Renvoie le type de signal. */
    public function getType(): RecruiterEventType
    {
        return $this->type;
    }

    /** Renvoie la date du signal. */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Renvoie la date de notification. */
    public function getNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->notifiedAt;
    }

    /** Marque le signal comme notifié. */
    public function markNotified(\DateTimeImmutable $notifiedAt): static
    {
        $this->notifiedAt = $notifiedAt;

        return $this;
    }
}
