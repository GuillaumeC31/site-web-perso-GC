<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * Journal en mémoire des tests : mémorise chaque message, variables {nom} remplacées par le contexte.
 *
 * Liens :
 * - Utilisé par : tests/Service/NotificationDispatcherTest
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<string> messages journalisés */
    public array $messages = [];

    /**
     * Mémorise le message avec ses variables remplacées.
     *
     * @param array<string, mixed> $context variables du message
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $replacements = [];
        foreach ($context as $key => $value) {
            $replacements['{'.$key.'}'] = \is_scalar($value) ? (string) $value : get_debug_type($value);
        }
        $this->messages[] = strtr((string) $message, $replacements);
    }
}
