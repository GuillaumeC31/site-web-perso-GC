<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Recruiter;
use App\Entity\RecruiterEvent;
use App\Enum\RecruiterEventType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des événements des recruteurs (signal d'intérêt explicite et activité).
 *
 * Liens :
 * - Utilisé par : InterestSignalNotifier, RecruiterDailyCommand, Admin\RecruiterController
 *
 * @extends ServiceEntityRepository<RecruiterEvent>
 */
class RecruiterEventRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RecruiterEvent::class);
    }

    /**
     * Indique si une notification est déjà partie pour ce recruteur depuis la date donnée, pour des événements des
     * types donnés (activité et signal d'intérêt sont comptés à part, v2.0.3).
     *
     * Liens :
     * - Appelée par : InterestSignalNotifier::recordActivity(), InterestSignalNotifier::sendDigest()
     *
     * @param list<RecruiterEventType> $types types d'événements pris en compte
     */
    public function hasNotificationSince(Recruiter $recruiter, \DateTimeImmutable $since, array $types): bool
    {
        return (int) $this->createQueryBuilder('e')
            ->select('COUNT(e.id)')
            ->andWhere('e.recruiter = :recruiter')->setParameter('recruiter', $recruiter)
            ->andWhere('e.notifiedAt >= :since')->setParameter('since', $since)
            ->andWhere('e.type IN (:types)')->setParameter('types', array_map(static fn (RecruiterEventType $t): string => $t->value, $types))
            ->getQuery()->getSingleScalarResult() > 0;
    }

    /**
     * Dernier événement d'un type donné pour ce recruteur depuis la date donnée (anti-doublon du signal d'intérêt).
     *
     * Liens :
     * - Appelée par : InterestSignalNotifier::signalInterest()
     */
    public function findLastSince(Recruiter $recruiter, RecruiterEventType $type, \DateTimeImmutable $since): ?RecruiterEvent
    {
        $event = $this->createQueryBuilder('e')
            ->andWhere('e.recruiter = :recruiter')->setParameter('recruiter', $recruiter)
            ->andWhere('e.type = :type')->setParameter('type', $type->value)
            ->andWhere('e.occurredAt >= :since')->setParameter('since', $since)
            ->orderBy('e.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()->getOneOrNullResult();

        return $event instanceof RecruiterEvent ? $event : null;
    }

    /**
     * Signaux pas encore notifiés, survenus avant la date donnée (récapitulatif).
     *
     * Liens :
     * - Appelée par : InterestSignalNotifier::sendDigest()
     *
     * @return list<RecruiterEvent>
     */
    public function findPendingBefore(\DateTimeImmutable $before): array
    {
        /* @var list<RecruiterEvent> */
        return $this->createQueryBuilder('e')
            ->addSelect('r')
            ->join('e.recruiter', 'r')
            ->andWhere('e.notifiedAt IS NULL')
            ->andWhere('e.occurredAt < :before')->setParameter('before', $before)
            ->orderBy('e.occurredAt', 'ASC')
            ->getQuery()->getResult();
    }

    /**
     * Derniers signaux d'un recruteur.
     *
     * Liens :
     * - Appelée par : Admin\RecruiterController::show()
     *
     * @return list<RecruiterEvent>
     */
    public function findLatestFor(Recruiter $recruiter, int $limit = 20): array
    {
        /* @var list<RecruiterEvent> */
        return $this->findBy(['recruiter' => $recruiter], ['occurredAt' => 'DESC'], $limit);
    }
}
