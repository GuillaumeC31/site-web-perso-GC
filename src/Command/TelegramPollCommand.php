<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Heartbeat;
use App\Repository\HeartbeatRepository;
use App\Service\Notification\TelegramCallbackHandler;
use App\Service\Notification\TelegramClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Validation à distance EN LOCAL : le webhook Telegram ne peut pas joindre localhost ; cette commande relève
 * les appuis sur les boutons et les réponses portant le code PIN par getUpdates (attente longue, sans webhook) et
 * les traite avec EXACTEMENT les contrôles du webhook (TelegramCallbackHandler : conversation et compte autorisés,
 * jeton à usage unique, 72 h, code PIN). Enregistrée en développement et en test seulement (#[When]) : elle
 * n'existe pas en production ; elle refuse aussi de tourner si un webhook est déclaré (Telegram n'envoie pas les
 * deux).
 *
 * Chaque passage est noté (battement « telegram_poll » : Paramètres « Relève Telegram : active », avertissement
 * dans les notifications sinon) ; une coupure passagère (réseau, délai) n'arrête pas la relève. Vider le cache de
 * développement pendant qu'elle tourne l'arrête (fichiers du conteneur supprimés) : il suffit de la relancer.
 *
 * Usage : php bin/console app:telegram:poll   (Ctrl+C pour arrêter ; --once : une seule relève, sans attente)
 *
 * Liens :
 * - Utilise : TelegramClient (getWebhookInfo, getUpdates), TelegramCallbackHandler, HeartbeatRepository
 * - Voir aussi : app:telegram:webhook (en ligne)
 * - Testé par : tests/Command/TelegramPollCommandTest
 */
#[When(env: 'dev')]
#[When(env: 'test')]
#[AsCommand(name: 'app:telegram:poll', description: 'Développement : relève les appuis sur les boutons Telegram sans webhook (getUpdates) et les traite')]
final class TelegramPollCommand extends Command
{
    /** Attente longue de getUpdates (secondes). */
    private const LONG_POLL_SECONDS = 25;

    /** Événements relevés : appuis sur les boutons, réponses portant le code PIN. */
    private const UPDATES = ['callback_query', 'message'];

    /**
     * Reçoit les dépendances.
     *
     * @param string $environment environnement (refus en production)
     */
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly TelegramCallbackHandler $handler,
        private readonly HeartbeatRepository $heartbeats,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'option --once.
     */
    protected function configure(): void
    {
        $this->addOption('once', null, InputOption::VALUE_NONE, 'Une seule relève, sans attente (tests)');
    }

    /**
     * Relève et traite les appuis et les réponses jusqu'à l'arrêt (Ctrl+C).
     *
     * Liens :
     * - Appelée par : la console Symfony
     *
     * @return int Command::SUCCESS ; Command::FAILURE en production, si un webhook est déclaré ou si Telegram refuse
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('prod' === $this->environment) {
            $io->error('Commande réservée au développement : en production, utilisez le webhook (app:telegram:webhook set).');

            return Command::FAILURE;
        }
        try {
            $info = $this->telegram->call('getWebhookInfo', []);
            if (\is_array($info) && '' !== (string) ($info['url'] ?? '')) {
                $io->error('Un webhook est déclaré pour ce bot : Telegram n\'envoie alors rien à getUpdates. Retirez-le (app:telegram:webhook delete) ou testez en ligne.');

                return Command::FAILURE;
            }
            $once = (bool) $input->getOption('once');
            if (!$once) {
                $io->success('En attente des appuis sur les boutons Telegram (Ctrl+C pour arrêter).');
            }
            $offset = 0;
            do {
                $this->heartbeats->beat(Heartbeat::TELEGRAM_POLL);
                try {
                    $updates = $this->telegram->call('getUpdates', ['offset' => $offset, 'timeout' => $once ? 0 : self::LONG_POLL_SECONDS, 'allowed_updates' => self::UPDATES], $once ? 5 : self::LONG_POLL_SECONDS + 10);
                } catch (\RuntimeException $exception) {
                    if ($once) {
                        throw $exception;
                    }
                    // Coupure passagère : la relève continue au lieu de s'arrêter
                    $io->warning($exception->getMessage().' Nouvel essai dans 5 s.');
                    sleep(5);
                    continue;
                }
                foreach (\is_array($updates) ? $updates : [] as $update) {
                    $offset = max($offset, (int) ($update['update_id'] ?? 0) + 1);
                    // Jamais le jeton ni le code : seulement l'heure et le type d'événement
                    if (\is_array($update['callback_query'] ?? null)) {
                        $this->handler->handle($update['callback_query']);
                        $io->writeln(\sprintf('%s : appui traité.', date('H:i:s')));
                    } elseif (\is_array($update['message'] ?? null)) {
                        $this->handler->handleMessage($update['message']);
                        $io->writeln(\sprintf('%s : message traité.', date('H:i:s')));
                    }
                }
            } while (!$once);
            if ($offset > 0) {
                // Événements traités marqués comme lus chez Telegram (sinon renvoyés à la relève suivante)
                $this->telegram->call('getUpdates', ['offset' => $offset, 'timeout' => 0, 'allowed_updates' => self::UPDATES]);
            }
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
