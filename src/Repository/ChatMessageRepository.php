<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatMessage;
use App\Entity\Recruiter;
use App\Enum\ChatOutcome;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Journal des conversations de l'assistant : historique d'un recruteur, questions libres du jour
 * (plafonds), liste de l'admin, purge.
 *
 * Liens :
 * - Utilisé par : ChatAssistant, AiQuota, RecruiterAreaController, Admin\AssistantController, DataPurgeCommand
 *
 * @extends ServiceEntityRepository<ChatMessage>
 */
class ChatMessageRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatMessage::class);
    }

    /**
     * Compte les questions libres envoyées à l'IA depuis une date (réponse d'un modèle, ou échec des deux
     * modèles) : les questions guidées, refusées ou bloquées par un plafond ne comptent pas.
     *
     * Liens :
     * - Appelée par : AiQuota (plafonds par recruteur et pour le site), AiStatus (consommation du jour)
     *
     * @param Recruiter|null $recruiter recruteur ; null : tout le site
     */
    public function countFreeQuestionsSince(\DateTimeImmutable $since, ?Recruiter $recruiter = null): int
    {
        $queryBuilder = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->andWhere('m.createdAt >= :since')->setParameter('since', $since)
            ->andWhere('m.outcome IN (:outcomes)')
            ->setParameter('outcomes', [ChatOutcome::AiPrimary->value, ChatOutcome::AiFallback->value, ChatOutcome::GuidedFallback->value])
            // Essais de l'admin : hors plafonds des recruteurs
            ->andWhere('m.adminTest = false');
        if (null !== $recruiter) {
            $queryBuilder->andWhere('m.recruiter = :recruiter')->setParameter('recruiter', $recruiter);
        }

        return (int) $queryBuilder->getQuery()->getSingleScalarResult();
    }

    /**
     * Renvoie les derniers échanges d'un recruteur, du plus ancien au plus récent.
     *
     * Liens :
     * - Appelée par : RecruiterAreaController (historique affiché dans l'espace privé)
     *
     * @return list<ChatMessage>
     */
    public function findLatestForRecruiter(Recruiter $recruiter, int $limit = 10): array
    {
        /** @var list<ChatMessage> $messages */
        $messages = $this->createQueryBuilder('m')
            ->andWhere('m.recruiter = :recruiter')->setParameter('recruiter', $recruiter)
            ->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()->getResult();

        return array_reverse($messages);
    }

    /**
     * Renvoie les derniers échanges pour l'admin (avec le recruteur), éventuellement les suspects seulement.
     *
     * Liens :
     * - Appelée par : Admin\AssistantController::index()
     *
     * @return list<ChatMessage>
     */
    public function findForAdmin(bool $suspiciousOnly = false, int $limit = 100): array
    {
        $queryBuilder = $this->createQueryBuilder('m')
            ->addSelect('r')->leftJoin('m.recruiter', 'r')
            ->orderBy('m.createdAt', 'DESC')->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit);
        if ($suspiciousOnly) {
            $queryBuilder->andWhere('m.suspiciousReason IS NOT NULL');
        }

        /* @var list<ChatMessage> */
        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Compte les échanges d'un recruteur (fiche de l'admin).
     */
    public function countForRecruiter(Recruiter $recruiter): int
    {
        return $this->count(['recruiter' => $recruiter]);
    }

    /**
     * Supprime les échanges antérieurs à une date.
     *
     * Liens :
     * - Appelée par : DataPurgeCommand (12 mois)
     *
     * @return int nombre de lignes supprimées
     */
    public function deleteBefore(\DateTimeImmutable $before): int
    {
        return (int) $this->createQueryBuilder('m')->delete()
            ->andWhere('m.createdAt < :before')->setParameter('before', $before)
            ->getQuery()->execute();
    }

    /**
     * Compte les échanges suspects (tentative de détournement, données privées) depuis une date.
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (tuile Assistant IA)
     */
    public function countSuspiciousSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')
            ->andWhere('m.suspiciousReason IS NOT NULL')
            ->andWhere('m.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }

    /**
     * Compte les réponses bloquées par le contrôle avant affichage depuis une date (essais de l'admin exclus).
     *
     * Liens :
     * - Appelée par : App\Service\Dashboard\DashboardMetrics (tuile « Cerveau de l'IA »), Admin\AiKnowledgeController
     */
    public function countBlockedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('m')->select('COUNT(m.id)')
            ->andWhere('m.blockedElement IS NOT NULL')
            ->andWhere('m.adminTest = false')
            ->andWhere('m.createdAt >= :since')->setParameter('since', $since)
            ->getQuery()->getSingleScalarResult();
    }
}
