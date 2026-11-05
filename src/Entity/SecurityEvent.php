<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\SecurityEventType;
use App\Repository\SecurityEventRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne du journal des connexions à l'administration (réussites, échecs, clés, déconnexions).
 *
 * Contient une adresse IP, donnée personnelle : les lignes sont supprimées au bout de
 * 90 jours (commande app:security-event:purge, lancée chaque jour par le cron).
 * Aucun mot de passe ni jeton n'est jamais enregistré.
 *
 * Liens :
 * - Créée par : SecurityEventLogger
 * - Utilisée par : SecurityEventRepository, Admin\SecurityEventController, PurgeSecurityEventsCommand
 */
#[ORM\Entity(repositoryClass: SecurityEventRepository::class)]
#[ORM\Table(name: 'security_event')]
#[ORM\Index(name: 'idx_security_event_occurred_at', columns: ['occurred_at'])]
class SecurityEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** Date et heure de l'évènement. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    /** Type d'évènement. */
    #[ORM\Column(length: 40, enumType: SecurityEventType::class)]
    private SecurityEventType $type;

    /** Identifiant saisi ou concerné (peut ne correspondre à aucun compte en cas d'échec). */
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $username;

    /** Adresse IP du client (IPv4 ou IPv6). */
    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress;

    /** Navigateur du client, tronqué à 255 caractères. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $userAgent;

    /**
     * Crée une ligne de journal datée de maintenant.
     *
     * Liens :
     * - Appelée par : SecurityEventLogger::log()
     *
     * @param SecurityEventType $type      type d'évènement
     * @param string|null       $username  identifiant concerné
     * @param string|null       $ipAddress adresse IP du client
     * @param string|null       $userAgent navigateur du client
     */
    public function __construct(SecurityEventType $type, ?string $username, ?string $ipAddress, ?string $userAgent)
    {
        $this->occurredAt = new \DateTimeImmutable();
        $this->type = $type;
        $this->username = null === $username ? null : mb_substr($username, 0, 64);
        $this->ipAddress = $ipAddress;
        $this->userAgent = null === $userAgent ? null : mb_substr($userAgent, 0, 255);
    }

    /** Renvoie l'identifiant technique. */
    public function getId(): ?int
    {
        return $this->id;
    }

    /** Renvoie la date de l'évènement. */
    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /** Renvoie le type d'évènement. */
    public function getType(): SecurityEventType
    {
        return $this->type;
    }

    /** Renvoie l'identifiant concerné, ou null. */
    public function getUsername(): ?string
    {
        return $this->username;
    }

    /** Renvoie l'adresse IP, ou null. */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /** Renvoie le navigateur, ou null. */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
