<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiKnowledgeEntry;
use App\Entity\AiKnowledgeRevision;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Historique des fiches de connaissances.
 *
 * Liens :
 * - Utilisé par : Admin\AiKnowledgeController::history()
 *
 * @extends ServiceEntityRepository<AiKnowledgeRevision>
 */
class AiKnowledgeRevisionRepository extends ServiceEntityRepository
{
    /**
     * Reçoit le registre Doctrine.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiKnowledgeRevision::class);
    }

    /**
     * Renvoie les anciennes versions d'une fiche, de la plus récente à la plus ancienne.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController::history()
     *
     * @return list<AiKnowledgeRevision>
     */
    public function findForEntry(AiKnowledgeEntry $entry): array
    {
        return $this->findBy(['entry' => $entry], ['changedAt' => 'DESC', 'id' => 'DESC']);
    }
}
