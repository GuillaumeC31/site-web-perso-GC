<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\SecurityEventType;
use App\Security\SecondFactorSession;
use App\Security\SecurityEventLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * Ferme la session admin après 1 heure d'inactivité ou 8 heures au total.
 *
 * Le contrôle se fait à chaque requête, juste après le pare-feu. La session expirée
 * est détruite (nouvel identifiant, plus aucun marqueur) et l'admin renvoyé vers la
 * page de connexion avec un message.
 *
 * Liens :
 * - Utilise : SecondFactorSession (horodatage), SecurityEventLogger, route admin_login
 * - Voir aussi : AdminLoginSubscriber (début de session), tests/Security/AdminSessionLifetimeTest
 */
final readonly class AdminSessionLifetimeSubscriber implements EventSubscriberInterface
{
    /** Inactivité maximale : 1 heure. */
    public const IDLE_LIMIT_SECONDS = 3600;

    /** Durée totale maximale : 8 heures. */
    public const ABSOLUTE_LIMIT_SECONDS = 28800;

    /**
     * Reçoit les dépendances.
     *
     * @param TokenStorageInterface $tokenStorage        jeton de sécurité courant
     * @param SecondFactorSession   $secondFactorSession horodatage de la session admin
     * @param SecurityEventLogger   $securityEventLogger journal des connexions
     * @param UrlGeneratorInterface $urlGenerator        générateur d'URL
     */
    public function __construct(
        private TokenStorageInterface $tokenStorage,
        private SecondFactorSession $secondFactorSession,
        private SecurityEventLogger $securityEventLogger,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Déclare l'écoute des requêtes, juste après le pare-feu (priorité 8).
     *
     * Liens :
     * - Appelée par : l'EventDispatcher de Symfony
     *
     * @return array<string, array{0: string, 1: int}>
     */
    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::REQUEST => ['checkSessionLifetime', 7]];
    }

    /**
     * Ferme la session si elle a dépassé une durée maximale, sinon note l'activité.
     *
     * Liens :
     * - Appelée par : l'évènement kernel.request
     * - Utilise : SecondFactorSession::isAdminSessionExpired(), SecondFactorSession::touchAdminSession()
     *
     * @param RequestEvent $event évènement de requête
     */
    public function checkSessionLifetime(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $user = $this->tokenStorage->getToken()?->getUser();
        if (!$user instanceof User) {
            return;
        }

        if (!$this->secondFactorSession->isAdminSessionExpired(self::IDLE_LIMIT_SECONDS, self::ABSOLUTE_LIMIT_SECONDS)) {
            $this->secondFactorSession->touchAdminSession();

            return;
        }

        $this->securityEventLogger->log(SecurityEventType::SessionExpired, $user->getUsername());
        $this->tokenStorage->setToken(null);

        $session = $event->getRequest()->getSession();
        $session->invalidate();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', 'admin.session.expired');
        }

        $event->setResponse(new RedirectResponse($this->urlGenerator->generate('admin_login')));
    }
}
