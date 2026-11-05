<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\TrustedDeviceCookie;
use App\Twig\AdminLinkExtension;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Interdit toute mise en cache partagée d'une page qui contient le lien vers l'admin.
 *
 * - Page qui affiche le lien (attribut posé par AdminLinkExtension) : « Cache-Control: no-store, private » ;
 * - toute requête qui porte un cookie « appareil de confiance », valide ou non : « Vary: Cookie », pour qu'aucun
 *   cache ne serve à un autre visiteur la version reçue par cet appareil.
 *
 * Priorité très basse : passe après l'écouteur de session de Symfony, qui réécrit Cache-Control quand la
 * session a servi.
 *
 * Liens :
 * - Utilise : AdminLinkExtension::REQUEST_ATTRIBUTE, TrustedDeviceCookie::NAME
 * - Voir aussi : tests/Controller/TrustedDeviceFooterTest (en-têtes de cache)
 */
final class TrustedDeviceCacheSubscriber implements EventSubscriberInterface
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
        return [KernelEvents::RESPONSE => ['protectAdminLink', -2000]];
    }

    /**
     * Pose les en-têtes de cache de la réponse principale.
     *
     * Liens :
     * - Appelée par : l'évènement kernel.response
     *
     * @param ResponseEvent $event évènement contenant la requête et la réponse
     */
    public function protectAdminLink(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $response = $event->getResponse();

        if ($request->cookies->has(TrustedDeviceCookie::NAME)) {
            $response->setVary('Cookie', false);
        }

        if (true === $request->attributes->get(AdminLinkExtension::REQUEST_ATTRIBUTE)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->remove('Expires');
            $response->setVary('Cookie', false);
        }
    }
}
