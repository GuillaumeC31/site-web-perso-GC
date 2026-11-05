<?php

declare(strict_types=1);

namespace App\Service\Recruiter;

use App\Entity\Recruiter;

/**
 * Score d'aide à la décision d'une demande d'accès (0 à 100), repris des règles de l'ancien site
 * (RecruiterScoringService) : adresse jetable, serveurs de messagerie (MX), IP sur la liste de
 * Spamhaus, domaine professionnel, entreprise renseignée.
 *
 * Barème : MX trouvé + 30, domaine professionnel (ni jetable ni messagerie grand public) + 40,
 * entreprise renseignée + 20, message joint + 10. Adresse jetable ou IP sur liste de blocage : score 0.
 * Le score s'affiche dans l'admin ; il ne valide une demande que si la validation automatique est
 * activée dans les paramètres (désactivée par défaut).
 *
 * Liens :
 * - Utilisé par : RecruiterAccessManager::requestAccess()
 * - Utilise : DnsChecker
 * - Testé par : tests/Service/RecruiterScorerTest
 */
final readonly class RecruiterScorer
{
    /** Domaines d'adresses jetables (liste courte, la plus fréquente). */
    public const DISPOSABLE_DOMAINS = [
        'yopmail.com', 'yopmail.fr', 'mailinator.com', 'guerrillamail.com', 'guerrillamail.info', 'sharklasers.com', '10minutemail.com',
        'tempmail.com', 'temp-mail.org', 'trashmail.com', 'trashmail.fr', 'jetable.org', 'maildrop.cc', 'getnada.com', 'dispostable.com',
        'throwawaymail.com', 'mohmal.com', 'emailondeck.com', 'fakeinbox.com', 'mailnesia.com', 'mintemail.com', 'spamgourmet.com',
        'tempail.com', 'mail-temp.com', 'minuteinbox.com', 'mytemp.email', 'tempr.email', 'discard.email',
    ];

    /** Messageries grand public : ni bonus ni malus. */
    public const FREE_MAIL_DOMAINS = [
        'gmail.com', 'googlemail.com', 'hotmail.com', 'hotmail.fr', 'outlook.com', 'outlook.fr', 'live.com', 'live.fr', 'msn.com',
        'yahoo.com', 'yahoo.fr', 'icloud.com', 'me.com', 'orange.fr', 'wanadoo.fr', 'free.fr', 'sfr.fr', 'laposte.net', 'gmx.fr',
        'gmx.com', 'aol.com', 'protonmail.com', 'proton.me', 'bbox.fr', 'neuf.fr',
    ];

    /**
     * Reçoit les dépendances.
     *
     * @param DnsChecker $dnsChecker contrôles DNS (MX, Spamhaus)
     */
    public function __construct(private DnsChecker $dnsChecker)
    {
    }

    /**
     * Calcule le score d'une demande et son détail.
     *
     * Liens :
     * - Appelée par : RecruiterAccessManager::requestAccess()
     *
     * @param Recruiter   $recruiter demande (e-mail, entreprise, message)
     * @param string|null $ip        adresse IP de la demande
     *
     * @return array{score: int, details: list<array{step: string, label: string, result: string, level: string}>}
     */
    public function score(Recruiter $recruiter, ?string $ip): array
    {
        $details = [];
        $add = static function (string $step, string $label, string $result, string $level) use (&$details): void {
            $details[] = ['step' => $step, 'label' => $label, 'result' => $result, 'level' => $level];
        };
        $domain = (string) substr((string) strrchr($recruiter->getEmail(), '@'), 1);
        $score = 0;
        $blocked = false;

        if (\in_array($domain, self::DISPOSABLE_DOMAINS, true)) {
            $blocked = true;
            $add('disposable', 'Adresse jetable', \sprintf('Domaine %s : adresse jetable', $domain), 'danger');
        } else {
            $add('disposable', 'Adresse jetable', 'Non', 'success');
        }

        if ($this->dnsChecker->hasMailServer($domain)) {
            $score += 30;
            $add('mx', 'Serveurs de messagerie (MX)', \sprintf('Trouvés pour %s (+30)', $domain), 'success');
        } else {
            $add('mx', 'Serveurs de messagerie (MX)', \sprintf('Aucun pour %s : validation manuelle', $domain), 'warning');
        }

        if (null !== $ip && false !== filter_var($ip, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4 | \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            if ($this->dnsChecker->isListedBySpamhaus($ip)) {
                $blocked = true;
                $add('spamhaus', 'Adresse IP (Spamhaus)', 'Inscrite sur la liste de blocage', 'danger');
            } else {
                $add('spamhaus', 'Adresse IP (Spamhaus)', 'Non inscrite', 'success');
            }
        } else {
            $add('spamhaus', 'Adresse IP (Spamhaus)', 'Non vérifiée (adresse locale ou IPv6)', 'warning');
        }

        if (\in_array($domain, self::FREE_MAIL_DOMAINS, true)) {
            $add('domain', 'Domaine professionnel', 'Messagerie grand public (0)', 'warning');
        } elseif (!$blocked) {
            $score += 40;
            $add('domain', 'Domaine professionnel', \sprintf('%s (+40)', $domain), 'success');
        }

        if ('' !== $recruiter->getCompany()) {
            $score += 20;
            $add('company', 'Entreprise renseignée', \sprintf('%s (+20)', $recruiter->getCompany()), 'success');
        }

        if (null !== $recruiter->getMessage()) {
            $score += 10;
            $add('message', 'Message joint', 'Oui (+10)', 'success');
        }

        return ['score' => $blocked ? 0 : min(100, $score), 'details' => $details];
    }
}
