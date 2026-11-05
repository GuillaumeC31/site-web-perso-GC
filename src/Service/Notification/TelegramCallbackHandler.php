<?php

declare(strict_types=1);

namespace App\Service\Notification;

use App\Entity\RemoteDecisionToken;
use App\Enum\NotificationChannel;
use App\Enum\RemoteDecision;
use App\Service\Recruiter\AccessRequestSummary;
use App\Service\Recruiter\RemoteDecisionExecutor;
use App\Service\Recruiter\RemoteDecisionTokens;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Traite les appuis sur les boutons Telegram et les réponses portant le code PIN, avec les MÊMES contrôles
 * quelle que soit la façon dont ils arrivent : webhook en ligne (TelegramWebhookController) ou relève en local
 * (app:telegram:poll).
 *
 * Parcours (décision du 2026-10-27 : le code PIN remplace la double confirmation « Oui / Non ») :
 * 1. appui sur « ✅ Accepter » ou « ❌ Refuser » (callback « p:jeton ») : conversation ET compte autorisés, jeton
 *    connu, non expiré (72 h), non utilisé, non bloqué, demande en attente ; le bot demande « Saisissez votre code
 *    PIN » en réponse forcée (force_reply) ;
 * 2. réponse à cette demande : le message qui porte le code est SUPPRIMÉ aussitôt (deleteMessage), puis le code
 *    est vérifié (même code que Discord, 5 essais puis jeton bloqué) ; bon code : décision appliquée comme
 *    depuis l'admin, message d'origine modifié (« ✅ Accepté le 27/10 à 14 h 05 »), boutons retirés.
 * Chaque action et chaque refus sont notés au journal de sécurité, jamais le jeton ni le code.
 *
 * Liens :
 * - Utilisé par : TelegramWebhookController (après contrôle du secret), TelegramPollCommand
 * - Utilise : TelegramAccess, RemoteDecisionTokens, RemoteDecisionExecutor, AccessRequestSummary, TelegramClient
 * - Testé par : tests/Controller/TelegramWebhookControllerTest, tests/Command/TelegramPollCommandTest
 */
