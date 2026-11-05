<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailTextRevision;
use App\Enum\EmailType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt de l'historique des modifications des textes des e-mails.
 *
 * Liens :
 * - Utilisé par : Mail\EmailTexts, Admin\CommunicationController::emailText()
 *
 * @extends ServiceEntityRepository<EmailTextRevision>
 */
class EmailTextRevisionRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailTextRevision::class);
    }

    /**
     * Renvoie les dernières modifications d'un type d'e-mail, les plus récentes d'abord.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::emailText()
     *
     * @param int $limit nombre de lignes au plus
     *
     * @return list<EmailTextRevision>
     */
    public function findLatest(EmailType $type, int $limit = 20): array
    {
        /* @var list<EmailTextRevision> */
        return $this->findBy(['type' => $type], ['savedAt' => 'DESC', 'id' => 'DESC'], $limit);
    }
}
