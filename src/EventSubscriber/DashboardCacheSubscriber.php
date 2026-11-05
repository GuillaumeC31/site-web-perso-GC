<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Dashboard\DashboardMetrics;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Vide le cache des tuiles du tableau de bord après tout envoi de formulaire (POST) : une décision de
 * l'admin, une demande d'accès ou un message de contact se voient aussitôt (sinon jusqu'à 60 s de retard).
 *
 * Liens :
 * - Écoute : kernel.terminate (après l'envoi de la réponse, sans la ralentir)
 * - Utilise : pool cache.dashboard (clé DashboardMetrics::CACHE_KEY), sans construire DashboardMetrics à
 *   chaque requête
 */
final readonly class DashboardCacheSubscriber implements EventSubscriberInterface
{
    /**
     * Reçoit le pool de cache des tuiles.
     */
    public function __construct(
        #[Autowire(service: 'cache.dashboard')]
        private CacheInterface $dashboardCache,
    ) {
    }

    /**
     * Déclare l'événement écouté.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::TERMINATE => 'onTerminate'];
    }

    /**
     * Vide le cache après une requête qui modifie des données (méthode autre que GET ou HEAD).
     *
     * Liens :
     * - Appelée par : le noyau Symfony (kernel.terminate)
     */
    public function onTerminate(TerminateEvent $event): void
    {
        if (!$event->getRequest()->isMethodSafe()) {
            $this->dashboardCache->delete(DashboardMetrics::CACHE_KEY);
        }
    }
}
