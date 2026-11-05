<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

/**
 * Contrôles DNS du score des demandes : serveurs de messagerie (MX) d'un domaine, adresse IP inscrite
 * sur la liste de blocage de Spamhaus (zen.spamhaus.org).
 *
 * Liens :
 * - Implémenté par : SystemDnsChecker (fonctions DNS de PHP) ; tests/Support/FakeDnsChecker en test
 * - Utilisé par : RecruiterScorer
 */
interface DnsChecker
{
    /**
     * Indique si le domaine a des serveurs de messagerie (enregistrement MX).
     *
     * @param string $domain domaine de l'e-mail
     */
    public function hasMailServer(string $domain): bool;

    /**
     * Indique si l'adresse IP publique est inscrite sur la liste de Spamhaus.
     *
     * @param string $ip adresse IPv4 publique
     */
    public function isListedBySpamhaus(string $ip): bool;
}
