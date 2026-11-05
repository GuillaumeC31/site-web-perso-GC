<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Seo\SeoContext;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Environnement de test (SITE_NOINDEX=1) : en-tête « X-Robots-Tag: noindex, nofollow » sur toutes les
 * réponses (pages, PDF, images servies par PHP), en plus de la balise meta et du robots.txt.
 *
 * Liens :
 * - Utilise : SeoContext::isSiteNoIndex()
 * - Voir aussi : SitemapController::robots()
 * - Testé par : tests/Controller/SeoTest
 */
final readonly class NoIndexSubscriber implements EventSubscriberInterface
{
    /**
     * Reçoit le service de référencement.
     */
    public function __construct(private SeoContext $seoContext)
    {
    }

    /**
     * Déclare l'écoute de la réponse.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => 'addNoIndexHeader'];
    }

    /**
     * Pose l'en-tête X-Robots-Tag si le site entier est exclu des moteurs.
     *
     * Liens :
     * - Appelée par : l'évènement kernel.response
     */
    public function addNoIndexHeader(ResponseEvent $event): void
    {
        if ($event->isMainRequest() && $this->seoContext->isSiteNoIndex()) {
            $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }
    }
}
