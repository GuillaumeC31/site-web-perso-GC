<?php

declare(strict_types=1);

namespace App\Service\Ai;

use App\Repository\SiteSettingsRepository;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\Exception\MaxWaitDurationExceededException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\CacheStorage;

/**
 * Débit maximal des appels à l'IA pour tout le site (hypothèse à confirmer : 1 requête par seconde,
 * réglable dans l'admin). Un appel qui arrive trop tôt attend dans une file courte (2 s au plus) ;
 * au-delà, il est refusé poliment et le recruteur bascule sur l'assistant guidé.
 *
 * Liens :
 * - Utilisé par : AiCaller::call() (avant chaque appel au fournisseur)
 * - Utilise : SiteSettings::getAiRequestsPerSecond(), pool de cache cache.ai (config/packages/cache.yaml)
 */
final readonly class AiThrottle
{
    /** Attente maximale dans la file (secondes). */
    public const MAX_WAIT_SECONDS = 2.0;

    /**
     * Reçoit les dépendances.
     *
     * @param CacheItemPoolInterface $cache compteurs partagés par toutes les requêtes du site
     */
    public function __construct(
        private SiteSettingsRepository $settingsRepository,
        #[Autowire(service: 'cache.ai')]
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Réserve une place pour un appel, en attendant au plus 2 s.
     *
     * Liens :
     * - Appelée par : AiCaller::call()
     *
     * @throws AiProviderException RATE_LIMITED si la file est trop longue (refus poli, sans appel)
     */
    public function acquire(): void
    {
        $perSecond = max(1, $this->settingsRepository->getOrCreate()->getAiRequestsPerSecond());
        $factory = new RateLimiterFactory([
            'id' => 'ai_site',
            'policy' => 'token_bucket',
            'limit' => $perSecond,
            'rate' => ['interval' => '1 second', 'amount' => $perSecond],
        ], new CacheStorage($this->cache));

        try {
            $factory->create('site')->reserve(1, self::MAX_WAIT_SECONDS)->wait();
        } catch (MaxWaitDurationExceededException) {
            throw new AiProviderException(AiProviderException::RATE_LIMITED, 'Trop de demandes simultanées sur le site (débit maximal atteint).');
        }
    }
}
