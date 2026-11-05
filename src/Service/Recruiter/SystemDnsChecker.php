<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Contrôles DNS avec les fonctions de PHP (checkdnsrr, gethostbyname).
 *
 * Spamhaus : l'IP a.b.c.d est cherchée sous d.c.b.a.zen.spamhaus.org ; une réponse en 127.0.0.x
 * signifie qu'elle est inscrite.
 *
 * Liens :
 * - Utilisé par : RecruiterScorer (via DnsChecker)
 */
#[AsAlias(DnsChecker::class)]
final class SystemDnsChecker implements DnsChecker
{
    /**
     * Indique si le domaine a des serveurs de messagerie (enregistrement MX).
     *
     * Liens :
     * - Appelée par : RecruiterScorer::score()
     */
    public function hasMailServer(string $domain): bool
    {
        return '' !== $domain && checkdnsrr($domain.'.', 'MX');
    }

    /**
     * Indique si l'adresse IPv4 est inscrite sur zen.spamhaus.org.
     *
     * Liens :
     * - Appelée par : RecruiterScorer::score()
     */
    public function isListedBySpamhaus(string $ip): bool
    {
        $host = implode('.', array_reverse(explode('.', $ip))).'.zen.spamhaus.org';
        $answer = gethostbyname($host);

        return $answer !== $host && str_starts_with($answer, '127.0.0.');
    }
}
