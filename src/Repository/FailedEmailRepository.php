<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\FailedEmail;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des e-mails non envoyés (serveur SMTP injoignable).
 *
 * Liens :
 * - Utilisé par : ResilientMailer, FailedEmailResender, Admin\DashboardController
 *
 * @extends ServiceEntityRepository<FailedEmail>
 */
class FailedEmailRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FailedEmail::class);
    }

    /**
     * Liste les e-mails non envoyés, du plus ancien au plus récent (ordre de renvoi).
     *
     * Liens :
     * - Appelée par : FailedEmailResender::resendAll()
     *
     * @return list<FailedEmail>
     */
    public function findAllOldestFirst(): array
    {
        /** @var list<FailedEmail> $emails */
        $emails = $this->findBy([], ['createdAt' => 'ASC', 'id' => 'ASC']);

        return $emails;
    }
}
