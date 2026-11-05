<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SecretRedactionProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;

/**
 * Aucun jeton ni secret d'adresse dans les journaux : liens de validation à distance, jeton du bot Telegram,
 * jeton des webhooks Discord, paramètre de route « token ».
 *
 * Liens :
 * - Couvre : App\Security\SecretRedactionProcessor
 */
final class SecretRedactionProcessorTest extends TestCase
{
    /**
     * Vérifie le masquage dans le message et dans le contexte (y compris imbriqué).
     */
    public function testSecretsAreMasked(): void
    {
        $token = str_repeat('a', 43);
        $record = new LogRecord(new \DateTimeImmutable(), 'request', Level::Info, 'Matched route "remote_decision_show" for https://site.example/validation-distance/'.$token, [
            'route_parameters' => ['_route' => 'remote_decision_show', 'token' => $token],
            'request_uri' => 'https://site.example/validation-distance/'.$token.'?x=1',
            'http' => ['url' => 'https://api.telegram.org/bot123456:ABC-def_ghi/sendMessage', 'webhook' => 'https://discord.com/api/webhooks/987/SeCrEt-Webhook_Token'],
        ]);

        $masked = (new SecretRedactionProcessor())($record);
        $all = $masked->message.json_encode($masked->context, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        self::assertStringNotContainsString($token, $all);
        self::assertStringNotContainsString('123456:ABC-def_ghi', $all);
        self::assertStringNotContainsString('SeCrEt-Webhook_Token', $all);
        self::assertStringContainsString('/validation-distance/[masqué]', $masked->message);
        self::assertSame('[masqué]', $masked->context['route_parameters']['token']);
        self::assertSame('https://api.telegram.org/bot[masqué]/sendMessage', $masked->context['http']['url']);
        self::assertSame('https://discord.com/api/webhooks/987/[masqué]', $masked->context['http']['webhook']);
    }
}
