<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Interdit toute mise en cache (navigateur, Cloudflare, cache de l’hébergeur) d'une réponse privée ou
 * personnalisée : « Cache-Control: no-store, private » et « Vary: Cookie ».
 *
 * Réponses visées (v2.0.3) :
 * - l'admin (routes admin_*), l'espace recruteur (/espace-recruteur…, PDF du CV complet compris), la connexion et
 *   la déconnexion ;
 * - toute réponse qui pose un cookie ou dont la session a servi (contenu propre au visiteur) ;
 * - toute page qui contient un jeton CSRF (formulaire de contact, demande d'accès…).
 *
 * cache de l’hébergeur garde les fichiers statiques et gardait les 404 : no-store garantit qu'une page
 * de ce type n'est jamais resservie à un autre visiteur. Priorité très basse : après l'écouteur de session de Symfony,
 * qui réécrit Cache-Control.
 *
 * Liens :
 * - Voir aussi : ErrorResponseCacheSubscriber (réponses d'erreur), TrustedDeviceCacheSubscriber (lien vers l'admin),
 *   tests/Security/PrivateResponseCacheTest, scripts/security/check-prod-headers.sh
 */
final class PrivateResponseCacheSubscriber implements EventSubscriberInterface
{
    /** Débuts d'adresse des espaces privés (hors admin, repéré par le nom de route). */
    private const PRIVATE_PATH_PREFIXES = ['/espace-recruteur'];

    /** Marques d'un jeton CSRF dans une page HTML. */
    private const CSRF_MARKERS = ['name="_token"', 'name="_csrf_token"', '[_token]', 'data-controller="csrf-protection"'];

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
        return [KernelEvents::RESPONSE => ['preventPrivateCaching', -2000]];
    }

    /**
     * Pose « no-store, private » et « Vary: Cookie » sur une réponse principale privée ou personnalisée.
     *
     * Liens :
     * - Appelée par : l'évènement kernel.response
     * - Utilise : isPrivate()
     *
     * @param ResponseEvent $event évènement contenant la requête et la réponse
     */
    public function preventPrivateCaching(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->isPrivate($event->getRequest(), $event->getResponse())) {
            return;
        }

        $response = $event->getResponse();
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
        $response->headers->remove('Expires');
        $response->setVary('Cookie', false);
    }

    /**
     * Indique si la réponse est privée ou personnalisée.
     *
     * @param Request  $request  requête
     * @param Response $response réponse
     */
    private function isPrivate(Request $request, Response $response): bool
    {
        $route = (string) $request->attributes->get('_route', '');
        if (str_starts_with($route, 'admin_')) {
            return true;
        }
        foreach (self::PRIVATE_PATH_PREFIXES as $prefix) {
            if (str_starts_with($request->getPathInfo(), $prefix)) {
                return true;
            }
        }
        if ([] !== $response->headers->getCookies()) {
            return true;
        }
        if ($request->hasSession(true) && $request->getSession()->isStarted()) {
            return true;
        }

        $type = (string) $response->headers->get('Content-Type', '');
        $content = $response->getContent();
        if (false === $content || ('' !== $type && !str_contains($type, 'html'))) {
            return false;
        }
        foreach (self::CSRF_MARKERS as $marker) {
            if (str_contains($content, $marker)) {
                return true;
            }
        }

        return false;
    }
}
