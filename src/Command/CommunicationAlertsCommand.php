<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Heartbeat;
use App\Enum\NotificationEvent;
use App\Repository\HeartbeatRepository;
use App\Service\Dashboard\DashboardMetrics;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Alertes « Général du site » (décision du 2026-10-29), à lancer toutes les heures par la tâche planifiée de l’hébergeur : lit les
 * tuiles du tableau de bord (DashboardMetrics, seule source) et notifie, au plus une fois par 24 h et par type :
 * - alerte de sécurité : tuile Sécurité en « problème » (échecs de connexion, aucune clé YubiKey) ;
 * - e-mails en échec : au moins un e-mail gardé pour un renvoi (Discord et Telegram par défaut) ;
 * - sauvegarde ou tâche planifiée en retard : tuile des tâches en alerte (production seulement) ;
 * - assistant IA : plafond atteint ou modèle retiré (tuile Assistant IA en « problème »).
 * Chiffres seulement, aucune donnée personnelle ; l'e-mail part à l'adresse « Général du site ».
 *
 * Hors production, la commande ne fait qu'afficher ce qu'elle enverrait (aucun envoi réel vers Discord ou
 * Telegram sans demande de Guillaume) ; --send force l'envoi.
 *
 * Usage : php bin/console app:communication:alerts --env=prod
 *
 * Liens :
 * - Utilise : DashboardMetrics::getTiles(true), NotificationDispatcher::notify(), HeartbeatRepository (passage et date
 *   de la dernière alerte de chaque type, Heartbeat::COMMUNICATION_ALERTS)
 * - Voir aussi : NotificationEvent (SecurityAlert, FailedEmail, TaskLate, AiAlert)
 * - Testée par : tests/Command/CommunicationAlertsCommandTest
 */
#[AsCommand(name: 'app:communication:alerts', description: 'Envoie les alertes « Général du site » (sécurité, e-mails en échec, tâches, IA), une fois par jour au plus')]
final class CommunicationAlertsCommand extends Command
{
    /** Délai minimal entre deux alertes du même type (heures). */
    public const MIN_HOURS = 24;

    /** Lignes de détail reprises au plus dans une alerte. */
    private const MAX_LINES = 4;

    /**
     * Reçoit les dépendances.
     *
     * @param string $environment environnement Symfony (envoi réel en production seulement, sauf --send)
     */
    public function __construct(
        private readonly DashboardMetrics $metrics,
        private readonly NotificationDispatcher $notifier,
        private readonly HeartbeatRepository $heartbeats,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'option --send (envoi réel hors production).
     */
    protected function configure(): void
    {
        $this->addOption('send', null, InputOption::VALUE_NONE, 'Envoie aussi hors production (sinon : affichage seulement)');
    }

    /**
     * Évalue les tuiles et envoie les alertes dues.
     *
     * Liens :
     * - Appelée par : la console Symfony (cron toutes les heures)
     *
     * @return int Command::SUCCESS
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $send = 'prod' === $this->environment || (bool) $input->getOption('send');
        $now = $this->clock->now();
        $previous = $this->heartbeats->findAllByName()[Heartbeat::COMMUNICATION_ALERTS] ?? null;
        $lastSent = self::readLastSent($previous?->getDetails()['sent'] ?? []);

        $tiles = array_column($this->metrics->getTiles(true)['tiles'], null, 'id');
        $due = [
            NotificationEvent::SecurityAlert->value => DashboardMetrics::PROBLEM === ($tiles['security']['status'] ?? null) ? $tiles['security'] : null,
            NotificationEvent::FailedEmail->value => (int) ($tiles['communication']['value'] ?? 0) > 0 ? $tiles['communication'] : null,
            NotificationEvent::TaskLate->value => \in_array($tiles['tasks']['status'] ?? null, [DashboardMetrics::PROBLEM, DashboardMetrics::WARNING], true) ? $tiles['tasks'] : null,
            NotificationEvent::AiAlert->value => DashboardMetrics::PROBLEM === ($tiles['ai']['status'] ?? null) ? $tiles['ai'] : null,
            // Turnstile mal configuré (v2.0.3) : erreurs de configuration répétées, formulaires publics bloqués
            NotificationEvent::TurnstileAlert->value => DashboardMetrics::PROBLEM === ($tiles['turnstile']['status'] ?? null) ? $tiles['turnstile'] : null,
        ];

        $sent = 0;
        foreach ($due as $event => $tile) {
            if (null === $tile) {
                continue;
            }
            $last = isset($lastSent[$event]) ? new \DateTimeImmutable($lastSent[$event]) : null;
            if (null !== $last && $last > $now->modify(\sprintf('-%d hours', self::MIN_HOURS))) {
                $io->writeln(\sprintf('%s : déjà signalé le %s.', $event, $last->format('d/m/Y H:i')));
                continue;
            }
            $notification = $this->buildNotification(NotificationEvent::from($event), $tile);
            if (!$send) {
                $io->writeln(\sprintf('%s : « %s » (non envoyé hors production ; --send pour forcer).', $event, $notification->title));
                continue;
            }
            $this->notifier->notify($notification);
            $lastSent[$event] = $now->format(\DATE_ATOM);
            ++$sent;
        }

        // Détails du passage : liste « type|date ISO » (format accepté par HeartbeatRepository::beat())
        $this->heartbeats->beat(Heartbeat::COMMUNICATION_ALERTS, true, ['sent' => array_map(static fn (string $type, string $date): string => $type.'|'.$date, array_keys($lastSent), array_values($lastSent))]);
        $io->success(\sprintf('%d alerte(s) envoyée(s).', $sent));

        return Command::SUCCESS;
    }

    /**
     * Relit la date de la dernière alerte de chaque type (détails du passage : « type|date ISO »).
     *
     * @param mixed $stored valeur gardée dans les détails du passage
     *
     * @return array<string, string> type => date ISO
     */
    private static function readLastSent(mixed $stored): array
    {
        $lastSent = [];
        foreach (\is_array($stored) ? $stored : [] as $entry) {
            if (\is_string($entry) && str_contains($entry, '|')) {
                [$type, $date] = explode('|', $entry, 2);
                $lastSent[$type] = $date;
            }
        }

        return $lastSent;
    }

    /**
     * Construit la notification d'une alerte à partir de sa tuile : titre générique, lignes en alerte (chiffres).
     *
     * @param array{id: string, value: string, value_key: string, lines: list<array{key: string, params: array<string, string|int>, status?: string}>, link: array{route: string, params: array<string, string|int>, label: string}} $tile tuile du tableau de bord
     */
    private function buildNotification(NotificationEvent $event, array $tile): Notification
    {
        $lines = [];
        $count = 0;
        foreach ($tile['lines'] as $line) {
            if (!isset($line['status']) || $count >= self::MAX_LINES) {
                continue;
            }
            ++$count;
            // Paramètres eux-mêmes clés de traduction (ex. rôle d'un modèle) : traduits d'abord
            $params = array_map(fn (string|int $value): string|int => \is_string($value) && str_starts_with($value, 'admin.') ? $this->translator->trans($value) : $value, $line['params']);
            $lines['Détail '.$count] = $this->translator->trans($line['key'], $params);
        }
        if (NotificationEvent::FailedEmail === $event) {
            $lines = ['E-mails gardés pour un renvoi' => $tile['value']] + $lines;
        }

        return new Notification(
            $event,
            $this->translator->trans($event->translationKey()),
            $lines,
            $this->urlGenerator->generate(NotificationEvent::FailedEmail === $event ? 'admin_communication_emails' : 'admin_dashboard', [], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }
}
