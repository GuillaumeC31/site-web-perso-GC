<?php

declare(strict_types=1);

namespace App\Service\Mail;

/**
 * Lecture des enregistrements TXT par le résolveur DNS du serveur (dns_get_record).
 *
 * Liens :
 * - Utilisée par : DeliverabilityChecker (via TxtRecordResolver)
 */
final class SystemTxtRecordResolver implements TxtRecordResolver
{
    /**
     * Renvoie les enregistrements TXT d'un nom ; une erreur DNS donne une liste vide.
     *
     * Liens :
     * - Appelée par : DeliverabilityChecker::check()
     *
     * @return list<string>
     */
    public function getTxtRecords(string $host): array
    {
        $records = @dns_get_record($host, \DNS_TXT);
        if (false === $records) {
            return [];
        }
        $texts = [];
        foreach ($records as $record) {
            // Un enregistrement long arrive en morceaux (« entries ») : on les réunit
            $texts[] = isset($record['entries']) && \is_array($record['entries']) ? implode('', $record['entries']) : (string) ($record['txt'] ?? '');
        }

        return array_values(array_filter($texts, static fn (string $text): bool => '' !== $text));
    }
}
