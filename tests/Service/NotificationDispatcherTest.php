<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Heartbeat;
use App\Entity\User;
use App\Enum\NotificationChannel;
use App\Enum\NotificationEvent;
use App\Enum\RecruiterStatus;
use App\Repository\HeartbeatRepository;
use App\Repository\SiteSettingsRepository;
use App\Repository\UserRepository;
use App\Service\Dev\TestContext;
use App\Service\Mail\MailFactory;
use App\Service\Mail\MailRecipients;
use App\Service\Mail\ResilientMailer;
use App\Service\Notification\DiscordApp;
use App\Service\Notification\Notification;
use App\Service\Notification\NotificationDispatcher;
use App\Service\Notification\TelegramClient;
use App\Service\Notification\TelegramPollStatus;
use App\Service\Recruiter\AccessRequestSummary;
use App\Service\Recruiter\RemoteDecisionTokens;
use App\Tests\Support\DiscordSigner;
use App\Tests\Support\MemoryLogger;
use App\Tests\Support\RemoteDecisionTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Répartition des notifications (lot 6) : canaux cochés dans les paramètres, canaux non configurés
 * ignorés, échec journalisé sans bloquer les autres canaux ; aucune donnée personnelle vers Discord et
 * Telegram (lot 8).
 *
 * Liens :
 * - Couvre : NotificationDispatcher, SiteSettings::getChannels()
 */
