<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de l'historique des envois d'e-mails (destinataires masqués, 90 jours).
 *
 * Liens :
 * - Utilisé par : Service\Mail\ResilientMailer (écriture), Admin\CommunicationController (lecture),
 *   Command\DataPurgeCommand (purge)
 *
 * @extends ServiceEntityRepository<EmailLog>
 */
class EmailLogRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailLog::class);
    }

    /**
     * Renvoie les derniers envois, les plus récents d'abord.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::emails()
     *
     * @param int $limit nombre d'envois au plus (50 sur la page)
     *
     * @return list<EmailLog>
     */
    public function findLatest(int $limit = 50): array
    {
        /* @var list<EmailLog> */
        return $this->findBy([], ['sentAt' => 'DESC', 'id' => 'DESC'], $limit);
    }

    /**
     * Renvoie la date du dernier envoi réussi.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController (santé de l'envoi)
     *
     * @return \DateTimeImmutable|null null si aucun envoi réussi n'est connu
     */
    public function findLastSuccessAt(): ?\DateTimeImmutable
    {
        $log = $this->findOneBy(['success' => true], ['sentAt' => 'DESC', 'id' => 'DESC']);

        return $log?->getSentAt();
    }

    /**
     * Compte les envois (réussis ou non) d'une liste de natures depuis une date, hors adresses des tests de bout en bout
     * (domaine « example.test » : le parcours de test ne consomme pas le plafond réel).
     *
     * Liens :
     * - Appelée par : Mail\UnvalidatedEmailCap::allows()
     *
     * @param list<string> $kinds natures (ResilientMailer::KIND_*)
     *
     * @return int nombre d'envois
     */
    public function countKindsSince(array $kinds, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('l')
            ->select('COUNT(l.id)')
            ->where('l.kind IN (:kinds)')
            ->andWhere('l.sentAt >= :since')
            ->andWhere('l.recipient NOT LIKE :test')
            ->setParameter('kinds', $kinds)
            ->setParameter('since', $since)
            ->setParameter('test', '%example.test')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Supprime les envois plus anciens que la date donnée.
     *
     * Liens :
     * - Appelée par : Command\DataPurgeCommand (90 jours)
     *
     * @return int nombre de lignes supprimées
     */
    public function deleteBefore(\DateTimeImmutable $limit): int
    {
        return (int) $this->createQueryBuilder('l')
            ->delete()
            ->where('l.sentAt < :limit')
            ->setParameter('limit', $limit)
            ->getQuery()
            ->execute();
    }
}
