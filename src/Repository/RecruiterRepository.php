<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Recruiter;
use App\Enum\RecruiterStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des recruteurs (demandes d'accès et comptes validés).
 *
 * Liens :
 * - Utilisé par : RecruiterAccessManager, Admin\RecruiterController, Admin\DashboardController,
 *   RecruiterDailyCommand, DataPurgeCommand, le fournisseur d'utilisateurs du pare-feu « recruiter »
 *
 * @extends ServiceEntityRepository<Recruiter>
 */
class RecruiterRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Recruiter::class);
    }

    /**
     * Cherche un recruteur par e-mail (insensible à la casse).
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::requestAccess(), RecruiterAccessManager::sendLoginLink()
     */
    public function findOneByEmail(string $email): ?Recruiter
    {
        return $this->findOneBy(['email' => mb_strtolower(trim($email))]);
    }

    /**
     * Liste les recruteurs, demandes en attente d'abord, puis par date de demande décroissante.
     *
     * Liens :
     * - Appelée par : Admin\RecruiterController::index()
     *
     * @param RecruiterStatus|null $status filtre facultatif
     *
     * @return list<Recruiter>
     */
    public function findForAdmin(?RecruiterStatus $status = null): array
    {
        $qb = $this->createQueryBuilder('r')
            ->addSelect("CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END AS HIDDEN pending_first")
            ->orderBy('pending_first', 'ASC')
            ->addOrderBy('r.requestedAt', 'DESC');
        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        /* @var list<Recruiter> */
        return $qb->getQuery()->getResult();
    }

    /**
     * Compte les recruteurs d'un statut.
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (tuile Recruteurs)
     */
    public function countByStatus(RecruiterStatus $status): int
    {
        return $this->count(['status' => $status]);
    }

    /**
     * Accès validés dont l'échéance tombe avant la date donnée et sans rappel envoyé.
     *
     * Liens :
     * - Appelée par : RecruiterDailyCommand (rappel 7 jours avant)
     *
     * @return list<Recruiter>
     */
    public function findApprovedExpiringWithoutReminder(\DateTimeImmutable $before): array
    {
        /* @var list<Recruiter> */
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :approved')->setParameter('approved', RecruiterStatus::Approved)
            ->andWhere('r.accessExpiresAt <= :before')->setParameter('before', $before)
            ->andWhere('r.reminderSentAt IS NULL')
            ->getQuery()->getResult();
    }

    /**
     * Accès validés échus à la date donnée.
     *
     * Liens :
     * - Appelée par : RecruiterDailyCommand (passage au statut « expiré »)
     *
     * @return list<Recruiter>
     */
    public function findApprovedExpired(\DateTimeImmutable $now): array
    {
        /* @var list<Recruiter> */
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :approved')->setParameter('approved', RecruiterStatus::Approved)
            ->andWhere('r.accessExpiresAt <= :now')->setParameter('now', $now)
            ->getQuery()->getResult();
    }

    /**
     * Recruteurs à supprimer (RGPD) : refusés sans activité depuis la date courte, les autres sans
     * activité depuis la date longue (hors accès encore ouverts).
     *
     * Liens :
     * - Appelée par : DataPurgeCommand
     *
     * @return list<Recruiter>
     */
    public function findToPurge(\DateTimeImmutable $refusedBefore, \DateTimeImmutable $inactiveBefore): array
    {
        /* @var list<Recruiter> */
        return $this->createQueryBuilder('r')
            ->andWhere('(r.status = :refused AND r.lastActivityAt < :refusedBefore) OR (r.status != :approved AND r.lastActivityAt < :inactiveBefore)')
            ->setParameter('refused', RecruiterStatus::Refused)
            ->setParameter('approved', RecruiterStatus::Approved)
            ->setParameter('refusedBefore', $refusedBefore)
            ->setParameter('inactiveBefore', $inactiveBefore)
            ->getQuery()->getResult();
    }

    /**
     * Efface les adresses IP des demandes plus anciennes que la date donnée.
     *
     * Liens :
     * - Appelée par : DataPurgeCommand
     *
     * @return int nombre de lignes modifiées
     */
    public function clearIpAddressesBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.ipAddress', 'NULL')
            ->andWhere('r.ipAddress IS NOT NULL')
            ->andWhere('r.requestedAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }

    /**
     * Compte les accès validés encore ouverts à la date donnée.
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (tuile Recruteurs)
     */
    public function countActive(\DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')
            ->andWhere('r.status = :approved')->setParameter('approved', RecruiterStatus::Approved)
            ->andWhere('r.accessExpiresAt > :now')->setParameter('now', $now)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Compte les accès validés qui se ferment entre deux dates (ex. dans les 7 jours).
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (tuile Recruteurs)
     */
    public function countExpiringBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) $this->createQueryBuilder('r')->select('COUNT(r.id)')
            ->andWhere('r.status = :approved')->setParameter('approved', RecruiterStatus::Approved)
            ->andWhere('r.accessExpiresAt > :from')->setParameter('from', $from)
            ->andWhere('r.accessExpiresAt <= :to')->setParameter('to', $to)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Renvoie la date de la plus ancienne demande en attente.
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (alerte au-delà de 24 h ouvrées)
     *
     * @return \DateTimeImmutable|null date de la demande, ou null si aucune demande n'attend
     */
    public function findOldestPendingRequestedAt(): ?\DateTimeImmutable
    {
        $oldest = $this->findOneBy(['status' => RecruiterStatus::Pending], ['requestedAt' => 'ASC']);

        return $oldest?->getRequestedAt();
    }
}
