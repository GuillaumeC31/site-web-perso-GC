<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Enum\EmailType;
use App\Enum\MailCategory;
use App\Enum\NotificationChannel;
use App\Enum\NotificationEvent;
use App\Repository\SiteSettingsRepository;
use App\Service\Dev\TestContext;
use App\Service\Mail\MailFactory;
use App\Service\Mail\MailRecipients;
use App\Service\Mail\ResilientMailer;
use App\Service\Recruiter\AccessRequestSummary;
use App\Service\Recruiter\RemoteDecisionTokens;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoie une notification à Guillaume sur les canaux choisis dans l'admin pour son événement :
 * e-mail (adresse de la catégorie de l'événement : « Recrutement » ou « Général du site », MailRecipients), Discord
 * (DISCORD_WEBHOOK_URL ou application), Telegram (TELEGRAM_BOT_TOKEN et TELEGRAM_CHAT_ID). Un canal coché mais non
 * configuré est ignoré.
 *
 * Validation à distance activée : CHAQUE notification de nouvelle demande en attente porte les boutons
 * (Telegram : callback_data ; Discord : boutons de type lien vers la page de confirmation, avec repli
 * automatique sur des liens dans le texte si Discord refuse les boutons), ou dit ce qui manque pour les
 * proposer (RemoteDecisionTokens). Signaux d'intérêt : bouton « Voir dans l'admin » seulement.
 *
 * Aucun envoi réel vers Discord ou Telegram sans demande de Guillaume (décision du 2026-10-27) : jamais pour un
 * événement venu d'une adresse de test (« example.test », dans tous les environnements), ni en développement
 * pendant une suite de tests ou une vérification (repère E2E_SILENT_FLAG). Le bouton « Envoyer une notification
 * de test » envoie toujours.
 *
 * Envoi immédiat (pas de worker sur l’hébergeur), délai de 3 à 5 s par appel ; une erreur est journalisée (sans
 * adresse ni jeton) et ne bloque jamais le formulaire qui a déclenché la notification.
 *
 * Liens :
 * - Utilisé par : RecruiterAccessManager, ContactController, RecruiterQuestionController, InterestSignalNotifier,
 *   Command\CommunicationAlertsCommand, Admin\SiteSettingsController (maintenance), Admin\CommunicationController
 *   (notification de test, état des canaux)
 * - Utilise : SiteSettingsRepository (canaux), MailRecipients (adresse par catégorie), MailFactory, HttpClient (Discord),
 *   TelegramClient, RemoteDecisionTokens,
 *   AccessRequestSummary::trialNotification() (notification de test)
 * - Testé par : tests/Service/NotificationDispatcherTest
 */
final readonly class NotificationDispatcher
{
    /**
     * Repère des tests et des vérifications, relatif au dossier du projet (développement seulement) : posé par
     * app:e2e:recruiter-journey start (suite Playwright) et par les scripts de captures, retiré à la fin ; Discord
     * et Telegram sont alors coupés.
     */
    public const E2E_SILENT_FLAG = '/var/e2e/silent-chat';

    /**
     * Reçoit les dépendances et la configuration des canaux.
     *
     * @param SiteSettingsRepository $settingsRepository canaux de chaque événement
     * @param ResilientMailer        $mailer             envoi des e-mails (échec gardé pour un renvoi)
     * @param MailRecipients         $recipients         adresse de réception de chaque catégorie
     * @param MailFactory            $mailFactory        e-mails du site (expéditeur)
     * @param HttpClientInterface    $httpClient         appels Discord
     * @param TelegramClient         $telegram           appels Telegram
     * @param RemoteDecisionTokens   $remoteDecisions    boutons et liens de décision à distance
     * @param AccessRequestSummary   $summary            notification de test (boutons d'essai)
     * @param DiscordApp             $discordApp         application Discord (boutons interactifs), si configurée
     * @param TelegramPollStatus     $telegramPoll       état de la relève Telegram en local
     * @param LoggerInterface        $logger             journal des échecs
     * @param string                 $discordWebhook     URL du webhook Discord
     * @param string                 $telegramChatId     identifiant de la conversation Telegram
     * @param string                 $environment        environnement (repère des tests lu en développement seulement)
     * @param string                 $projectDir         dossier du projet (repère des tests)
     * @param TestContext|null       $testContext        requêtes de test (Discord et Telegram coupés)
     */
    public function __construct(
        private SiteSettingsRepository $settingsRepository,
        private ResilientMailer $mailer,
        private MailRecipients $recipients,
        private MailFactory $mailFactory,
        private HttpClientInterface $httpClient,
        private TelegramClient $telegram,
        private RemoteDecisionTokens $remoteDecisions,
        private AccessRequestSummary $summary,
        private DiscordApp $discordApp,
        private TelegramPollStatus $telegramPoll,
        private LoggerInterface $logger,
        #[Autowire('%env(DISCORD_WEBHOOK_URL)%')]
        private string $discordWebhook,
        #[Autowire('%env(TELEGRAM_CHAT_ID)%')]
        private string $telegramChatId,
        #[Autowire('%kernel.environment%')]
        private string $environment,
        #[Autowire('%kernel.project_dir%')]
        private string $projectDir,
        private ?TestContext $testContext = null,
    ) {
    }

    /**
     * Envoie la notification sur chaque canal actif de son événement.
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager, ContactController::show(), InterestSignalNotifier
     *
     * @return list<NotificationChannel> canaux sur lesquels l'envoi a réussi
     */
    public function notify(Notification $notification): array
    {
        $sent = [];
        foreach ($this->settingsRepository->getOrCreate()->getChannels($notification->event) as $channel) {
            if (!$this->isConfigured($channel, $notification->event)) {
                continue;
            }
            if (NotificationChannel::Email !== $channel && null !== $reason = $this->silenceReason($notification)) {
                $this->logger->info('Notification {channel} non envoyée : {reason}.', ['channel' => $channel->value, 'reason' => $reason]);
                continue;
            }
            try {
                if ($this->send($channel, $notification)) {
                    $sent[] = $channel;
                }
            } catch (\RuntimeException $exception) {
                $this->logger->error('Notification {channel} non envoyée : {message}', ['channel' => $channel->value, 'message' => $exception->getMessage()]);
            }
        }

        return $sent;
    }

    /**
     * Envoie une notification de test sur un canal (bouton de Paramètres), même s'il n'est coché pour aucun
     * événement : texte d'essai et, si la validation à distance est active, boutons ou liens d'ESSAI qui suivent
     * tout le parcours sans rien décider.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController::testChannel()
     *
     * @return string|null null si l'envoi a réussi ; sinon l'erreur, claire et sans jeton ni adresse
     */
    public function sendTest(NotificationChannel $channel): ?string
    {
        $trial = $this->summary->trialNotification();
        if (!$this->isConfigured($channel, $trial->event)) {
            return match ($channel) {
                NotificationChannel::Email => 'aucune adresse « Recrutement » n\'est enregistrée (Communication › E-mails).',
                NotificationChannel::Discord => 'DISCORD_WEBHOOK_URL n\'est pas renseignée dans .env.local.',
                NotificationChannel::Telegram => 'TELEGRAM_BOT_TOKEN et TELEGRAM_CHAT_ID doivent être renseignés dans .env.local.',
            };
        }
        try {
            $sent = $this->send($channel, $trial);
        } catch (\RuntimeException $exception) {
            return $exception->getMessage();
        }

        return $sent ? null : 'l\'e-mail n\'a pas pu partir (gardé pour un renvoi : Communication › E-mails).';
    }

    /**
     * Indique si un canal est configuré : adresse de la catégorie de l'événement pour l'e-mail (sans événement :
     * au moins une adresse « Recrutement » ou « Général du site »), variables de .env.local pour Discord et Telegram.
     *
     * Liens :
     * - Appelée par : notify(), sendTest(), Admin\CommunicationController (état des canaux), DashboardMetrics
     */
    public function isConfigured(NotificationChannel $channel, ?NotificationEvent $event = null): bool
    {
        return match ($channel) {
            NotificationChannel::Email => null !== $event
                ? null !== $this->recipients->addressFor($event->category())
                : null !== $this->recipients->addressFor(MailCategory::Recruitment) || null !== $this->recipients->addressFor(MailCategory::General),
            NotificationChannel::Discord => '' !== $this->discordWebhook || $this->discordApp->isConfigured(),
            NotificationChannel::Telegram => $this->telegram->hasToken() && '' !== $this->telegramChatId,
        };
    }

    /**
     * Raison de ne PAS envoyer vers Discord et Telegram, ou null : adresse de test (tous les environnements), requête
     * d'un navigateur ou compte de test (développement, Dev\TestContext), ou repère des tests posé pendant une
     * commande (développement). Une action réelle faite dans un navigateur normal pendant une suite est notifiée
     * normalement (décision du 2026-10-29).
     *
     * Liens :
     * - Appelée par : notify()
     */
    private function silenceReason(Notification $notification): ?string
    {
        return match (true) {
            $notification->isFromTestDomain() => 'adresse de test (example.test)',
            true === $this->testContext?->isTestRequest() => 'requête de test (navigateur ou compte de test)',
            'dev' === $this->environment && \PHP_SAPI === 'cli' && is_file($this->projectDir.self::E2E_SILENT_FLAG) => 'tests ou vérifications en cours (repère var/e2e/silent-chat)',
            default => null,
        };
    }

    /**
     * Envoie un message au webhook Discord (jamais de mention).
     *
     * @param array<string, mixed> $payload    contenu et, le cas échéant, composants
     * @param bool                 $components vrai : ajoute with_components=true, sans lequel Discord ignore les composants
     *                                         d'un webhook qui n'appartient pas à une application
     *
     * @return int code HTTP de la réponse
     *
     * @throws \RuntimeException si Discord est injoignable (message sans adresse ni jeton)
     */
    private function postDiscord(array $payload, bool $components): int
    {
        $url = $components ? $this->discordWebhook.(str_contains($this->discordWebhook, '?') ? '&' : '?').'with_components=true' : $this->discordWebhook;
        try {
            return $this->httpClient->request('POST', $url, ['json' => $payload + ['allowed_mentions' => ['parse' => []]], 'timeout' => 3])->getStatusCode();
        } catch (ExceptionInterface) {
            throw new \RuntimeException('Discord est injoignable (réseau ou délai dépassé).');
        }
    }

    /**
     * Vérifie la réponse de Discord.
     *
     * @return bool vrai si l'envoi est accepté
     *
     * @throws \RuntimeException si Discord refuse l'envoi (message clair, sans adresse ni jeton)
     */
    private function checkDiscordStatus(int $status): bool
    {
        if ($status >= 400) {
            throw new \RuntimeException(\sprintf('Discord a refusé l\'envoi (HTTP %d%s).', $status, 404 === $status ? ' : webhook inconnu ou supprimé' : ''));
        }

        return true;
    }

    /**
     * Envoie la notification sur un canal configuré.
     *
     * @return bool vrai si l'envoi a réussi ; faux si l'e-mail est gardé pour un renvoi
     *
     * @throws \RuntimeException si Discord ou Telegram refuse l'envoi (message sans jeton ni adresse)
     */
    private function send(NotificationChannel $channel, Notification $notification): bool
    {
        // Boutons et liens : nouvelle demande en attente, ou essai (notification de test)
        $decides = null !== $notification->decisionRequest || $notification->trial;
        switch ($channel) {
            case NotificationChannel::Email:
                $category = $notification->event->category();
                $email = $this->mailFactory->createTyped(EmailType::Notification, (string) $this->recipients->addressFor($category), ['notification' => $notification], subjectSuffix: ' '.$notification->title);

                return $this->mailer->send($email, ResilientMailer::KIND_NOTIFICATION, category: $category);
            case NotificationChannel::Discord:
                $tokens = $decides ? $this->remoteDecisions->discordTokens($notification->decisionRequest, $notification->trial) : null;
                $links = null === $tokens ? null : $this->remoteDecisions->linksFor($tokens);
                $baseNotes = $decides ? array_filter([$this->remoteDecisions->discordUnavailable()]) : [];
                $local = null !== $links && RemoteDecisionTokens::isLocalUrl($links['approve']);
                // Application Discord : vrais boutons, décision et code PIN dans Discord (repli : webhook)
                if ($notification->chatDetails && $this->discordApp->isConfigured()) {
                    $appNotes = $local ? [...$baseNotes, 'Site en local : Discord ne peut pas joindre ce poste, les boutons ne répondront qu\'une fois le site en ligne.'] : $baseNotes;
                    try {
                        $status = $this->discordApp->sendMessage(['content' => $notification->toDiscordText($tokens, $appNotes, true), 'components' => $notification->discordInteractiveComponents($tokens)]);
                    } catch (\RuntimeException $exception) {
                        if ('' === $this->discordWebhook) {
                            throw $exception;
                        }
                        $status = 0;
                    }
                    if ($status >= 200 && $status < 400) {
                        return true;
                    }
                    if ('' === $this->discordWebhook) {
                        return $this->checkDiscordStatus($status);
                    }
                    $this->logger->warning('Discord (application) n\'a pas envoyé le message ({status}) : repli sur le webhook.', ['status' => 0 === $status ? 'injoignable' : 'HTTP '.$status]);
                }
                $notes = $local ? [...$baseNotes, 'Site en local : ces liens pointent vers localhost et ne s\'ouvrent que depuis ce PC.'] : $baseNotes;
                // Webhook : nouvelle demande, signal d'intérêt, essai : boutons de type lien ; sinon, texte seul
                if ($notification->chatDetails) {
                    $status = $this->postDiscord(['content' => $notification->toDiscordText($links, $notes, true), 'components' => $notification->discordComponents($links)], true);
                    if (400 !== $status) {
                        return $this->checkDiscordStatus($status);
                    }
                    // Boutons refusés (ex. adresse non acceptée par Discord) : repli sur les liens dans le texte
                    $this->logger->warning('Discord a refusé les boutons (HTTP 400) : repli sur les liens dans le texte.');
                }

                return $this->checkDiscordStatus($this->postDiscord(['content' => $notification->toDiscordText($links, $notes)], false));
            case NotificationChannel::Telegram:
                $keyboard = $notification->chatDetails ? $this->remoteDecisions->telegramKeyboard($decides ? $notification->decisionRequest : null, $notification->adminUrl, $decides && $notification->trial) : null;
                $notes = $decides ? array_filter([$this->remoteDecisions->telegramUnavailable()]) : [];
                // En local, sans relève (app:telegram:poll), les boutons ne répondent pas : le dire
                if (null !== $keyboard && isset($keyboard['inline_keyboard'][0][0]['callback_data']) && null !== $warning = $this->telegramPoll->warning()) {
                    $notes[] = $warning;
                }
                $parameters = [
                    'chat_id' => $this->telegramChatId,
                    'text' => $notification->toTelegramHtml(!RemoteDecisionTokens::isPublicUrl($notification->adminUrl), $notes),
                    'parse_mode' => 'HTML',
                    'link_preview_options' => ['is_disabled' => true],
                ];
                if (null !== $keyboard) {
                    $parameters['reply_markup'] = $keyboard;
                }
                $this->telegram->call('sendMessage', $parameters);

                return true;
        }
    }
}