final class NotificationDispatcherTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use RemoteDecisionTestTrait;

    /** Faux journal du répartiteur (messages mémorisés). */
    private MemoryLogger $logger;

    /**
     * Vérifie qu'un canal non configuré est ignoré et qu'un échec Discord est journalisé ; l'e-mail part.
     */
    public function testFailureIsLoggedAndOtherChannelsStillSend(): void
    {
        $dispatcher = $this->createDispatcher(new MockHttpClient(new MockResponse('', ['http_code' => 500])), '');

        $sent = $dispatcher->notify($this->notification(NotificationEvent::Contact));

        self::assertSame([NotificationChannel::Email], $sent);
        self::assertFalse($dispatcher->isConfigured(NotificationChannel::Telegram));
        self::assertCount(1, $this->logger->messages);
        self::assertStringContainsString('discord', $this->logger->messages[0]);
        self::assertEmailCount(1);
    }

    /**
     * Vérifie qu'un canal décoché dans les paramètres n'est pas utilisé.
     */
    public function testUncheckedChannelIsSkipped(): void
    {
        self::bootKernel();
        $settings = static::getContainer()->get(SiteSettingsRepository::class)->getOrCreate();
        $settings->setChannels(NotificationEvent::Interest, [NotificationChannel::Discord]);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($settings);
        $entityManager->flush();
        $http = new MockHttpClient(new MockResponse('', ['http_code' => 204]));

        $sent = $this->createDispatcher($http, '42')->notify($this->notification(NotificationEvent::Interest));

        self::assertSame([NotificationChannel::Discord], $sent);
        self::assertSame(1, $http->getRequestsCount());
        self::assertEmailCount(0);
    }

    /**
     * Vérifie que Discord et Telegram ne reçoivent que le type d'événement et le lien vers l'admin : ni nom,
     * ni entreprise, ni message ; l'e-mail à Guillaume garde le nom et l'entreprise.
     */
    public function testChatChannelsReceiveNoPersonalData(): void
    {
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = (string) ($options['body'] ?? '');

            return new MockResponse('{"ok":true,"result":{}}', ['http_code' => 200]);
        });
        $dispatcher = $this->createDispatcher($http, '42');
        $notification = new Notification(NotificationEvent::AccessRequest, 'Nouvelle demande d\'accès recruteur', ['Nom' => 'Camille Martin', 'Entreprise' => 'Acme Recrutement'], 'http://localhost/admin/recruteurs/12');

        $sent = $dispatcher->notify($notification);

        self::assertSame([NotificationChannel::Email, NotificationChannel::Discord, NotificationChannel::Telegram], $sent);
        self::assertCount(2, $bodies);
        foreach ($bodies as $body) {
            self::assertStringNotContainsString('Camille', $body);
            self::assertStringNotContainsString('Martin', $body);
            self::assertStringNotContainsString('Acme', $body);
            self::assertStringContainsString('voir l\'admin', json_decode($body, true)['content'] ?? json_decode($body, true)['text']);
            self::assertStringContainsString('http:\/\/localhost\/admin\/recruteurs\/12', $body);
        }
        self::assertSame("Nouvelle demande d'accès recruteur — voir l'admin : http://localhost/admin/recruteurs/12", $notification->toChatText());
        self::assertEmailTextBodyContains(self::getMailerMessage(), 'Camille Martin');
    }

    /**
     * Vérifie la notification d'une nouvelle demande avec la validation à distance activée : détails
     * neutralisés (code en ligne, HTML échappé), boutons Telegram en callback_data, liens Discord à usage
     * unique sans aperçu ; jamais l'e-mail complet ni l'IP.
     */
    public function testAccessRequestCarriesDetailsAndRemoteDecisions(): void
    {
        $bodies = [];
        $urls = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, &$urls): MockResponse {
            $bodies[str_contains($url, 'telegram') ? 'telegram' : 'discord'] = json_decode((string) ($options['body'] ?? ''), true);
            $urls[] = $url;

            return new MockResponse('{"ok":true,"result":{}}', ['http_code' => 200]);
        });
        $dispatcher = $this->createDispatcher($http, '42');
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest()->setIpAddress('203.0.113.9')->setMessage('Voir [Accepter](https://piege.example) <b>urgent</b> `code`');

        $dispatcher->notify(static::getContainer()->get(AccessRequestSummary::class)->notification($recruiter));

        // Discord : boutons de type lien (style 5) avec with_components=true ; aucun lien dans le texte
        self::assertContains('https://discord.example.test/hook?with_components=true', $urls);
        $discord = $bodies['discord']['content'];
        self::assertStringContainsString('E-mail : `@acme.example`', $discord);
        self::assertStringContainsString("Message : `Voir [Accepter](https://piege.example) <b>urgent</b> 'code'`", $discord);
        self::assertStringContainsString('Boutons à usage unique, valables 72 h, code PIN demandé.', $discord);
        self::assertStringNotContainsString('validation-distance', $discord);
        $buttons = $bodies['discord']['components'][0]['components'];
        self::assertSame(1, $bodies['discord']['components'][0]['type']);
        self::assertSame(['✅ Accepter', '❌ Refuser', '🔗 Voir dans l\'admin'], array_column($buttons, 'label'));
        self::assertSame([5, 5, 5], array_column($buttons, 'style'));
        self::assertArrayNotHasKey('custom_id', $buttons[0]);
        self::assertMatchesRegularExpression('#^https?://[^/]+/validation-distance/[A-Za-z0-9_-]{43}$#', $buttons[0]['url']);
        self::assertMatchesRegularExpression('#^https?://[^/]+/validation-distance/[A-Za-z0-9_-]{43}$#', $buttons[1]['url']);
        self::assertStringNotContainsString('rh@acme.test', (string) json_encode($bodies['discord']));
        self::assertStringNotContainsString('203.0.113.9', (string) json_encode($bodies['discord']));
        $telegram = $bodies['telegram'];
        self::assertSame('HTML', $telegram['parse_mode']);
        self::assertStringContainsString('<code>Voir [Accepter](https://piege.example) &lt;b&gt;urgent&lt;/b&gt; `code`</code>', $telegram['text']);
        $buttons = $telegram['reply_markup']['inline_keyboard'][0];
        self::assertSame(['✅ Accepter', '❌ Refuser'], array_column($buttons, 'text'));
        self::assertMatchesRegularExpression('#^p:[A-Za-z0-9_-]{43}$#', $buttons[0]['callback_data']);
        foreach ([$discord, $telegram['text']] as $text) {
            self::assertStringNotContainsString('rh@acme.test', $text);
            self::assertStringNotContainsString('203.0.113.9', $text);
        }
    }

    /**
     * Vérifie que la notification de test renvoie une erreur claire : canal non configuré, jeton du bot refusé,
     * webhook Discord inconnu ; sans jeton ni adresse dans le message.
     */
    public function testSendTestReportsClearErrors(): void
    {
        $responses = [new MockResponse('', ['http_code' => 404]), new MockResponse('{"ok":false,"error_code":401,"description":"Unauthorized"}', ['http_code' => 401])];
        $dispatcher = $this->createDispatcher(new MockHttpClient($responses), '');
        self::assertStringContainsString('TELEGRAM_CHAT_ID', (string) $dispatcher->sendTest(NotificationChannel::Telegram));
        self::assertSame('Discord a refusé l\'envoi (HTTP 404 : webhook inconnu ou supprimé).', $dispatcher->sendTest(NotificationChannel::Discord));

        $dispatcher = $this->createDispatcher(new MockHttpClient([new MockResponse('{"ok":false,"error_code":401,"description":"Unauthorized"}', ['http_code' => 401])]), '42');
        $error = (string) $dispatcher->sendTest(NotificationChannel::Telegram);
        self::assertSame('Telegram a refusé l\'appel sendMessage (HTTP 401 : Unauthorized).', $error);
        self::assertStringNotContainsString('123:test', $error);
        self::assertNull($dispatcher->sendTest(NotificationChannel::Email));
    }

    /**
     * Vérifie qu'en développement, pendant une suite Playwright (repère posé), Discord et Telegram sont coupés
     * et l'e-mail part ; sans repère, ou hors développement, tout part.
     */
    public function testChatChannelsAreSilencedDuringEndToEndTests(): void
    {
        $dir = sys_get_temp_dir().'/gc2-silence-'.bin2hex(random_bytes(4));
        mkdir(\dirname($dir.NotificationDispatcher::E2E_SILENT_FLAG), 0700, true);
        file_put_contents($dir.NotificationDispatcher::E2E_SILENT_FLAG, '1');
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ok":true}', ['http_code' => 200]));

        try {
            self::assertSame([NotificationChannel::Email], $this->createDispatcher($http, '42', 'dev', $dir)->notify($this->notification(NotificationEvent::Contact)));
            self::assertSame(0, $http->getRequestsCount());
            self::assertCount(3, $this->createDispatcher($http, '42', 'prod', $dir)->notify($this->notification(NotificationEvent::Contact)));
            unlink($dir.NotificationDispatcher::E2E_SILENT_FLAG);
            self::assertCount(3, $this->createDispatcher($http, '42', 'dev', $dir)->notify($this->notification(NotificationEvent::Contact)));
        } finally {
            @unlink($dir.NotificationDispatcher::E2E_SILENT_FLAG);
        }
    }

    /**
     * Vérifie qu'aucun événement venu d'une adresse de test (« example.test ») n'atteint Discord ni Telegram,
     * dans aucun environnement (production comprise) ; l'e-mail part.
     */
    public function testTestDomainNeverReachesChatChannels(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ok":true}', ['http_code' => 200]));
        foreach (['prod', 'dev', 'test'] as $environment) {
            foreach (['parcours@e2e-parcours.example.test', 'demo@example.test'] as $email) {
                $notification = new Notification(NotificationEvent::Interest, 'Signal d\'intérêt d\'un recruteur', ['Nom' => 'Camille'], 'http://localhost/admin', senderEmail: $email);
                self::assertSame([NotificationChannel::Email], $this->createDispatcher($http, '42', $environment)->notify($notification), $environment.' '.$email);
            }
        }
        self::assertSame(0, $http->getRequestsCount());
        self::assertCount(3, $this->createDispatcher($http, '42', 'prod')->notify(new Notification(NotificationEvent::Contact, 'Test', [], 'http://localhost/admin', senderEmail: 'rh@exemple-test.test')));
    }

    /**
     * Vérifie le signal d'intérêt : mêmes informations qu'une demande (score en clair, critères, domaine de
     * l'e-mail), signaux reçus, lien vers l'admin seulement (aucun bouton de décision).
     */
    public function testInterestSignalCarriesDetailsWithoutDecisionButtons(): void
    {
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[str_contains($url, 'telegram') ? 'telegram' : 'discord'] = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse('{"ok":true,"result":{}}', ['http_code' => 200]);
        });
        $dispatcher = $this->createDispatcher($http, '42');
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest()->setStatus(RecruiterStatus::Approved)->setScoring(70, [
            ['step' => 'mx', 'label' => 'Serveurs de messagerie (MX)', 'result' => 'Trouvés pour acme.example (+30)', 'level' => 'success'],
            ['step' => 'domain', 'label' => 'Domaine professionnel', 'result' => 'acme.example (+40)', 'level' => 'success'],
            ['step' => 'spamhaus', 'label' => 'Adresse IP (Spamhaus)', 'result' => 'Non inscrite', 'level' => 'success'],
        ]);

        $dispatcher->notify(static::getContainer()->get(AccessRequestSummary::class)->interestNotification($recruiter, ['Je suis intéressé (27/10 14:05)']));

        $discord = $bodies['discord']['content'];
        self::assertStringContainsString('Score : `70/100 — fiabilité bonne`', $discord);
        self::assertStringContainsString('Critères : `Domaine professionnel : acme.example (+40) ; Serveurs de messagerie (MX) : Trouvés pour acme.example (+30)`', $discord);
        self::assertStringContainsString('Signaux : `Je suis intéressé (27/10 14:05)`', $discord);
        self::assertStringContainsString('E-mail : `@acme.example`', $discord);
        self::assertStringNotContainsString('validation-distance', $discord);
        // Signal d'intérêt : bouton « Voir dans l'admin » seulement (rien à accepter)
        self::assertSame(['🔗 Voir dans l\'admin'], array_column($bodies['discord']['components'][0]['components'], 'label'));
        self::assertArrayNotHasKey('reply_markup', $bodies['telegram'], 'Adresse locale : lien vers l\'admin dans le texte, aucun bouton.');
        self::assertStringContainsString('Voir dans l\'admin', $bodies['telegram']['text']);
    }

    /**
     * Vérifie la notification de test : interrupteur actif, boutons et liens d'ESSAI ; liens locaux signalés ;
     * code PIN absent signalé au lieu de retirer les liens en silence.
     */
    public function testTestNotificationCarriesTrialButtonsAndClearWarnings(): void
    {
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode((string) ($options['body'] ?? ''), true);

            return new MockResponse('{"ok":true,"result":{}}', ['http_code' => 200]);
        });
        $dispatcher = $this->createDispatcher($http, '42');
        $this->enableRemoteValidation();

        self::assertNull($dispatcher->sendTest(NotificationChannel::Discord));
        self::assertStringContainsString('Liens indisponibles : aucun code PIN', $bodies[0]['content']);
        self::assertStringNotContainsString('validation-distance', $bodies[0]['content']);

        $this->enableRemoteValidation(withPin: true);
        self::assertNull($dispatcher->sendTest(NotificationChannel::Discord));
        self::assertMatchesRegularExpression('#^https://localhost[^/]*/validation-distance/[A-Za-z0-9_-]{43}$#', $bodies[1]['components'][0]['components'][0]['url']);
        self::assertStringContainsString('ne s\'ouvrent que depuis ce PC', $bodies[1]['content']);
        self::assertNull($dispatcher->sendTest(NotificationChannel::Telegram));
        self::assertSame(['✅ Accepter', '❌ Refuser'], array_column($bodies[2]['reply_markup']['inline_keyboard'][0], 'text'));
        self::assertStringContainsString('Demande d\'essai', $bodies[2]['text']);
    }

    /**
     * Vérifie le repli : Discord refuse les boutons (HTTP 400), le message repart aussitôt avec les liens dans le
     * texte, sans composants ; le journal le note sans adresse ni jeton.
     */
    public function testDiscordFallsBackToTextLinksWhenButtonsAreRefused(): void
    {
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'body' => json_decode((string) ($options['body'] ?? ''), true)];

            return new MockResponse('{"code":50035,"message":"Invalid Form Body"}', ['http_code' => 1 === \count($requests) ? 400 : 204]);
        });
        $dispatcher = $this->createDispatcher($http, '');
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest()->setIpAddress('203.0.113.9');

        $sent = $dispatcher->notify(static::getContainer()->get(AccessRequestSummary::class)->notification($recruiter));

        self::assertContains(NotificationChannel::Discord, $sent);
        self::assertCount(2, $requests);
        self::assertStringContainsString('with_components=true', $requests[0]['url']);
        self::assertSame('https://discord.example.test/hook', $requests[1]['url']);
        self::assertArrayNotHasKey('components', $requests[1]['body']);
        self::assertMatchesRegularExpression('#✅ Accepter : <https?://[^/>]+/validation-distance/[A-Za-z0-9_-]{43}>#', $requests[1]['body']['content']);
        self::assertStringContainsString('🔗 Voir dans l\'admin', $requests[1]['body']['content']);
        foreach ($requests as $request) {
            self::assertStringNotContainsString('rh@acme.test', (string) json_encode($request['body']));
            self::assertStringNotContainsString('203.0.113.9', (string) json_encode($request['body']));
        }
        $log = implode("\n", $this->logger->messages);
        self::assertStringContainsString('Discord a refusé les boutons (HTTP 400)', $log);
        self::assertStringNotContainsString('validation-distance', $log);
        self::assertStringNotContainsString('discord.example.test', $log);
    }

    /**
     * Vérifie l'application Discord : message envoyé par le bot dans le salon (en-tête « Bot »), vrais boutons
     * (custom_id = jeton, jamais l'identifiant de la demande) ; refus de l'application : repli sur le webhook
     * (boutons-liens), noté sans adresse ni jeton.
     */
    public function testDiscordAppSendsInteractiveButtonsWithWebhookFallback(): void
    {
        $requests = [];
        $status = 200;
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests, &$status): MockResponse {
            $requests[] = ['url' => $url, 'auth' => implode('', (array) ($options['normalized_headers']['authorization'] ?? [])), 'body' => json_decode((string) ($options['body'] ?? ''), true)];

            return new MockResponse('{}', ['http_code' => str_contains($url, '/channels/') ? $status : 204]);
        });
        $dispatcher = $this->createDispatcher($http, '', discordApp: true);
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();

        $dispatcher->notify(static::getContainer()->get(AccessRequestSummary::class)->notification($recruiter));

        self::assertCount(1, $requests);
        self::assertSame('https://discord.com/api/v10/channels/222/messages', $requests[0]['url']);
        self::assertStringContainsString('Bot jeton-de-test-du-bot', $requests[0]['auth']);
        $buttons = $requests[0]['body']['components'][0]['components'];
        self::assertSame(['✅ Accepter', '❌ Refuser', '🔗 Voir dans l\'admin'], array_column($buttons, 'label'));
        self::assertSame([3, 4, 5], array_column($buttons, 'style'));
        self::assertMatchesRegularExpression('#^d:[A-Za-z0-9_-]{43}$#', $buttons[0]['custom_id']);
        self::assertStringNotContainsString((string) $recruiter->getId(), $buttons[0]['custom_id'].$buttons[1]['custom_id']);
        self::assertStringContainsString('les boutons ne répondront qu\'une fois le site en ligne', $requests[0]['body']['content']);

        // L'application refuse (ex. bot absent du salon) : repli sur le webhook
        $status = 403;
        $dispatcher->notify(static::getContainer()->get(AccessRequestSummary::class)->notification($this->createPendingRequest('autre@acme.test')));
        self::assertCount(3, $requests);
        self::assertStringContainsString('discord.example.test/hook?with_components=true', $requests[2]['url']);
        self::assertSame(5, $requests[2]['body']['components'][0]['components'][0]['style']);
        $log = implode("\n", $this->logger->messages);
        self::assertStringContainsString('Discord (application) n\'a pas envoyé le message (HTTP 403) : repli sur le webhook.', $log);
        self::assertStringNotContainsString('jeton-de-test-du-bot', $log);
    }

    /**
     * Vérifie l'avertissement « Relève Telegram arrêtée » : en développement seulement, si la relève n'a pas tourné
     * depuis plus de 2 minutes ; rien quand elle vient de tourner.
     */
    public function testTelegramPollWarning(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $status = new TelegramPollStatus($container->get(HeartbeatRepository::class), $container->get(ClockInterface::class), 'dev');
        self::assertStringContainsString('Relève Telegram arrêtée (jamais lancée)', (string) $status->warning());
        $container->get(HeartbeatRepository::class)->beat(Heartbeat::TELEGRAM_POLL);
        self::assertNull($status->warning());
        self::assertNull((new TelegramPollStatus($container->get(HeartbeatRepository::class), $container->get(ClockInterface::class), 'prod'))->warning());
        self::mockTime('+3 minutes');
        self::assertStringContainsString('Relève Telegram arrêtée (dernier passage le', (string) $status->warning());
    }

    /**
     * Construit le répartiteur avec un client HTTP simulé et un faux journal.
     *
     * @param string           $telegramChatId conversation Telegram (vide : canal non configuré)
     * @param string           $environment    environnement simulé (dev : repère des tests de bout en bout lu)
     * @param string           $projectDir     dossier où chercher le repère E2E_SILENT_FLAG
     * @param bool             $discordApp     vrai : application Discord configurée (boutons interactifs) ; faux : webhook seul
     * @param string           $discordWebhook adresse du webhook Discord (vide : aucun repli)
     * @param TestContext|null $testContext    requêtes de test (null : aucune)
     */
    private function createDispatcher(MockHttpClient $http, string $telegramChatId, string $environment = 'test', string $projectDir = '', bool $discordApp = false, string $discordWebhook = 'https://discord.example.test/hook', ?TestContext $testContext = null): NotificationDispatcher
    {
        if (!self::$booted) {
            self::bootKernel();
        }
        $container = static::getContainer();
        $this->logger = new MemoryLogger();

        // Canal e-mail : adresse de notification du compte admin (repli des catégories), créé une fois par test
        $entityManager = $container->get(EntityManagerInterface::class);
        if (null === $container->get(UserRepository::class)->findOneBy(['username' => 'admin_notifications'])) {
            $entityManager->persist((new User('admin_notifications'))->setRoles(['ROLE_ADMIN'])->setNotificationEmail('notifications@example.test')->setPassword('!'));
            $entityManager->flush();
        }

        return new NotificationDispatcher(
            $container->get(SiteSettingsRepository::class), $container->get(ResilientMailer::class), $container->get(MailRecipients::class),
            $container->get(MailFactory::class), $http, new TelegramClient($http, '123:test'), $container->get(RemoteDecisionTokens::class),
            $container->get(AccessRequestSummary::class),
            $discordApp ? new DiscordApp($http, $container->get(ClockInterface::class), '111', DiscordSigner::publicKey(), 'jeton-de-test-du-bot', '222', '333') : new DiscordApp($http, $container->get(ClockInterface::class), '', '', '', '', ''),
            $container->get(TelegramPollStatus::class), $this->logger, $discordWebhook, $telegramChatId, $environment, $projectDir, $testContext,
        );
    }

    /**
     * Vérifie qu'une requête de test (navigateur sans interface, en développement) ne part jamais vers Discord ni
     * Telegram, même sans repère de fichier ; une action réelle (navigateur normal) part normalement.
     */
    public function testTestRequestsNeverReachChatChannels(): void
    {
        $http = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"ok":true}', ['http_code' => 200]));
        $test = new RequestStack();
        $test->push(Request::create('/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 HeadlessChrome/140']));
        self::assertSame([NotificationChannel::Email], $this->createDispatcher($http, '42', 'dev', testContext: new TestContext($test, null, 'dev'))->notify($this->notification(NotificationEvent::Contact)));
        self::assertSame(0, $http->getRequestsCount());

        $real = new RequestStack();
        $real->push(Request::create('/', server: ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Edg/140']));
        self::assertCount(3, $this->createDispatcher($http, '42', 'dev', testContext: new TestContext($real, null, 'dev'))->notify($this->notification(NotificationEvent::Contact)));
    }

    /**
     * Notification de test.
     */
    private function notification(NotificationEvent $event): Notification
    {
        return new Notification($event, 'Test', ['Nom' => 'Camille Martin'], 'http://localhost/admin');
    }
}
