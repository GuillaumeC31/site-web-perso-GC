<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Enum\AiKnowledgeCategory;
use App\Repository\AiKnowledgeEntryRepository;
use App\Service\Ai\AiDirectiveImporter;
use App\Service\Legacy\LegacyDatabaseReader;
use Doctrine\DBAL\DriverManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Import des directives du chatbot de l'ancien site : table chatbot_directive simulée (SQLite en mémoire,
 * l'ancien projet n'est jamais touché). Aperçu sans écriture, puis fiches en brouillon inactif ; « Règles
 * strictes » listées à part ; directives avec donnée privée ou déjà reprises écartées.
 *
 * Liens :
 * - Couvre : App\Service\Ai\AiDirectiveImporter, LegacyDatabaseReader::getChatbotDirectives()
 */
final class AiDirectiveImporterTest extends KernelTestCase
{
    /**
     * Vérifie l'aperçu puis l'import.
     */
    public function testPreviewThenApply(): void
    {
        self::bootKernel();
        $reader = $this->legacyReader([
            ['Info Personnelle', 'Guillaume est mobile dans toute la région Auvergne-Rhône-Alpes et dispose du permis B.', 1],
            ['FAQ', 'Disponibilité : sous un mois après acceptation de l\'offre.', 1],
            ['Ton & Personnalité', 'Réponses courtes et chaleureuses.', 0],
            ['Règle Stricte', 'Ne jamais donner le numéro de téléphone.', 1],
            ['Info Personnelle', 'Mon numéro personnel : 06 12 34 56 78.', 1],
        ]);
        $importer = self::getContainer()->get(AiDirectiveImporter::class);
        $repository = self::getContainer()->get(AiKnowledgeEntryRepository::class);

        $preview = $importer->import($reader, false);
        self::assertCount(3, $preview['imports']);
        self::assertSame(0, $repository->count([]), 'Aperçu : rien n\'est écrit.');
        self::assertCount(1, $preview['strict']);
        self::assertSame('Ne jamais donner le numéro de téléphone.', $preview['strict'][0]['content']);
        self::assertCount(1, $preview['skipped']);
        self::assertSame('admin.ai_knowledge.blocked.phone', $preview['skipped'][0]['reason']);

        $importer->import($reader, true);
        $entries = $repository->findOrdered();
        self::assertCount(3, $entries);
        foreach ($entries as $entry) {
            self::assertFalse($entry->isActive(), 'Toujours en brouillon.');
            self::assertStringNotContainsString('Ne jamais donner', $entry->getContent());
        }
        self::assertSame([AiKnowledgeCategory::Personal, AiKnowledgeCategory::Faq, AiKnowledgeCategory::Tone], array_map(static fn ($e) => $e->getCategory(), $entries));
        self::assertSame('Guillaume est mobile dans toute la région Auvergne-Rhône-Alpes et dispose du…', $entries[0]->getTitle());

        // Relance : rien n'est repris deux fois
        $again = $importer->import($reader, true);
        self::assertSame([], $again['imports']);
        self::assertCount(3, $repository->findOrdered());
    }

    /**
     * Construit une ancienne base simulée (SQLite en mémoire).
     *
     * @param list<array{string, string, int}> $rows type, contenu, active
     */
    private function legacyReader(array $rows): LegacyDatabaseReader
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE chatbot_directive (id INTEGER PRIMARY KEY AUTOINCREMENT, type VARCHAR(100), content TEXT, is_active INTEGER)');
        foreach ($rows as [$type, $content, $active]) {
            $connection->insert('chatbot_directive', ['type' => $type, 'content' => $content, 'is_active' => $active]);
        }

        return new LegacyDatabaseReader($connection);
    }
}
