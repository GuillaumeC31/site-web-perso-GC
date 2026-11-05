<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\Notification\TelegramClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Déclare auprès de Telegram l'adresse du webhook de la validation à distance (setWebhook, avec le secret de
 * l'en-tête et les seuls appuis sur les boutons), la retire (deleteWebhook) ou affiche son état
 * (getWebhookInfo). Ni le jeton du bot ni le secret ne sont jamais affichés.
 *
 * Usage (sur le serveur, une fois par adresse) :
 *   php bin/console app:telegram:webhook set --env=prod                 (adresse tirée de DEFAULT_URI)
 *   php bin/console app:telegram:webhook set --url=https://…/telegram/webhook
 *   php bin/console app:telegram:webhook info
 *   php bin/console app:telegram:webhook delete
 *
 * Liens :
 * - Utilise : TelegramClient, route telegram_webhook (TelegramWebhookController)
 * - Testé par : tests/Command/TelegramWebhookCommandTest
 */
#[AsCommand(name: 'app:telegram:webhook', description: 'Déclare (set), retire (delete) ou affiche (info) le webhook Telegram de la validation à distance')]
final class TelegramWebhookCommand extends Command
{
    /**
     * Reçoit les dépendances.
     *
     * @param string $secret secret de l'en-tête X-Telegram-Bot-Api-Secret-Token (TELEGRAM_WEBHOOK_SECRET)
     */
    public function __construct(
        private readonly TelegramClient $telegram,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%env(TELEGRAM_WEBHOOK_SECRET)%')]
        private readonly string $secret,
    ) {
        parent::__construct();
    }

    /**
     * Déclare l'action et l'option --url.
     */
    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'set, delete ou info')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Adresse HTTPS du webhook (par défaut : DEFAULT_URI + /telegram/webhook)');
    }

    /**
     * Exécute l'action demandée.
     *
     * Liens :
     * - Appelée par : la console Symfony
     *
     * @return int Command::SUCCESS ; Command::FAILURE si Telegram refuse ou si la configuration manque ; Command::INVALID sinon
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            switch ((string) $input->getArgument('action')) {
                case 'set':
                    $url = (string) ($input->getOption('url') ?? $this->urlGenerator->generate('telegram_webhook', [], UrlGeneratorInterface::ABSOLUTE_URL));
                    if (!str_starts_with($url, 'https://')) {
                        $io->error('Telegram exige une adresse HTTPS publique (reçue : '.$url.').');

                        return Command::FAILURE;
                    }
                    if ('' === $this->secret) {
                        $io->error('TELEGRAM_WEBHOOK_SECRET n\'est pas renseigné dans .env.local.');

                        return Command::FAILURE;
                    }
                    $this->telegram->call('setWebhook', ['url' => $url, 'secret_token' => $this->secret, 'allowed_updates' => ['callback_query', 'message'], 'drop_pending_updates' => true]);
                    $io->success('Webhook déclaré : '.$url.' (appuis sur les boutons et réponses portant le code PIN, secret vérifié).');

                    return Command::SUCCESS;
                case 'delete':
                    $this->telegram->call('deleteWebhook', ['drop_pending_updates' => true]);
                    $io->success('Webhook retiré : les boutons Telegram ne répondent plus.');

                    return Command::SUCCESS;
                case 'info':
                    $info = $this->telegram->call('getWebhookInfo', []);
                    $info = \is_array($info) ? $info : [];
                    $io->definitionList(
                        ['Adresse' => ('' !== ($info['url'] ?? '')) ? $info['url'] : 'aucune'],
                        ['Mises à jour en attente' => (string) ($info['pending_update_count'] ?? 0)],
                        ['Dernière erreur' => isset($info['last_error_message']) ? (string) $info['last_error_message'] : 'aucune'],
                    );

                    return Command::SUCCESS;
                default:
                    $io->error('Action inconnue : set, delete ou info.');

                    return Command::INVALID;
            }
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }
    }
}
