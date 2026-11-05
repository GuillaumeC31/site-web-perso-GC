<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Dev\TestTraceCleaner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Aucune trace des tests dans la base de développement (règle du 2026-10-26), et aucune donnée réelle supprimée :
 * seules les lignes identifiées précisément comme venant des tests le sont, jamais selon une plage horaire
 * (décision du 2026-10-29 ; l'ancien instantané de début de suite n'existe plus).
 * Commande enregistrée SEULEMENT en développement (#[When('dev')]) : elle n'existe pas en production.
 *
 * - purge : supprime les traces de test (repères de TestTraceCleaner) ;
 * - check : compte les traces restantes ; échoue (code 1) s'il en reste.
 *
 * Usage : php bin/console app:e2e:traces purge|check
 *
 * Liens :
 * - Appelée par : tests/e2e/support/global-teardown.js (purge puis check), scripts de captures
 * - Utilise : App\Service\Dev\TestTraceCleaner
 */
#[When(env: 'dev')]
#[AsCommand(name: 'app:e2e:traces', description: 'Développement : traces des tests dans la base (purge / check)')]
final class E2eTracesCommand extends Command
{
    /**
     * Reçoit le service de nettoyage.
     */
    public function __construct(private readonly TestTraceCleaner $cleaner)
    {
        parent::__construct();
    }

    /**
     * Déclare l'action.
     */
    protected function configure(): void
    {
        $this->addArgument('action', InputArgument::REQUIRED, 'purge ou check');
    }

    /**
     * Exécute l'action.
     *
     * Liens :
     * - Appelée par : la console Symfony (Playwright, captures)
     *
     * @return int Command::SUCCESS ; Command::FAILURE si check trouve des traces ; Command::INVALID sinon
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        switch ((string) $input->getArgument('action')) {
            case 'purge':
                $counts = array_filter($this->cleaner->purge());
                $output->writeln([] === $counts ? 'Aucune trace de test.' : 'Traces supprimées : '.http_build_query($counts, '', ', '));

                return Command::SUCCESS;
            case 'check':
                $remaining = array_filter($this->cleaner->count());
                if ([] !== $remaining) {
                    $output->writeln('Traces de test restantes : '.http_build_query($remaining, '', ', '));

                    return Command::FAILURE;
                }
                $output->writeln('Base de développement sans trace de test.');

                return Command::SUCCESS;
            default:
                return Command::INVALID;
        }
    }
}
