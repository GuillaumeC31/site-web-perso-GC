<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Enum\NotificationChannel;
use App\Enum\RecruiterStatus;
use App\Enum\SecurityEventType;
use App\Service\Notification\TelegramPollStatus;
use App\Service\Recruiter\RemoteDecisionTokens;
use App\Tests\Support\MockHttpResponseFactory;
use App\Tests\Support\RemoteDecisionTestTrait;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Relève des appuis Telegram en local (app:telegram:poll), API simulée : refus si un webhook est déclaré ;
 * appuis traités avec les MÊMES contrôles que le webhook (autre compte refusé, deux appuis, usage unique) ;
 * appuis marqués comme lus ; jamais de jeton affiché.
 *
 * Liens :
 * - Couvre : App\Command\TelegramPollCommand, App\Service\Notification\TelegramCallbackHandler
 */
final class TelegramPollCommandTest extends KernelTestCase
{
    use RemoteDecisionTestTrait;

    /**
     * Vérifie le refus quand un webhook est déclaré (Telegram n'envoie alors rien à getUpdates).
     */
    public function testRefusesWhenWebhookIsDeclared(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        MockHttpResponseFactory::$telegramQueue[] = new MockResponse('{"ok":true,"result":{"url":"https://site.example/telegram/webhook"}}');

        $tester = new CommandTester((new Application(self::$kernel))->find('app:telegram:poll'));

        self::assertSame(Command::FAILURE, $tester->execute(['--once' => true]));
        self::assertStringContainsString('Un webhook est déclaré', $tester->getDisplay());
        self::assertSame([], MockHttpResponseFactory::telegramCalls('getUpdates'));
    }

    /**
     * Vérifie une relève : appui d'un autre compte refusé, premier appui puis « Oui » du bon compte : demande
     * acceptée comme depuis le webhook ; appuis marqués comme lus.
     */
    public function testProcessesCallbacksWithWebhookChecks(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $this->enableRemoteValidation(withPin: true);
        $recruiter = $this->createPendingRequest();
        $tokens = $this->issueTokens($recruiter, NotificationChannel::Telegram);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:telegram:poll'));

        // Relève 1 : appui d'un autre compte (refusé), appui du bon compte (code PIN demandé)
        MockHttpResponseFactory::$telegramQueue[] = new MockResponse('{"ok":true,"result":{"url":""}}');
        MockHttpResponseFactory::$telegramQueue[] = new MockResponse(json_encode(['ok' => true, 'result' => [
            $this->update(1, 'p:'.$tokens['approve'], 777),
            $this->update(2, 'p:'.$tokens['approve'], 42),
        ]], \JSON_THROW_ON_ERROR));
        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));
        self::assertSame(RecruiterStatus::Pending, $this->reloadStatus($recruiter));
        $prompt = (int) static::getContainer()->get(RemoteDecisionTokens::class)->find($tokens['approve'])?->getTelegramPromptMessageId();
        self::assertGreaterThan(0, $prompt);

        // Relève 2 : réponse portant le bon code PIN (supprimée aussitôt), décision appliquée
        MockHttpResponseFactory::$telegramQueue[] = new MockResponse('{"ok":true,"result":{"url":""}}');
        MockHttpResponseFactory::$telegramQueue[] = new MockResponse(json_encode(['ok' => true, 'result' => [['update_id' => 3, 'message' => [
            'message_id' => 600, 'from' => ['id' => 42, 'is_bot' => false], 'chat' => ['id' => 42, 'type' => 'private'],
            'reply_to_message' => ['message_id' => $prompt], 'text' => self::TEST_PIN,
        ]]]], \JSON_THROW_ON_ERROR));
        self::assertSame(Command::SUCCESS, $tester->execute(['--once' => true]));

        self::assertSame(RecruiterStatus::Approved, $this->reloadStatus($recruiter));
        self::assertContains(600, array_column(MockHttpResponseFactory::telegramCalls('deleteMessage'), 'message_id'));
        self::assertSame(['telegram foreign_chat'], $this->securityUsernames(SecurityEventType::RemoteDecisionRejected));
        self::assertSame(['telegram approve #'.$recruiter->getId()], $this->securityUsernames(SecurityEventType::RemoteDecision));
        $calls = MockHttpResponseFactory::telegramCalls('getUpdates');
        self::assertSame(['callback_query', 'message'], $calls[0]['allowed_updates']);
        self::assertSame(4, end($calls)['offset'], 'Événements marqués comme lus.');
        self::assertStringNotContainsString($tokens['approve'], $tester->getDisplay());
        self::assertStringNotContainsString(self::TEST_PIN, $tester->getDisplay());
        self::assertStringContainsString('message traité', $tester->getDisplay());
        // Passage noté : « Relève Telegram : active »
        self::assertTrue(static::getContainer()->get(TelegramPollStatus::class)->isActive());
    }

    /**
     * Mise à jour Telegram simulée : appui sur un bouton dans la conversation 42.
     *
     * @return array<string, mixed>
     */
    private function update(int $id, string $data, int $fromId): array
    {
        return ['update_id' => $id, 'callback_query' => [
            'id' => 'appui-'.$id, 'from' => ['id' => $fromId, 'is_bot' => false],
            'message' => ['message_id' => 10, 'chat' => ['id' => 42, 'type' => 'private']], 'data' => $data,
        ]];
    }
}
