<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\AiKnowledgeEntry;
use App\Enum\AiKnowledgeCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Fiches de connaissances de l'assistant IA.
 *
 * Liens :
 * - Utilisé par : ChatContextBuilder, AiKnowledgeBudget, Admin\AiKnowledgeController, AiDirectiveImporter
 *
 * @extends ServiceEntityRepository<AiKnowledgeEntry>
 */
class AiKnowledgeEntryRepository extends ServiceEntityRepository
{
    /**
     * Reçoit le registre Doctrine.
     */
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AiKnowledgeEntry::class);
    }

    /**
     * Renvoie toutes les fiches, par catégorie (ordre de l'énumération) puis par ordre et titre.
     *
     * Liens :
     * - Appelée par : Admin\AiKnowledgeController (liste, export Markdown), findActiveOrdered()
     *
     * @param bool $activeOnly vrai : fiches actives seulement (envoyées à l'assistant)
     *
     * @return list<AiKnowledgeEntry>
     */
    public function findOrdered(bool $activeOnly = false): array
    {
        $criteria = $activeOnly ? ['active' => true] : [];
        $entries = $this->findBy($criteria, ['position' => 'ASC', 'title' => 'ASC', 'id' => 'ASC']);
        $order = array_flip(array_map(static fn (AiKnowledgeCategory $c): string => $c->value, AiKnowledgeCategory::cases()));
        usort($entries, static fn (AiKnowledgeEntry $a, AiKnowledgeEntry $b): int => $order[$a->getCategory()->value] <=> $order[$b->getCategory()->value]);

        return $entries;
    }

    /**
     * Renvoie les fiches actives, dans l'ordre d'envoi à l'assistant.
     *
     * Liens :
     * - Appelée par : ChatContextBuilder::buildKnowledgeText(), AiKnowledgeBudget
     *
     * @return list<AiKnowledgeEntry>
     */
    public function findActiveOrdered(): array
    {
        return $this->findOrdered(true);
    }

    /**
     * Indique si une fiche a déjà ce contenu (import des anciennes directives sans doublon).
     *
     * Liens :
     * - Appelée par : App\Service\Ai\AiDirectiveImporter
     */
    public function existsWithContent(string $content): bool
    {
        return null !== $this->findOneBy(['content' => trim($content)]);
    }
}
