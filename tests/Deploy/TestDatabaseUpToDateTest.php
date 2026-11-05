<?php

declare(strict_types=1);

namespace App\Tests\Deploy;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Base de test à jour : toutes les migrations sont appliquées (tests/bootstrap.php les lance avant chaque
 * suite) et les tables des derniers lots existent.
 *
 * Liens :
 * - Couvre : tests/bootstrap.php (migrations automatiques), migrations/
 */
final class TestDatabaseUpToDateTest extends KernelTestCase
{
    /**
     * Vérifie qu'aucune migration n'est en attente et que les tables récentes existent.
     */
    public function testAllMigrationsAreApplied(): void
    {
        self::bootKernel();
        $application = new Application(self::$kernel);

        $upToDate = new CommandTester($application->find('doctrine:migrations:up-to-date'));
        self::assertSame(0, $upToDate->execute([]), $upToDate->getDisplay());

        // Tables des derniers lots présentes (ex. failed_email, dont l'absence faussait les tests)
        $tables = self::getContainer()->get(Connection::class)->createSchemaManager()->listTableNames();
        foreach (['failed_email', 'heartbeat', 'ai_knowledge_entry', 'unanswered_question'] as $table) {
            self::assertContains($table, $tables);
        }
    }
}
