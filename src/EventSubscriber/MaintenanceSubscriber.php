<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Repository\SiteSettingsRepository;
use App\Service\MaintenancePage;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Mode maintenance (Paramètres de l'admin) : le site public répond « 503 Service indisponible » avec la page
 * de maintenance (MaintenancePage : message, date de réactivation, Retry-After). Restent accessibles : l'admin
 * (pour désactiver le mode), la validation à distance (/telegram/webhook, /discord/interactions,
 * /validation-distance/…), les adresses IP autorisées dans Paramètres (facultatif), les fichiers compilés
 * (/build), robots.txt, et la barre de débogage en développement. Les fichiers statiques de public/ (logo,
 * police de la page) sont servis par le serveur web sans passer par Symfony.
 *
 * Liens :
 * - Utilise : SiteSettings (isMaintenanceEnabled(), getMaintenanceAllowedIpList()), MaintenancePage
 * - Testé par : tests/Controller/MaintenanceTest
 */
final readonly class MaintenanceSubscriber implements EventSubscriberInterface
{
    /**
     * Reçoit les dépendances.
     *
     * @param string $adminPath chemin secret de l'admin (ADMIN_PATH)
     */
    public function __construct(
        private SiteSettingsRepository $settingsRepository,
        private MaintenancePage $page,
        #[Autowire('%app.admin_path%')]
        private string $adminPath,
    ) {
    }

    /**
     * Déclare l'écoute de la requête, avant le pare-feu (priorité 20).
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onRequest', 20]];
    }

    /**
     * Répond la page de maintenance si le mode est actif et que l'adresse ou le visiteur n'est pas exempté.
     *
     * Liens :
     * - Appelée par : l'évènement kernel.request
     */
    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if (!$event->isMainRequest() || $this->isExempt($request->getPathInfo())) {
            return;
        }
        $settings = $this->settingsRepository->getOrCreate();
        if (!$settings->isMaintenanceEnabled()) {
            return;
        }
        $allowed = $settings->getMaintenanceAllowedIpList();
        if ([] !== $allowed && null !== $request->getClientIp() && IpUtils::checkIp($request->getClientIp(), $allowed)) {
            return;
        }

        $event->setResponse($this->page->render($settings));
    }

    /**
     * Indique si une adresse reste accessible pendant la maintenance.
     */
    private function isExempt(string $path): bool
    {
        // Dossiers exemptés : l'adresse exacte ou tout ce qui est en dessous
        // Validation à distance (webhook Telegram, interactions Discord, page de confirmation) : utilisable pendant la maintenance
        foreach (['/'.$this->adminPath, '/build', '/_wdt', '/_profiler', '/telegram/webhook', '/discord/interactions', '/validation-distance'] as $directory) {
            if ($path === $directory || str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        return '/robots.txt' === $path;
    }
}
