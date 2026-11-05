<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\HeartbeatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Dernier passage d'une tâche planifiée (cron) ou d'un évènement surveillé, pour le tableau de bord de
 * l'admin : sauvegarde (fichier et taille), purges, rappels, vérification des modèles d'IA, dernier e-mail
 * parti. Une ligne par nom, mise à jour à chaque passage (HeartbeatRepository::beat()).
 *
 * Liens :
 * - Écrit par : HeartbeatRepository::beat() (commandes app:db:backup, app:security-event:purge,
 *   app:data:purge, app:recruiter:daily, app:ai:refresh-models, app:communication:alerts, ResilientMailer)
 * - Lu par : App\Service\Dashboard\DashboardMetrics
 */
#[ORM\Entity(repositoryClass: HeartbeatRepository::class)]
#[ORM\Table(name: 'heartbeat')]
class Heartbeat
{
    /** Noms des passages surveillés. */
    public const BACKUP = 'backup';
    public const SECURITY_PURGE = 'security_purge';
    public const DATA_PURGE = 'data_purge';
    public const RECRUITER_DAILY = 'recruiter_daily';
    public const AI_MODELS = 'ai_models';
    public const MAIL_SENT = 'mail_sent';

    /** Alertes « Général du site » (app:communication:alerts, toutes les heures) ; détails : date de la dernière alerte par type. */
    public const COMMUNICATION_ALERTS = 'communication_alerts';

    /** Relève des appuis Telegram en local (app:telegram:poll, développement seulement ; hors tâches planifiées). */
    public const TELEGRAM_POLL = 'telegram_poll';

    /**
     * Plafond des e-mails aux personnes non validées atteint (Mail\UnvalidatedEmailCap ; hors tâches planifiées) : détails
     * « day » (AAAA-MM-JJ) et « suspended » (e-mails suspendus ce jour-là).
     */
    public const EMAIL_CAP = 'email_cap';

    /** Tâches planifiées, dans l'ordre du tableau de bord (nom => commande). */
    public const CRON_COMMANDS = [
        self::BACKUP => 'app:db:backup',
        self::SECURITY_PURGE => 'app:security-event:purge',
        self::RECRUITER_DAILY => 'app:recruiter:daily',
        self::DATA_PURGE => 'app:data:purge',
        self::AI_MODELS => 'app:ai:refresh-models',
        self::COMMUNICATION_ALERTS => 'app:communication:alerts',
    ];

    /** Nom du passage (constantes ci-dessus). */
    #[ORM\Id]
    #[ORM\Column(length: 40)]
    private string $name;

    /** Date du dernier passage. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $lastRunAt;

    /** Vrai si le dernier passage a réussi. */
    #[ORM\Column]
    private bool $success = true;

    /** @var array<string, scalar|list<scalar>|null> détails du passage (ex. fichier et taille de la sauvegarde) */
    #[ORM\Column(type: Types::JSON)]
    private array $details = [];

    /**
     * Crée la ligne d'un passage (les écritures passent par HeartbeatRepository::beat()).
     *
     * @param string                                  $name      nom du passage
     * @param \DateTimeImmutable                      $lastRunAt date du passage
     * @param bool                                    $success   vrai si réussi
     * @param array<string, scalar|list<scalar>|null> $details   détails
     */
    public function __construct(string $name, \DateTimeImmutable $lastRunAt, bool $success = true, array $details = [])
    {
        $this->name = $name;
        $this->lastRunAt = $lastRunAt;
        $this->success = $success;
        $this->details = $details;
    }

    /** Renvoie le nom du passage. */
    public function getName(): string
    {
        return $this->name;
    }

    /** Renvoie la date du dernier passage. */
    public function getLastRunAt(): \DateTimeImmutable
    {
        return $this->lastRunAt;
    }

    /** Indique si le dernier passage a réussi. */
    public function isSuccess(): bool
    {
        return $this->success;
    }

    /**
     * Renvoie les détails du dernier passage.
     *
     * @return array<string, scalar|list<scalar>|null>
     */
    public function getDetails(): array
    {
        return $this->details;
    }
}
