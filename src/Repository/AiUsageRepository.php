<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiUsage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Consommation du fournisseur d'IA : jetons réels par période et par usage.
 *
 * Liens :
 * - Utilisé par : AiUsageRecorder, AiQuota (plafond mensuel), AiStatus (admin), DataPurgeCommand
 *
 * @extends ServiceEntityRepository<AiUsage>
 */
class AiUsageRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiUsage::class);
    }

    /**
     * Renvoie les jetons consommés (envoyés + réponse) depuis une date.
     *
     * Liens :
     * - Appelée par : AiQuota::monthTokens(), AiStatus
     */
    public function sumTokensSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('u')
            ->select('COALESCE(SUM(u.promptTokens + u.completionTokens), 0)')
            ->andWhere('u.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Renvoie, depuis une date, le nombre d'appels et les jetons par usage.
     *
     * Liens :
     * - Appelée par : AiStatus::getSummary() (admin)
     *
     * @return list<array{purpose: \App\Enum\AiPurpose, calls: int, errors: int, tokens: int}>
     */
    public function summarizeSince(\DateTimeImmutable $since): array
    {
        /** @var list<array{purpose: \App\Enum\AiPurpose, calls: int|string, errors: int|string, tokens: int|string}> $rows */
        $rows = $this->createQueryBuilder('u')
            ->select('u.purpose AS purpose, COUNT(u.id) AS calls, SUM(CASE WHEN u.error IS NULL THEN 0 ELSE 1 END) AS errors, COALESCE(SUM(u.promptTokens + u.completionTokens), 0) AS tokens')
            ->andWhere('u.createdAt >= :since')->setParameter('since', $since)
            ->groupBy('u.purpose')
            ->getQuery()->getResult();

        return array_map(static fn (array $row): array => [
            'purpose' => $row['purpose'], 'calls' => (int) $row['calls'], 'errors' => (int) $row['errors'], 'tokens' => (int) $row['tokens'],
        ], $rows);
    }

    /**
     * Supprime les lignes antérieures à une date.
     *
     * Liens :
     * - Appelée par : DataPurgeCommand (12 mois)
     *
     * @return int nombre de lignes supprimées
     */
    public function deleteBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('u')->delete()
            ->andWhere('u.createdAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }
}
