<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Recruiter\DnsChecker;

/**
 * Contrôles DNS simulés en test (aucune requête DNS) : les domaines en « .example » et les domaines
 * de messagerie connus ont des serveurs MX, sauf « no-mx.example » ; l'IP 203.0.113.66 est inscrite
 * sur la liste de Spamhaus.
 *
 * Liens :
 * - Déclaré dans : config/services.yaml (when@test, alias de DnsChecker)
 * - Utilisé par : RecruiterScorer (tests), tests/Service/RecruiterScorerTest
 */
final class FakeDnsChecker implements DnsChecker
{
    /** Adresse IP simulée comme inscrite sur la liste de blocage. */
    public const LISTED_IP = '203.0.113.66';

    /**
     * Indique si le domaine a des serveurs de messagerie (simulation).
     */
    public function hasMailServer(string $domain): bool
    {
        return 'no-mx.example' !== $domain && '' !== $domain;
    }

    /**
     * Indique si l'adresse IP est inscrite sur la liste de Spamhaus (simulation).
     */
    public function isListedBySpamhaus(string $ip): bool
    {
        return self::LISTED_IP === $ip;
    }
}
