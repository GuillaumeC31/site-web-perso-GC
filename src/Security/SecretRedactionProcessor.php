<?php

declare(strict_types=1);

namespace App\Security;

use Monolog\Attribute\AsMonologProcessor;
use Monolog\LogRecord;

/**
 * Masque dans TOUS les journaux applicatifs les secrets qui peuvent apparaître dans une adresse (règle du
 * 2026-10-26 : jamais d'URL ni de jeton dans les journaux) :
 * - jeton des liens de validation à distance (/validation-distance/{jeton}, paramètre de route « token ») ;
 * - jeton du bot Telegram (api.telegram.org/bot{jeton}/…) ;
 * - jeton des webhooks Discord (discord.com/api/webhooks/{id}/{jeton}).
 * Les journaux du serveur web (l’hébergeur) restent hors de portée : les liens sont à usage unique, valables
 * 72 h et protégés par le code PIN.
 *
 * Liens :
 * - Déclaré par : l'attribut AsMonologProcessor (tous les canaux)
 * - Testé par : tests/Security/SecretRedactionProcessorTest
 */
#[AsMonologProcessor]
final class SecretRedactionProcessor
{
    /** Motifs à masquer => remplacement. */
    private const PATTERNS = [
        '#(/validation-distance/)[A-Za-z0-9_-]+#' => '$1[masqué]',
        '#(api\.telegram\.org/bot)[^/\s"]+#' => '$1[masqué]',
        '#(/api/webhooks/\d+/)[^/\s"?]+#' => '$1[masqué]',
    ];

    /**
     * Masque les secrets du message et du contexte d'une ligne de journal.
     *
     * Liens :
     * - Appelée par : Monolog, pour chaque ligne
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->redact($record->context);
        // Paramètres de route du routeur (« Matched route ») : jeton des liens retiré
        if (isset($context['route_parameters']['token'])) {
            $context['route_parameters']['token'] = '[masqué]';
        }

        return $record->with(message: $this->redactString($record->message), context: $context);
    }

    /**
     * Masque les secrets d'un tableau, récursivement.
     *
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private function redact(array $values): array
    {
        foreach ($values as $key => $value) {
            if (\is_string($value)) {
                $values[$key] = $this->redactString($value);
            } elseif (\is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }

    /**
     * Masque les secrets d'un texte.
     */
    private function redactString(string $text): string
    {
        return (string) preg_replace(array_keys(self::PATTERNS), array_values(self::PATTERNS), $text);
    }
}
