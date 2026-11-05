<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Interdit la mise en cache de toute réponse d'erreur de l'application (404, 403, 500…) : « Cache-Control:
 * no-store, private, max-age=0 ».
 *
 * Origine (v2.0.2) : le cache de l’hébergeur (« cache de l’hébergeur », en-têtes x-cache et Age) garde les pages 404 malgré
 * « Cache-Control: no-cache, private » ; une 404 produite par une ancienne version (en-têtes de sécurité en
 * double) continuait d'être servie après le déploiement, avec le même nonce CSP pour tous. Avec no-store, chaque
 * 404 est recalculée par Symfony, quel que soit le serveur ou le cache placé devant.
 *
 * Priorité très basse : passe après l'écouteur de session de Symfony, qui réécrit Cache-Control quand la session
 * a servi. Une réponse qui interdit déjà le cache (no-store) est laissée telle quelle.
 *
 * Liens :
 * - Voir aussi : TrustedDeviceCacheSubscriber (même règle pour les pages qui affichent le lien vers l'admin),
 *   tests/Security/SingleSecurityHeadersTest.php (404), scripts/security/check-prod-headers.sh
 */
final class ErrorResponseCacheSubscriber implements EventSubscriberInterface
{
    /**
     * Déclare l'écoute de la réponse, après l'écouteur de session (-1000).
     *
     * Liens :
     * - Appelée par : l'EventDispatcher de Symfony
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['preventErrorCaching', -2000]];
    }

    /**
     * Pose « no-store, private, max-age=0 » sur la réponse principale si son code est 400 ou plus.
     *
     * Liens :
     * - Appelée par : l'évènement kernel.response
     *
     * @param ResponseEvent $event évènement contenant la requête et la réponse
     */
    public function preventErrorCaching(ResponseEvent $event): void
    {
        $response = $event->getResponse();
        if (!$event->isMainRequest() || $response->getStatusCode() < 400) {
            return;
        }

        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->remove('Expires');
    }
}
