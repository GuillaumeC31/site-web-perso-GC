<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Heartbeat;
use App\Repository\HeartbeatRepository;
use App\Service\Ai\AiModelMonitor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Relit chaque jour la liste des modèles du compte Mistral AI (GET /v1/models : aucune question posée,
 * aucun jeton consommé) et vérifie que les modèles principal et de secours y sont toujours, non archivés.
 * Le résultat alimente la tuile « Assistant IA » du tableau de bord (date de la dernière vérification).
 *
 * À lancer chaque jour par la tâche planifiée de l’hébergeur (3 h 30) :
 *   php bin/console app:ai:refresh-models --env=prod
 *
 * Liens :
 * - Utilise : AiModelMonitor::getModelAlerts(true), HeartbeatRepository (Heartbeat::AI_MODELS)
 * - Voir aussi : app:ai:check (vérification complète, avec appels payants en jetons)
 * - Testée par : tests/Command/AiRefreshModelsCommandTest
 */
#[AsCommand(name: 'app:ai:refresh-models', description: 'Relit la liste des modèles Mistral AI (sans question ni jeton) pour le tableau de bord')]
final class AiRefreshModelsCommand extends Command
{
    /**
     * Reçoit les dépendances.
     *
     * @param AiModelMonitor      $modelMonitor modèles configurés et liste de Mistral AI
     * @param HeartbeatRepository $heartbeats   passage noté pour le tableau de bord
     */
    public function __construct(
        private readonly AiModelMonitor $modelMonitor,
        private readonly HeartbeatRepository $heartbeats,
    ) {
        parent::__construct();
    }

    /**
     * Relit la liste, note le résultat et l'affiche (sans jamais afficher la clé).
     *
     * Liens :
     * - Appelée par : la console Symfony (tâche planifiée quotidienne)
     *
     * @return int Command::SUCCESS si les deux modèles sont disponibles, Command::FAILURE sinon
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $alerts = $this->modelMonitor->getModelAlerts(true);
        $readable = [] === array_filter($alerts, static fn (array $alert): bool => 'unchecked' === $alert['problem']);
        $this->heartbeats->beat(Heartbeat::AI_MODELS, $readable, [
            'primary' => $this->modelMonitor->getPrimaryModel(),
            'fallback' => $this->modelMonitor->getFallbackModel(),
            'alerts' => array_map(static fn (array $alert): string => $alert['role'].':'.$alert['problem'], $alerts),
        ]);

        if ([] === $alerts) {
            $io->success(\sprintf('Modèles disponibles chez Mistral AI : %s (principal), %s (secours).', $this->modelMonitor->getPrimaryModel(), $this->modelMonitor->getFallbackModel()));

            return Command::SUCCESS;
        }
        foreach ($alerts as $alert) {
            $io->warning(\sprintf('Modèle %s (%s) : %s.', 'primary' === $alert['role'] ? 'principal' : 'de secours', $alert['model'] ?: '—', match ($alert['problem']) {
                'missing' => 'absent de la liste (retiré ?)',
                'archived' => 'archivé',
                'unchecked' => 'liste des modèles illisible (clé absente ou service injoignable)',
                'not_configured' => 'non configuré',
            }));
        }

        return Command::FAILURE;
    }
}
