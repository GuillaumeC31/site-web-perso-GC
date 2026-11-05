<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Repository\SiteSettingsRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Délivrabilité du domaine d'expédition (Communication › E-mails) : SPF, DKIM et DMARC lus dans le DNS, avec
 * pour chacun ✅ / ❌, l'enregistrement trouvé, l'explication et la correction à faire. Résultat gardé 1 h en cache
 * (bouton « Vérifier maintenant » pour relire). En local (environnement autre que prod) : « non applicable »,
 * sans alerte et sans requête DNS.
 *
 * Règles de l’hébergeur (blog officiel, https://hebergeur.example.com/aide/dkim/, consulté le
 * 29/10/2026) : SPF avec « include:spf.hebergeur.example.com », un seul enregistrement SPF ; DKIM de l'hébergement au
 * sélecteur « default » (default._domainkey.<domaine>) ; DMARC « v=DMARC1; p=none; » au minimum, rapports vers
 * dmarc@<domaine>.
 *
 * Liens :
 * - Utilisé par : Admin\CommunicationController (onglets Vue d'ensemble et E-mails), DashboardMetrics (tuile
 *   Communication, résultat en cache seulement)
 * - Utilise : TxtRecordResolver, SiteSettingsRepository (sélecteur DKIM), cache.app
 * - Testé par : tests/Service/Mail/DeliverabilityCheckerTest
 */
final readonly class DeliverabilityChecker
{
    /** Durée du cache (secondes). */
    public const CACHE_TTL = 3600;

    /** Clé du cache. */
    public const CACHE_KEY = 'communication_deliverability';

    /** Élément SPF exigé pour les e-mails qui partent de l’hébergeur. */
    public const HEBERGEUR_SPF_INCLUDE = 'include:spf.hebergeur.example.com';

    /**
     * Reçoit les dépendances.
     *
     * @param string $fromAddress expéditeur (MAILER_FROM) : son domaine est vérifié
     * @param string $environment environnement Symfony (vérification en prod seulement)
     */
    public function __construct(
        private TxtRecordResolver $resolver,
        private SiteSettingsRepository $settingsRepository,
        #[Autowire(service: 'cache.app')]
        private CacheInterface&CacheItemPoolInterface $cache,
        private ClockInterface $clock,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $fromAddress,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * Indique si la vérification a un sens ici (site en ligne).
     */
    public function isApplicable(): bool
    {
        return 'prod' === $this->environment;
    }

    /**
     * Renvoie le résultat (cache de 1 h), ou le vérifie à nouveau.
     *
     * Liens :
     * - Appelée par : Admin\CommunicationController (overview(), emails(), checkDeliverability())
     *
     * @param bool $refresh vrai : ignore le cache (« Vérifier maintenant »)
     *
     * @return array{applicable: bool, domain: string, checked_at: \DateTimeImmutable|null, ok: bool, items: array<string, array{ok: bool, found: string|null, host: string}>}
     */
    public function check(bool $refresh = false): array
    {
        if (!$this->isApplicable()) {
            return ['applicable' => false, 'domain' => $this->domain(), 'checked_at' => null, 'ok' => true, 'items' => []];
        }
        if ($refresh) {
            $this->cache->delete(self::CACHE_KEY);
        }

        /* @var array{applicable: bool, domain: string, checked_at: \DateTimeImmutable|null, ok: bool, items: array<string, array{ok: bool, found: string|null, host: string}>} */
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::CACHE_TTL);

            return $this->checkNow();
        });
    }

    /**
     * Renvoie le dernier résultat gardé en cache, sans jamais interroger le DNS (tableau de bord).
     *
     * Liens :
     * - Appelée par : DashboardMetrics (tuile Communication)
     *
     * @return bool|null vrai si tout est bon, faux sinon ; null si non vérifié depuis 1 h ou non applicable
     */
    public function cachedStatus(): ?bool
    {
        if (!$this->isApplicable()) {
            return null;
        }
        $item = $this->cache->getItem(self::CACHE_KEY);
        $result = $item->isHit() ? $item->get() : null;

        return \is_array($result) ? (bool) $result['ok'] : null;
    }

    /**
     * Interroge le DNS et évalue SPF, DKIM et DMARC.
     *
     * Liens :
     * - Appelée par : check() ; tests (avec un résolveur simulé)
     *
     * @return array{applicable: bool, domain: string, checked_at: \DateTimeImmutable, ok: bool, items: array<string, array{ok: bool, found: string|null, host: string}>}
     */
    public function checkNow(): array
    {
        $domain = $this->domain();
        $selector = $this->settingsRepository->getOrCreate()->getDkimSelector();

        $spfRecords = array_values(array_filter($this->resolver->getTxtRecords($domain), static fn (string $txt): bool => str_starts_with(strtolower(trim($txt)), 'v=spf1')));
        $spf = 1 === \count($spfRecords) ? $spfRecords[0] : null;
        $dkimHost = $selector.'._domainkey.'.$domain;
        $dkim = self::first($this->resolver->getTxtRecords($dkimHost), static fn (string $txt): bool => 1 === preg_match('/(^|;)\s*p=[A-Za-z0-9+\/=]{20,}/', $txt));
        $dmarcHost = '_dmarc.'.$domain;
        $dmarc = self::first($this->resolver->getTxtRecords($dmarcHost), static fn (string $txt): bool => str_starts_with(strtolower(trim($txt)), 'v=dmarc1'));

        $items = [
            'spf' => ['ok' => null !== $spf && str_contains(strtolower($spf), self::HEBERGEUR_SPF_INCLUDE), 'found' => $spf ?? (\count($spfRecords) > 1 ? implode(' | ', $spfRecords) : null), 'host' => $domain],
            'dkim' => ['ok' => null !== $dkim, 'found' => null === $dkim ? null : mb_substr($dkim, 0, 60).(mb_strlen($dkim) > 60 ? '…' : ''), 'host' => $dkimHost],
            'dmarc' => ['ok' => null !== $dmarc, 'found' => $dmarc, 'host' => $dmarcHost],
        ];

        return [
            'applicable' => true,
            'domain' => $domain,
            'checked_at' => $this->clock->now(),
            'ok' => $items['spf']['ok'] && $items['dkim']['ok'] && $items['dmarc']['ok'],
            'items' => $items,
        ];
    }

    /**
     * Domaine d'expédition (partie après l'arobase de MAILER_FROM).
     */
    public function domain(): string
    {
        $at = strrpos($this->fromAddress, '@');

        return strtolower(trim(false === $at ? $this->fromAddress : substr($this->fromAddress, $at + 1)));
    }

    /**
     * Premier enregistrement qui vérifie la condition.
     *
     * @param list<string>           $records enregistrements TXT
     * @param callable(string): bool $test    condition
     */
    private static function first(array $records, callable $test): ?string
    {
        foreach ($records as $record) {
            if ($test($record)) {
                return $record;
            }
        }

        return null;
    }
}
