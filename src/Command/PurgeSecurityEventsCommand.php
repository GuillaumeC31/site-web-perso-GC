<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Heartbeat;
use App\Repository\HeartbeatRepository;
use App\Repository\SecurityEventRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Supprime les lignes du journal des connexions de plus de 90 jours (RGPD : adresses IP).
 *
 * À lancer chaque jour par la tâche planifiée de l’hébergeur :
 *   php bin/console app:security-event:purge --env=prod
 *
 * Liens :
 * - Utilise : SecurityEventRepository::deleteOlderThan()
 */
#[AsCommand(name: 'app:security-event:purge', description: 'Supprime les lignes du journal des connexions de plus de 90 jours')]
final class PurgeSecurityEventsCommand extends Command
{
    /** Durée de conservation du journal. */
    public const RETENTION = '-90 days';

    /**
     * Reçoit les dépendances.
     *
     * @param SecurityEventRepository $securityEventRepository dépôt du journal
     * @param ClockInterface          $clock                   horloge (remplaçable en test)
     * @param HeartbeatRepository     $heartbeats              passage noté pour le tableau de bord
     */
    public function __construct(
        private readonly SecurityEventRepository $securityEventRepository,
        private readonly ClockInterface $clock,
        private readonly HeartbeatRepository $heartbeats,
    ) {
        parent::__construct();
    }

    /**
     * Supprime les lignes trop anciennes et affiche leur nombre.
     *
     * Liens :
     * - Appelée par : la console Symfony (cron quotidien)
     *
     * @return int Command::SUCCESS
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = $this->clock->now()->modify(self::RETENTION);
        $deleted = $this->securityEventRepository->deleteOlderThan($limit);

        $this->heartbeats->beat(Heartbeat::SECURITY_PURGE, true, ['deleted' => $deleted]);
        (new SymfonyStyle($input, $output))->success(\sprintf('%d ligne(s) du journal antérieure(s) au %s supprimée(s).', $deleted, $limit->format('d/m/Y')));

        return Command::SUCCESS;
    }
}
