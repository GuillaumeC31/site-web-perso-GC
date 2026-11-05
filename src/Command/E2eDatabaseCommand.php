<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Dev\E2eMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Développement seulement : prépare la base dédiée aux tests de bout en bout (« <base>_e2e », décision du
 * 2026-10-29). La suite Playwright y travaille entièrement ; la base de développement n'est jamais modifiée.
 *
 * - prepare : vide la base e2e, la remet au schéma courant (migrations), puis y RECOPIE les données de la base de
 *   développement, lue en lecture seule (SELECT), pour que les pages testées aient leur contenu réel.
 *
 * Garde-fou : la commande refuse de s'exécuter hors du mode e2e (E2E_DATABASE=1) ou si la base visée ne se termine
 * pas par « _e2e » : elle ne peut jamais vider la base de développement.
 *
 * Usage : E2E_DATABASE=1 php bin/console app:e2e:database prepare (lancé par tests/e2e/support/global-setup.js)
 *
 * Liens :
 * - Utilise : Service\Dev\E2eMode, doctrine:migrations:migrate
 * - Voir aussi : App\DependencyInjection\E2eDatabaseEnvVarProcessor, playwright.config.js (E2E_DATABASE=1)
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:e2e:database', description: 'Développement : prépare la base dédiée aux tests de bout en bout (prepare)')]
final class E2eDatabaseCommand extends Command
{
    /** Tables jamais recopiées (état propre à chaque base). */
    private const SKIPPED_TABLES = ['doctrine_migration_versions', 'messenger_messages'];

    /**
     * Reçoit la connexion (base e2e en mode e2e) et l'adresse de la base de développement.
     *
     * @param string $developmentUrl DATABASE_URL brute (base de développement, lue seulement)
     */
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%env(resolve:DATABASE_URL)%')]
        private readonly string $developmentUrl,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'action.
     */
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'prepare');
    }

    /**
     * Prépare la base e2e.
     *
     * Liens :
     * - Appelée par : la console Symfony (global-setup.js)
     *
     * @return int Command::SUCCESS ; Command::FAILURE si le garde-fou refuse ; Command::INVALID pour une action inconnue
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('prepare' !== $input->getArgument('action')) {
            return Command::INVALID;
        }
        $target = (string) $this->connection->fetchOne('SELECT DATABASE()');
        $source = $this->databaseName($this->developmentUrl);
        if (!E2eMode::isActive($this->environment) || !str_ends_with($target, E2eMode::DATABASE_SUFFIX) || $target === $source) {
            $output->writeln('<error>Refus : la base visée (« '.$target.' ») n\'est pas la base des tests de bout en bout. Lancez la commande avec E2E_DATABASE=1.</error>');

            return Command::FAILURE;
        }

        // 1. Base e2e vidée puis remise au schéma courant
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($this->tablesOf($target) as $table) {
            $this->connection->executeStatement('DROP TABLE '.$this->connection->quoteIdentifier($table));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $migrate = $this->getApplication()?->find('doctrine:migrations:migrate');
        $arguments = new ArrayInput(['--allow-no-migration' => true]);
        // Sans question : la confirmation des migrations bloquerait la suite (aucune saisie possible)
        $arguments->setInteractive(false);
        if (null === $migrate || Command::SUCCESS !== $migrate->run($arguments, new NullOutput())) {
            $output->writeln('<error>Migrations de la base e2e en échec.</error>');

            return Command::FAILURE;
        }

        // 2. Données de la base de développement recopiées (lecture seule de la source)
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        $rows = 0;
        $sourceTables = $this->tablesOf($source);
        foreach ($this->tablesOf($target) as $table) {
            if (\in_array($table, self::SKIPPED_TABLES, true) || !\in_array($table, $sourceTables, true)) {
                continue;
            }
            // Contenu chargé par une migration (ex. arguments « Pourquoi me recruter », v2.0.2) remplacé par la copie :
            // sinon doublons de clé primaire
            $this->connection->executeStatement('DELETE FROM '.$this->connection->quoteIdentifier($table));
            $columns = array_values(array_intersect($this->columnsOf($target, $table), $this->columnsOf($source, $table)));
            $list = implode(', ', array_map($this->connection->quoteIdentifier(...), $columns));
            $rows += (int) $this->connection->executeStatement(\sprintf(
                'INSERT INTO %s (%s) SELECT %s FROM %s.%s',
                $this->connection->quoteIdentifier($table), $list, $list, $this->connection->quoteIdentifier($source), $this->connection->quoteIdentifier($table),
            ));
        }
        $this->connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        $output->writeln(\sprintf('Base %s prête : %d ligne(s) recopiée(s) depuis %s (lecture seule).', $target, $rows, $source));

        return Command::SUCCESS;
    }

    /**
     * Tables d'une base.
     *
     * @return list<string>
     */
    private function tablesOf(string $database): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'", [$database]);
    }

    /**
     * Colonnes d'une table, dans l'ordre.
     *
     * @return list<string>
     */
    private function columnsOf(string $database, string $table): array
    {
        /* @var list<string> */
        return $this->connection->fetchFirstColumn('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$database, $table]);
    }

    /**
     * Nom de la base d'une adresse DATABASE_URL.
     */
    private function databaseName(string $url): string
    {
        return 1 === preg_match('#^[a-z0-9+]+://[^/]*/([^?/]+)#i', $url, $matches) ? $matches[1] : '';
    }
}
