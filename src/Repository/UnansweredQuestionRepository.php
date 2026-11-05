<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UnansweredQuestion;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Questions sans réponse de l'assistant IA.
 *
 * Liens :
 * - Utilisé par : Admin\AiKnowledgeController, App\Service\Dashboard\DashboardMetrics, DataPurgeCommand
 *
 * @extends ServiceEntityRepository<UnansweredQuestion>
 */
class UnansweredQuestionRepository extends ServiceEntityRepository
{
    /**
     * Reçoit le registre Doctrine.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UnansweredQuestion::class);
    }

    /**
     * Renvoie les questions à traiter, de la plus récente à la plus ancienne.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController::unanswered()
     *
     * @return list<UnansweredQuestion>
     */
    public function findPending(int $limit = 200): array
    {
        return $this->findBy(['status' => UnansweredQuestion::STATUS_PENDING], ['createdAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * Compte les questions à traiter.
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics, Admin\AiKnowledgeController::index()
     */
    public function countPending(): int
    {
        return $this->count(['status' => UnansweredQuestion::STATUS_PENDING]);
    }

    /**
     * Supprime les questions antérieures à une date (conservation limitée, comme le journal de l'assistant).
     *
     * Liens :
     * - Appelée par : DataPurgeCommand
     *
     * @return int nombre de questions supprimées
     */
    public function deleteBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('q')->delete()->where('q.createdAt < :before')->setParameter('before', $before)->getQuery()->execute();
    }
}
