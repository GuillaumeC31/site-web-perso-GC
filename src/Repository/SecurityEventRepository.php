<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SecurityEvent;
use App\Enum\SecurityEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt du journal des connexions à l'administration.
 *
 * Liens :
 * - Utilisé par : SecurityEventLogger, Admin\SecurityEventController, PurgeSecurityEventsCommand
 *
 * @extends ServiceEntityRepository<SecurityEvent>
 */
class SecurityEventRepository extends ServiceEntityRepository
{
    /** Nombre de lignes affichées par page dans l'admin. */
    public const PAGE_SIZE = 50;

    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SecurityEvent::class);
    }

    /**
     * Renvoie une page du journal, du plus récent au plus ancien.
     *
     * Liens :
     * - Appelée par : Admin\SecurityEventController::index()
     *
     * @param int $page numéro de page, à partir de 1
     *
     * @return list<SecurityEvent> au plus PAGE_SIZE lignes (liste vide au-delà de la dernière page)
     */
    public function findPage(int $page): array
    {
        /** @var list<SecurityEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setFirstResult((max(1, $page) - 1) * self::PAGE_SIZE)
            ->setMaxResults(self::PAGE_SIZE)
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * Renvoie les derniers événements de certains types, du plus récent au plus ancien.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::remote() (journal de la validation à distance)
     *
     * @param list<SecurityEventType> $types types voulus
     * @param int                     $limit nombre de lignes au plus
     *
     * @return list<SecurityEvent>
     */
    public function findLatestByTypes(array $types, int $limit = 20): array
    {
        /** @var list<SecurityEvent> $events */
        $events = $this->createQueryBuilder('e')
            ->where('e.type IN (:types)')
            ->setParameter('types', array_map(static fn (SecurityEventType $type): string => $type->value, $types))
            ->orderBy('e.occurredAt', 'DESC')
            ->addOrderBy('e.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $events;
    }

    /**
     * Compte les lignes du journal.
     *
     * Liens :
     * - Appelée par : Admin\SecurityEventController::index() (pagination)
     */
    public function countAll(): int
    {
        return $this->count([]);
    }

    /**
     * Supprime les lignes antérieures à la date donnée (purge RGPD).
     *
     * Liens :
     * - Appelée par : PurgeSecurityEventsCommand::execute()
     *
     * @param \DateTimeImmutable $limit date limite : tout ce qui est plus ancien est supprimé
     *
     * @return int nombre de lignes supprimées
     */
    public function deleteOlderThan(\DateTimeImmutable $limit): int
    {
        $deleted = $this->createQueryBuilder('e')
            ->delete()
            ->where('e.occurredAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();

        return (int) $deleted;
    }

    /**
     * Compte les évènements de certains types depuis une date (ex. échecs de connexion sur 7 jours).
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (tuile Sécurité)
     *
     * @param list<SecurityEventType> $types types comptés
     */
    public function countByTypesSince(array $types, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('e')->select('COUNT(e.id)')
            ->andWhere('e.type IN (:types)')->setParameter('types', $types)
            ->andWhere('e.occurredAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Renvoie la date du dernier évènement d'un type (ex. dernière connexion complète à l'admin).
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (tuile Sécurité)
     *
     * @return \DateTimeImmutable|null date, ou null si aucun évènement de ce type n'est gardé
     */
    public function findLastOccurredAt(SecurityEventType $type): ?\DateTimeImmutable
    {
        return $this->findOneBy(['type' => $type], ['occurredAt' => 'DESC', 'id' => 'DESC'])?->getOccurredAt();
    }
}
