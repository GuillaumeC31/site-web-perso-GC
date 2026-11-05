<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\TelegramWebhookController;
use App\Entity\Recruiter;
use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Enum\SecurityEventType;
use App\Service\Notification\TelegramAccess;
use App\Service\Notification\TelegramCallbackHandler;
use App\Service\Recruiter\RecruiterAccessManager;
use App\Service\Recruiter\RemoteDecisionExecutor;
use App\Service\Recruiter\RemoteDecisionTokens;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RemoteDecisionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpFoundation\Request;

/**
 * Webhook Telegram de la validation à distance, simulé (le vrai webhook ne fonctionne qu'en ligne) : secret
 * de l'en-tête, conversation et compte autorisés, deux appuis, usage unique, expiration (72 h), demande déjà
 * traitée, « Non », interrupteur ; même traitement que l'admin ; journal de sécurité sans jeton.
 *
 * Liens :
 * - Couvre : App\Controller\TelegramWebhookController, RemoteDecisionTokens, RemoteDecisionExecutor
 */
final class TelegramWebhookControllerTest extends WebTestCase
{
    use ClockSensitiveTrait;
    use RemoteDecisionTestTrait;

    /** Secret du webhook de .env.test. */
    private const SECRET = 'secret-de-test-du-webhook';

    /** Conversation et compte autorisés de .env.test. */
    private const CHAT_ID = 42;

    private KernelBrowser $client;

    /**
     * Démarre un client et vide la simulation HTTP.
     */
    protected function setUp(): void
    {
        $this->client = self::createClient();
        MockHttpResponseFactory::reset();
    }

    /**
     * Vérifie qu'un appel sans secret, ou avec un secret faux, est refusé (403) et noté, sans aucun effet.
     */
    public function testMissingOrWrongSecretIsRefused(): void
    {
        $this->enableRemoteValidation();
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);

        $this->press('p:'.$tokens['approve'], secret: null);
        self::assertResponseStatusCodeSame(403);
        $this->press('p:'.$tokens['approve'], secret: 'secret-faux');
        self::assertResponseStatusCodeSame(403);

        self::assertSame(['telegram secret_missing', 'telegram secret_invalid'], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
        self::assertSame([], MockHttpResponseFactory::telegramCalls('editMessageText'));
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
    }

