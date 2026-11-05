<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Recruiter;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Ferme la session d'un recruteur dont l'accès n'est plus ouvert (révoqué, échu, supprimé) : le
 * contrôle de RecruiterUserChecker n'a lieu qu'à la connexion, une session ouverte doit aussi s'arrêter.
 *
 * Le recruteur est relu en base à chaque requête par le pare-feu : un changement fait dans l'admin
 * s'applique dès la requête suivante.
 *
 * Liens :
 * - Écoute : kernel.request, après le pare-feu (priorité 7)
 * - Utilise : Recruiter::hasActiveAccess(), Security::logout(), route recruiter_access
 * - Testé par : tests/Controller/RecruiterAreaControllerTest
 */
final readonly class RecruiterSessionSubscriber implements EventSubscriberInterface
{
    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private Security $security,
        private ClockInterface $clock,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Déclare l'événement écouté (le pare-feu a la priorité 8).
     *
     * @return array<string, array{string, int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['onKernelRequest', 7]];
    }

    /**
     * Déconnecte le recruteur et renvoie vers la page publique de l'espace recruteur si son accès est fermé.
     *
     * Liens :
     * - Appelée par : le noyau, à chaque requête principale
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $recruiter = $this->security->getUser();
        if (!$recruiter instanceof Recruiter || $recruiter->hasActiveAccess($this->clock->now())) {
            return;
        }
        // Pas de jeton CSRF : la déconnexion est imposée par le serveur, pas demandée par un lien
        $this->security->logout(false);
        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('recruiter_access')));
    }
}