final readonly class TelegramCallbackHandler
{
    /** Fuseau des dates affichées. */
    private const TIMEZONE = 'Europe/Paris';

    /** Motifs de refus présentés à Guillaume (réponse à l'appui et message modifié). */
    private const ERROR_TEXTS = [
        RemoteDecisionTokens::ERROR_DISABLED => 'La validation à distance est désactivée (Paramètres de l\'admin).',
        RemoteDecisionTokens::ERROR_UNKNOWN => 'Bouton inconnu : utilisez l\'admin.',
        RemoteDecisionTokens::ERROR_EXPIRED => 'Bouton expiré (72 h) : décidez depuis l\'admin.',
        RemoteDecisionTokens::ERROR_USED => 'Bouton déjà utilisé.',
        RemoteDecisionTokens::ERROR_BLOCKED => 'Bouton bloqué (trop de codes PIN faux) : décidez depuis l\'admin.',
        RemoteDecisionTokens::ERROR_PROCESSED => 'Demande déjà traitée.',
    ];

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private TelegramAccess $access,
        private RemoteDecisionTokens $tokens,
        private RemoteDecisionExecutor $executor,
        private AccessRequestSummary $summary,
        private TelegramClient $telegram,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Traite un appui sur un bouton (callback_query de l'API Telegram) : demande le code PIN.
     *
     * Liens :
     * - Appelée par : TelegramWebhookController::__invoke(), TelegramPollCommand
     *
     * @param array<string, mixed> $callback callback_query reçue
     */
    public function handle(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $chatId = (string) ($callback['message']['chat']['id'] ?? '');
        if (!$this->access->isAllowed($chatId, (string) ($callback['from']['id'] ?? ''))) {
            $this->executor->reject(NotificationChannel::Telegram, 'foreign_chat');
            $this->answer($callbackId, 'Action non autorisée.');

            return;
        }
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $data = (string) ($callback['data'] ?? '');
        if (1 !== preg_match('/^p:('.RemoteDecisionTokens::TOKEN_PATTERN.')$/', $data, $matches)) {
            $this->executor->reject(NotificationChannel::Telegram, 'unknown');
            $this->answer($callbackId, self::ERROR_TEXTS[RemoteDecisionTokens::ERROR_UNKNOWN]);

            return;
        }
        ['token' => $token, 'error' => $error] = $this->tokens->check($matches[1], NotificationChannel::Telegram);
        if (null !== $error || null === $token) {
            $this->refuse((string) $error, $token, $callbackId, $messageId);

            return;
        }
        if (!$this->tokens->hasPin()) {
            $this->answer($callbackId, 'Aucun code PIN défini : Paramètres › Validation à distance.');

            return;
        }
        $this->askPin($token, $messageId, null);
        $this->answer($callbackId, 'Saisissez votre code PIN.');
    }

    /**
     * Traite un message reçu : seule une RÉPONSE à une demande de code PIN compte. Le message (qui porte le code)
     * est supprimé aussitôt, puis le code est vérifié.
     *
     * Liens :
     * - Appelée par : TelegramWebhookController::__invoke(), TelegramPollCommand
     *
     * @param array<string, mixed> $message message reçu (update « message »)
     */
    public function handleMessage(array $message): void
    {
        $promptId = (int) ($message['reply_to_message']['message_id'] ?? 0);
        $chatId = (string) ($message['chat']['id'] ?? '');
        if (0 === $promptId) {
            return;
        }
        if (!$this->access->isAllowed($chatId, (string) ($message['from']['id'] ?? ''))) {
            $this->executor->reject(NotificationChannel::Telegram, 'foreign_chat');

            return;
        }
        $token = $this->tokens->findByTelegramPrompt($promptId);
        if (null === $token) {
            return;
        }
        // Le code PIN ne reste pas dans la conversation
        $this->call('deleteMessage', ['chat_id' => $this->access->allowedChatId(), 'message_id' => (int) ($message['message_id'] ?? 0)]);
        $this->call('deleteMessage', ['chat_id' => $this->access->allowedChatId(), 'message_id' => $promptId]);
        $messageId = (int) $token->getTelegramMessageId();
        $error = $this->tokens->stateError($token);
        if (null !== $error) {
            $this->refuse($error, $token, '', $messageId);

            return;
        }
        $remaining = $this->executor->checkPin($token, trim((string) ($message['text'] ?? '')));
        if (0 === $remaining) {
            $this->edit($messageId, $this->textOf($token)."\n\n<b>".Notification::escape(self::ERROR_TEXTS[RemoteDecisionTokens::ERROR_BLOCKED]).'</b>', null);
            $token->setTelegramPrompt($messageId, null);
            $this->entityManager->flush();

            return;
        }
        if (null !== $remaining) {
            $this->askPin($token, $messageId, $remaining);

            return;
        }
        $at = $this->executor->execute($token)->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $token->setTelegramPrompt($messageId, null);
        $this->entityManager->flush();
        $when = \sprintf('le %s à %s', $at->format('d/m'), $at->format('H \h i'));
        $result = match (true) {
            $token->isTrial() => \sprintf('✅ Essai réussi %s : rien n\'a été décidé (notification de test).', $when),
            RemoteDecision::Approve === $token->getDecision() => \sprintf('✅ Accepté %s : accès ouvert pour 90 jours, e-mail envoyé au recruteur.', $when),
            default => \sprintf('❌ Refusé %s.', $when),
        };
        $this->edit($messageId, $this->textOf($token)."\n\n<b>".Notification::escape($result).'</b>', null);
    }

    /**
     * Envoie la demande du code PIN (réponse forcée) et la note sur le jeton.
     *
     * @param int|null $remaining essais restants après un code faux, ou null (première demande)
     */
    private function askPin(RemoteDecisionToken $token, int $messageId, ?int $remaining): void
    {
        $recruiter = $token->getRecruiter();
        $who = null === $recruiter ? 'la demande d\'essai' : \sprintf('%s (%s)', $recruiter->getFullName(), $recruiter->getCompany());
        $action = RemoteDecision::Approve === $token->getDecision() ? 'accepter' : 'refuser';
        $text = (null === $remaining ? '' : \sprintf("Code PIN incorrect : encore %d essai%s.\n", $remaining, $remaining > 1 ? 's' : ''))
            .\sprintf('🔐 Saisissez votre code PIN pour %s %s (répondez à ce message).', $action, $who);
        $sent = $this->call('sendMessage', [
            'chat_id' => $this->access->allowedChatId(),
            'text' => $text,
            'reply_markup' => ['force_reply' => true, 'input_field_placeholder' => 'Code PIN', 'selective' => true],
        ]);
        $token->setConfirmRequestedAt($this->clock->now())->setTelegramPrompt($messageId, \is_array($sent) ? (int) ($sent['message_id'] ?? 0) ?: null : null);
        $this->entityManager->flush();
    }

    /**
     * Texte d'origine du message des boutons (demande ou essai), reconstruit.
     */
    private function textOf(RemoteDecisionToken $token): string
    {
        $recruiter = $token->getRecruiter();
        $notification = null === $recruiter ? $this->summary->trialNotification() : $this->summary->notification($recruiter);

        return $notification->toTelegramHtml(!RemoteDecisionTokens::isPublicUrl($notification->adminUrl));
    }

    /**
     * Répond à un appui ou à un code refusé (message clair) ; le message perd ses boutons si le refus est définitif.
     *
     * @param string $error motif (RemoteDecisionTokens::ERROR_*)
     */
    private function refuse(string $error, ?RemoteDecisionToken $token, string $callbackId, int $messageId): void
    {
        $text = self::ERROR_TEXTS[$error] ?? self::ERROR_TEXTS[RemoteDecisionTokens::ERROR_UNKNOWN];
        // Demande déjà traitée : pas un refus de sécurité
        if (RemoteDecisionTokens::ERROR_PROCESSED !== $error) {
            $this->executor->reject(NotificationChannel::Telegram, $error, $token);
        }
        if (null !== $token && $messageId > 0) {
            $this->edit($messageId, $this->textOf($token)."\n\n<b>".Notification::escape($text).'</b>', null);
        }
        $this->answer($callbackId, $text);
    }

    /**
     * Modifie le message des boutons.
     *
     * @param array<string, mixed>|null $keyboard nouveaux boutons ; null : aucun
     */
    private function edit(int $messageId, string $html, ?array $keyboard): void
    {
        $this->call('editMessageText', ['chat_id' => $this->access->allowedChatId(), 'message_id' => $messageId, 'text' => $html, 'parse_mode' => 'HTML', 'link_preview_options' => ['is_disabled' => true], 'reply_markup' => $keyboard ?? ['inline_keyboard' => []]]);
    }

    /**
     * Répond à l'appui (bulle affichée par Telegram, fin de l'attente du bouton).
     */
    private function answer(string $callbackId, string $text): void
    {
        if ('' !== $callbackId) {
            $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId, 'text' => $text, 'show_alert' => false]);
        }
    }

    /**
     * Appelle l'API Telegram ; un échec est journalisé (sans jeton ni code) sans bloquer la suite.
     *
     * @param array<string, mixed> $parameters
     *
     * @return array<mixed>|bool|null résultat, ou null en cas d'échec
     */
    private function call(string $method, array $parameters): array|bool|null
    {
        try {
            return $this->telegram->call($method, $parameters);
        } catch (\RuntimeException $exception) {
            $this->logger->error('Telegram : {method} non abouti : {message}', ['method' => $method, 'message' => $exception->getMessage()]);

            return null;
        }
    }
}
