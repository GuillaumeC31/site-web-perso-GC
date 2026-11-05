<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Heartbeat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * Passages des tâches planifiées et évènements surveillés (table heartbeat).
 *
 * L'écriture passe par une requête SQL directe (insertion ou mise à jour) : elle n'enregistre jamais, par
 * effet de bord, d'autres entités en cours de modification (ex. pendant l'envoi d'un e-mail).
 *
 * Liens :
 * - Utilisé par : commandes planifiées (DbBackupCommand, PurgeSecurityEventsCommand, DataPurgeCommand,
 *   RecruiterDailyCommand, AiRefreshModelsCommand), ResilientMailer, DashboardMetrics
 *
 * @extends ServiceEntityRepository<Heartbeat>
 */
class HeartbeatRepository extends ServiceEntityRepository
{
    /**
     * Reçoit le registre Doctrine et l'horloge.
     */
    public function __construct(ManagerRegistry $registry, private readonly ClockInterface $clock)
    {
        parent::__construct($registry, Heartbeat::class);
    }

    /**
     * Note un passage maintenant (crée ou met à jour la ligne du nom).
     *
     * Liens :
     * - Appelée par : les commandes planifiées en fin d'exécution, ResilientMailer (e-mail parti)
     *
     * Exemple : $heartbeats->beat(Heartbeat::BACKUP, true, ['file' => 'backup-….sql.gz', 'size' => 123456]);
     *
     * @param string                                  $name    nom du passage (Heartbeat::*)
     * @param bool                                    $success vrai si réussi
     * @param array<string, scalar|list<scalar>|null> $details détails (sans donnée personnelle)
     */
    public function beat(string $name, bool $success = true, array $details = []): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO heartbeat (name, last_run_at, success, details) VALUES (:name, :at, :success, :details)
             ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), success = VALUES(success), details = VALUES(details)',
            ['name' => $name, 'at' => $this->clock->now(), 'success' => $success, 'details' => json_encode($details, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE)],
            ['at' => Types::DATETIME_IMMUTABLE, 'success' => Types::BOOLEAN],
        );
    }

    /**
     * Renvoie tous les passages, indexés par nom (relus en base, jamais depuis la mémoire de Doctrine).
     *
     * Liens :
     * - Appelée par : DashboardMetrics
     *
     * @return array<string, Heartbeat> nom => passage ; tableau vide si aucun passage
     */
    public function findAllByName(): array
    {
        $rows = [];
        foreach ($this->getEntityManager()->getConnection()->fetchAllAssociative('SELECT name, last_run_at, success, details FROM heartbeat') as $row) {
            /** @var array<string, scalar|list<scalar>|null> $details */
            $details = json_decode((string) $row['details'], true) ?: [];
            $rows[(string) $row['name']] = new Heartbeat((string) $row['name'], new \DateTimeImmutable((string) $row['last_run_at']), (bool) $row['success'], $details);
        }

        return $rows;
    }
}