    /**
     * Vérifie que l'adresse publique du webhook refuse toute requête sans
     * le bon secret : secret non configuré sur le serveur (même avec un en-tête), GET, corps quelconque.
     */
    public function testWebhookRefusesEverythingWithoutTheRightSecret(): void
    {
        $controller = new TelegramWebhookController(static::getContainer()->get(TelegramCallbackHandler::class), static::getContainer()->get(RemoteDecisionExecutor::class), '');
        foreach (['', 'secret-de-test-du-webhook', 'x'] as $header) {
            $request = Request::create('/telegram/webhook', 'POST', server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => $header], content: '{"callback_query":{"data":"p:x"}}');
            self::assertSame(403, $controller($request)->getStatusCode());
        }
        self::assertSame(['telegram secret_not_configured', 'telegram secret_not_configured', 'telegram secret_not_configured'], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));

        $this->client->request('GET', '/telegram/webhook', server: ['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET]);
        self::assertResponseStatusCodeSame(405);
        $this->client->request('POST', '/telegram/webhook', server: ['CONTENT_TYPE' => 'application/json'], content: 'pas du JSON');
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], MockHttpResponseFactory::telegramCalls('answerCallbackQuery'));
    }

    /**
     * Vérifie le circuit d'essai (boutons de la notification de test) : appui, code PIN, « Essai réussi », aucune
     * demande touchée, aucun e-mail ; le bouton ne sert qu'une fois.
     */
    public function testTrialButtonsFollowTheWholeFlowWithoutDeciding(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $tokens = $this->issueTokens(null, NotificationChannel::Telegram);

        $this->press('p:'.$tokens['approve']);
        self::assertStringContainsString('code PIN pour accepter la demande d\'essai', MockHttpResponseFactory::telegramCalls('sendMessage')[0]['text']);
        $this->reply($this->promptOf($tokens['approve']), self::TEST_PIN);
        self::assertMatchesRegularExpression('#✅ Essai réussi le \d{2}/\d{2} à \d{2} h \d{2} : rien n\'a été décidé#', MockHttpResponseFactory::telegramCalls('editMessageText')[0]['text']);
        self::assertEmailCount(0);
        self::assertSame(['telegram approve essai'], $this->securityUsernames(SecurityEventType::RemoteDecision));
        $this->press('p:'.$tokens['approve']);
        self::assertSame('Bouton déjà utilisé.', array_slice(MockHttpResponseFactory::telegramCalls('answerCallbackQuery'), -1)[0]['text']);
    }

    /**
     * Vérifie le compte autorisé : TELEGRAM_USER_ID, ou la conversation privée si la variable est vide ; jamais
     * déduit d'une conversation de groupe (identifiant négatif).
     */
    public function testAllowedAccountFallsBackToPrivateChatOnly(): void
    {
        self::assertTrue((new TelegramAccess('42', ''))->isAllowed('42', '42'));
        self::assertFalse((new TelegramAccess('42', ''))->isAllowed('42', '43'));
        self::assertTrue((new TelegramAccess('42', '7'))->isAllowed('42', '7'));
        self::assertFalse((new TelegramAccess('42', '7'))->isAllowed('42', '42'));
        self::assertFalse((new TelegramAccess('-1001', ''))->isAllowed('-1001', '1001'));
        self::assertSame('TELEGRAM_USER_ID manquant (conversation de groupe)', (new TelegramAccess('-1001', ''))->missing());
        self::assertSame('TELEGRAM_CHAT_ID manquant', (new TelegramAccess('', ''))->missing());
    }

    /**
     * Vérifie qu'un appui venu d'une autre conversation ou d'un autre compte est refusé et noté.
     */
    public function testOtherChatOrUserIsRefused(): void
    {
        $this->enableRemoteValidation();
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);

        $this->press('p:'.$tokens['approve'], chatId: 777);
        self::assertResponseIsSuccessful();
        $this->press('p:'.$tokens['approve'], userId: 777);

        self::assertSame(['telegram foreign_chat', 'telegram foreign_chat'], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
        self::assertSame('Action non autorisée.', MockHttpResponseFactory::telegramCalls('answerCallbackQuery')[0]['text']);
        self::assertSame([], MockHttpResponseFactory::telegramCalls('editMessageText'));
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
    }

    /**
     * Vérifie le parcours complet : appui (code PIN demandé en réponse forcée, rien n'est décidé), code faux
     * (message supprimé, nouvelle demande, essai compté), bon code (message supprimé, accès ouvert, e-mail de
     * validation comme depuis l'admin, résultat daté, boutons retirés), puis rejeu refusé ; jamais le code au
     * journal.
     */
    public function testApproveWithPinIsSingleUse(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);

        $this->press('p:'.$tokens['approve']);
        self::assertResponseIsSuccessful();
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        $prompt = MockHttpResponseFactory::telegramCalls('sendMessage')[0];
        self::assertStringContainsString('Saisissez votre code PIN pour accepter Camille Martin (Acme)', $prompt['text']);
        self::assertTrue($prompt['reply_markup']['force_reply']);
        self::assertSame('Saisissez votre code PIN.', MockHttpResponseFactory::telegramCalls('answerCallbackQuery')[0]['text']);

        // Code faux : message supprimé, nouvelle demande
        $this->reply($this->promptOf($tokens['approve']), '000000', messageId: 501);
        self::assertContains(501, array_column(MockHttpResponseFactory::telegramCalls('deleteMessage'), 'message_id'));
        self::assertStringContainsString('Code PIN incorrect : encore 4 essais.', MockHttpResponseFactory::telegramCalls('sendMessage')[1]['text']);
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        self::assertEmailCount(0);

        // Bon code : message supprimé, décision appliquée
        $this->reply($this->promptOf($tokens['approve']), self::TEST_PIN, messageId: 502);
        self::assertContains(502, array_column(MockHttpResponseFactory::telegramCalls('deleteMessage'), 'message_id'));
        self::assertSame(RecruiterStatus::Approved, $this->reloadStatus($recruiter));
        self::assertEmailCount(1);
        self::assertEmailAddressContains(self::getMailerMessage(), 'To', 'rh@acme.test');
        $final = MockHttpResponseFactory::telegramCalls('editMessageText')[0];
        self::assertSame(10, $final['message_id']);
        self::assertMatchesRegularExpression('#✅ Accepté le \d{2}/\d{2} à \d{2} h \d{2}#', $final['text']);
        self::assertSame([], $final['reply_markup']['inline_keyboard']);
        self::assertSame(['telegram approve #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecision));
        self::assertSame(['telegram pin_invalid #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));

        // Rejeu du même bouton : refusé, noté, sans nouvel effet
        $this->press('p:'.$tokens['approve']);
        self::assertSame('Bouton déjà utilisé.', array_slice(MockHttpResponseFactory::telegramCalls('answerCallbackQuery'), -1)[0]['text']);
        self::assertEmailCount(0);
        foreach ([...$this->securityUsernames(SecurityEventType::RemoteDecisionRejected), ...$this->securityUsernames(SecurityEventType::RemoteDecision)] as $username) {
            self::assertStringNotContainsString(self::TEST_PIN, (string) $username);
        }
    }

    /**
     * Vérifie le blocage après 5 codes faux, le refus d'une réponse venue d'un autre compte et l'absence de code PIN.
     */
    public function testWrongPinsBlockTheButtonAndForeignRepliesAreRefused(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);
        $this->press('p:'.$tokens['refuse']);

        // Réponse d'un autre compte : refusée, rien n'est supprimé ni décidé
        $this->reply($this->promptOf($tokens['refuse']), self::TEST_PIN, userId: 777);
        self::assertSame([], MockHttpResponseFactory::telegramCalls('deleteMessage'));
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));

        foreach (range(1, 5) as $attempt) {
            $this->reply($this->promptOf($tokens['refuse']), '111111');
        }
        self::assertStringContainsString('Bouton bloqué', array_slice(MockHttpResponseFactory::telegramCalls('editMessageText'), -1)[0]['text']);
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        $this->press('p:'.$tokens['refuse']);
        self::assertStringContainsString('Bouton bloqué', array_slice(MockHttpResponseFactory::telegramCalls('answerCallbackQuery'), -1)[0]['text']);

        // Sans code PIN défini : le bouton ne demande rien
        $this->enableRemoteValidation(clearPin: true);
        $other = $this->issueTokens(static::getContainer()->get(EntityManagerInterface::class)->find(Recruiter::class, $recruiter->getId()), NotificationChannel::Telegram);
        $this->press('p:'.$other['approve']);
        self::assertStringContainsString('Aucun code PIN défini', array_slice(MockHttpResponseFactory::telegramCalls('answerCallbackQuery'), -1)[0]['text']);
    }

    /**
     * Vérifie le refus : même traitement que l'admin (e-mail de refus au demandeur, sans adresse de réponse).
     */
    public function testRefuseAppliesAdminTreatment(): void
    {
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);

        $this->press('p:'.$tokens['refuse']);
        self::assertStringContainsString('code PIN pour refuser Camille Martin (Acme)', MockHttpResponseFactory::telegramCalls('sendMessage')[0]['text']);
        $this->reply($this->promptOf($tokens['refuse']), self::TEST_PIN);

        self::assertSame(RecruiterStatus::Refused, $this->reloadStatus($recruiter));
        self::assertEmailCount(1);
        self::assertSame(['telegram refuse #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecision));
        self::assertMatchesRegularExpression('#❌ Refusé le \d{2}/\d{2} à \d{2} h \d{2}#', MockHttpResponseFactory::telegramCalls('editMessageText')[0]['text']);
    }

    /**
     * Vérifie qu'un bouton expire après 72 h.
     */
    public function testButtonExpiresAfter72Hours(): void
    {
        $this->enableRemoteValidation();
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);

        self::mockTime('+72 hours 1 minute');
        $this->press('p:'.$tokens['approve']);

        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        self::assertStringContainsString('Bouton expiré', MockHttpResponseFactory::telegramCalls('answerCallbackQuery')[0]['text']);
        self::assertSame(['telegram expired #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
    }

    /**
     * Vérifie qu'une demande déjà traitée depuis l'admin donne un message clair (pas un refus de sécurité).
     */
    public function testAlreadyProcessedRequestIsReported(): void
    {
        $this->enableRemoteValidation();
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);
        static::getContainer()->get(RecruiterAccessManager::class)->refuse($recruiter);

        $this->press('p:'.$tokens['approve']);

        self::assertSame('Demande déjà traitée.', MockHttpResponseFactory::telegramCalls('answerCallbackQuery')[0]['text']);
        self::assertStringContainsString('Demande déjà traitée.', MockHttpResponseFactory::telegramCalls('editMessageText')[0]['text']);
        self::assertSame(RecruiterStatus::Refused, $this->reloadStatus($recruiter));
        self::assertSame([], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
    }

    /**
     * Vérifie que l'interrupteur « Validation à distance » désactivé rend les boutons sans effet.
     */
    public function testDisabledSwitchRefusesButtons(): void
    {
        $this->enableRemoteValidation();
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);
        $this->enableRemoteValidation(false);

        $this->press('p:'.$tokens['approve']);

        self::assertStringContainsString('désactivée', MockHttpResponseFactory::telegramCalls('answerCallbackQuery')[0]['text']);
        self::assertSame(['telegram disabled'], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
    }

    /**
     * Vérifie qu'un jeton Discord ne fonctionne pas comme bouton Telegram, et qu'aucun jeton n'est journalisé.
     */
    public function testDiscordTokenIsUnknownAndNoTokenIsLogged(): void
    {
        $this->enableRemoteValidation();
        $recruiter = $this->createPendingRequest();
        $discord = $this->issueTokens($recruiter, NotificationChannel::Discord);

        $this->press('p:'.$discord['approve']);
        $this->press('p:inconnu');

        self::assertSame(['telegram unknown', 'telegram unknown'], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
        foreach ($this->securityUsernames(SecurityEventType::RemoteDecisionRejected) as $username) {
            self::assertStringNotContainsString($discord['approve'], (string) $username);
        }
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
    }

    /**
     * Envoie un appui simulé sur un bouton au webhook.
     *
     * @param string      $data   callback_data du bouton
     * @param string|null $secret en-tête X-Telegram-Bot-Api-Secret-Token (null : absent)
     */
    private function press(string $data, ?string $secret = self::SECRET, int $chatId = self::CHAT_ID, int $userId = self::CHAT_ID): void
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $secret) {
            $server['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] = $secret;
        }
        $update = ['update_id' => 1, 'callback_query' => [
            'id' => 'appui-'.bin2hex(random_bytes(4)),
            'from' => ['id' => $userId, 'is_bot' => false],
            'message' => ['message_id' => 10, 'chat' => ['id' => $chatId, 'type' => 'private']],
            'data' => $data,
        ]];
        $this->client->request('POST', '/telegram/webhook', [], [], $server, json_encode($update, \JSON_THROW_ON_ERROR));
    }

    /**
     * Envoie au webhook une réponse simulée à une demande de code PIN.
     *
     * @param int    $promptId  message de la demande du code PIN
     * @param string $text      code saisi
     * @param int    $messageId numéro du message de la réponse (supprimé par le traitement)
     */
    private function reply(int $promptId, string $text, int $userId = self::CHAT_ID, int $messageId = 500): void
    {
        $update = ['update_id' => 2, 'message' => [
            'message_id' => $messageId,
            'from' => ['id' => $userId, 'is_bot' => false],
            'chat' => ['id' => self::CHAT_ID, 'type' => 'private'],
            'reply_to_message' => ['message_id' => $promptId],
            'text' => $text,
        ]];
        $this->client->request('POST', '/telegram/webhook', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN' => self::SECRET], json_encode($update, \JSON_THROW_ON_ERROR));
    }

    /**
     * Renvoie le numéro du message qui demande le code PIN d'un jeton (relu en base).
     */
    private function promptOf(string $plainToken): int
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        return (int) static::getContainer()->get(RemoteDecisionTokens::class)->find($plainToken)?->getTelegramPromptMessageId();
    }
}
