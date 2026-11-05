<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Security\CspNonceGenerator;
use Pentatrion\ViteBundle\Event\RenderAssetTagEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ajoute le nonce CSP aux balises <script> et <link> générées par Vite.
 *
 * Les fonctions Twig vite_entry_script_tags() et vite_entry_link_tags() émettent
 * un évènement par balise : on y pose l'attribut nonce attendu par la CSP.
 *
 * Liens :
 * - Utilise : CspNonceGenerator, RenderAssetTagEvent (pentatrion/vite-bundle)
 * - Voir aussi : SecurityHeadersSubscriber, templates/base.html.twig
 */
final class ViteAssetNonceSubscriber implements EventSubscriberInterface
{
    /**
     * Reçoit le générateur de nonce.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring)
     *
     * @param CspNonceGenerator $nonceGenerator fournit le nonce de la requête
     */
    public function __construct(private readonly CspNonceGenerator $nonceGenerator)
    {
    }

    /**
     * Déclare l'écoute du rendu des balises Vite.
     *
     * Liens :
     * - Appelée par : l'EventDispatcher de Symfony
     *
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [RenderAssetTagEvent::class => 'addNonce'];
    }

    /**
     * Pose l'attribut nonce sur la balise en cours de rendu.
     *
     * Liens :
     * - Appelée par : TagRenderer de pentatrion/vite-bundle (via l'évènement)
     * - Utilise : CspNonceGenerator::getNonce()
     *
     * @param RenderAssetTagEvent $event évènement qui porte la balise à modifier
     */
    public function addNonce(RenderAssetTagEvent $event): void
    {
        $event->getTag()->setAttribute('nonce', $this->nonceGenerator->getNonce());
    }
}
