<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\Recruiter;
use App\Enum\RecruiterEventType;
use App\Service\Recruiter\InterestSignalNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Connexion réussie d'un recruteur : date de dernière connexion (propriété signée : le lien utilisé
 * devient caduc) et, à la première connexion après la validation, ACTIVITÉ « première connexion » (jamais un
 * signal d'intérêt, v2.0.3 : notification « Activité d'un recruteur ») et fenêtre de bienvenue (affichée une seule fois par l'accueil de l'espace).
 *
 * Liens :
 * - Écoute : LoginSuccessEvent (pare-feu « recruiter »)
 * - Utilise : InterestSignalNotifier, message flash WELCOME_FLASH (templates/recruiter_area/_welcome.html.twig)
 */
final readonly class RecruiterLoginSubscriber implements EventSubscriberInterface
{
    /** Message flash de la fenêtre de bienvenue (lu une seule fois par recruiter_area/home.html.twig). */
    public const WELCOME_FLASH = 'recruiter_welcome';

    /**
     * Reçoit les dépendances.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private InterestSignalNotifier $interestNotifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Déclare l'événement écouté.
     *
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    /**
     * Note la connexion ; à la première connexion, l'activité « première connexion » et la fenêtre de bienvenue.
     *
     * Liens :
     * - Appelée par : le composant Security
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $recruiter = $event->getUser();
        if ('recruiter' !== $event->getFirewallName() || !$recruiter instanceof Recruiter) {
            return;
        }
        $firstLogin = null === $recruiter->getLastLoginAt() || $recruiter->getLastLoginAt() < $recruiter->getDecidedAt();
        $now = $this->clock->now();
        $recruiter->setLastLoginAt($now)->touch($now);
        $this->entityManager->flush();
        if ($firstLogin) {
            $this->interestNotifier->recordActivity($recruiter, RecruiterEventType::FirstLogin);
            $session = $event->getRequest()->hasSession() ? $event->getRequest()->getSession() : null;
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add(self::WELCOME_FLASH, '1');
            }
        }
    }
}
