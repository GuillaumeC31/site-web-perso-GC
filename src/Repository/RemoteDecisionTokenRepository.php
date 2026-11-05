<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Recruiter;
use App\Entity\RemoteDecisionToken;
use App\Enum\NotificationChannel;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Accès aux jetons des décisions à distance.
 *
 * Liens :
 * - Utilisé par : Service\Recruiter\RemoteDecisionTokens
 *
 * @extends ServiceEntityRepository<RemoteDecisionToken>
 */
final class RemoteDecisionTokenRepository extends ServiceEntityRepository
{
    /**
     * Reçoit le registre Doctrine.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RemoteDecisionToken::class);
    }

    /**
     * Retrouve un jeton par son empreinte.
     *
     * Liens :
     * - Appelée par : RemoteDecisionTokens::find()
     *
     * @param string $tokenHash empreinte SHA-256 du jeton reçu
     *
     * @return RemoteDecisionToken|null null si aucun jeton ne correspond
     */
    public function findOneByHash(string $tokenHash): ?RemoteDecisionToken
    {
        return $this->findOneBy(['tokenHash' => $tokenHash]);
    }

    /**
     * Retrouve le jeton dont le message Telegram demande le code PIN (réponse reçue à ce message).
     *
     * Liens :
     * - Appelée par : TelegramCallbackHandler::handleMessage()
     *
     * @param int $promptMessageId message auquel Guillaume a répondu
     *
     * @return RemoteDecisionToken|null null si ce message ne demande aucun code PIN
     */
    public function findOneByTelegramPrompt(int $promptMessageId): ?RemoteDecisionToken
    {
        return $this->findOneBy(['telegramPromptMessageId' => $promptMessageId]);
    }

    /**
     * Renvoie les jetons encore inutilisés d'une demande sur un canal (Telegram : remplacés quand l'admin
     * annule une confirmation).
     *
     * Liens :
     * - Appelée par : RemoteDecisionTokens::revokeUnused()
     *
     * @return list<RemoteDecisionToken>
     */
    public function findUnused(Recruiter $recruiter, NotificationChannel $channel): array
    {
        return $this->createQueryBuilder('t')
            ->andWhere('t.recruiter = :recruiter')->setParameter('recruiter', $recruiter)
            ->andWhere('t.channel = :channel')->setParameter('channel', $channel)
            ->andWhere('t.usedAt IS NULL')
            ->getQuery()->getResult();
    }
}
