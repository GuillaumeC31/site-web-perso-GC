<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Tests\Support\MockHttpResponseFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Déclaration du webhook Telegram (setWebhook, deleteWebhook, getWebhookInfo), API simulée : adresse HTTPS
 * exigée, secret transmis, appuis sur les boutons seulement ; ni jeton ni secret affichés.
 *
 * Liens :
 * - Couvre : App\Command\TelegramWebhookCommand
 */
final class TelegramWebhookCommandTest extends KernelTestCase
{
    /**
     * Vérifie la déclaration, le refus d'une adresse non HTTPS, le retrait et l'erreur de Telegram.
     */
    public function testSetDeleteAndErrors(): void
    {
        MockHttpResponseFactory::reset();
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:telegram:webhook'));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'set', '--url' => 'http://site.example/telegram/webhook']));
        self::assertStringContainsString('HTTPS', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'set', '--url' => 'https://test.site.example/telegram/webhook']));
        $call = MockHttpResponseFactory::telegramCalls('setWebhook')[0];
        self::assertSame('https://test.site.example/telegram/webhook', $call['url']);
        self::assertSame('secret-de-test-du-webhook', $call['secret_token']);
        self::assertSame(['callback_query', 'message'], $call['allowed_updates']);
        self::assertStringNotContainsString('secret-de-test-du-webhook', $tester->getDisplay());
        self::assertStringNotContainsString('123:test', $tester->getDisplay());

        self::assertSame(Command::SUCCESS, $tester->execute(['action' => 'delete']));
        self::assertCount(1, MockHttpResponseFactory::telegramCalls('deleteWebhook'));

        MockHttpResponseFactory::$telegramQueue[] = MockHttpResponseFactory::telegramError(401, 'Unauthorized');
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'info']));
        self::assertStringContainsString('Unauthorized', $tester->getDisplay());
        self::assertSame(Command::INVALID, $tester->execute(['action' => 'autre']));
    }
}
