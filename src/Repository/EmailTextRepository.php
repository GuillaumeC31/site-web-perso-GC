<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\EmailText;
use App\Enum\EmailType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Dépôt des textes modifiés des e-mails.
 *
 * Liens :
 * - Utilisé par : Mail\EmailTexts
 *
 * @extends ServiceEntityRepository<EmailText>
 */
class EmailTextRepository extends ServiceEntityRepository
{
    /**
     * Déclare l'entité gérée par ce dépôt.
     *
     * @param ManagerRegistry $registry registre Doctrine
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, EmailText::class);
    }

    /**
     * Renvoie les textes modifiés d'un type d'e-mail.
     *
     * Liens :
     * - Appelée par : Mail\EmailTexts::find(), save(), reset()
     *
     * @return EmailText|null null si les textes d'origine sont utilisés
     */
    public function findOneByType(EmailType $type): ?EmailText
    {
        return $this->findOneBy(['type' => $type]);
    }
}
