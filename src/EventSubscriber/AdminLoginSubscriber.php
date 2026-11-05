<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Entity\User;
use App\Enum\SecurityEventType;
use App\Security\SecondFactorSession;
use App\Security\SecurityEventLogger;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Réagit aux connexions et déconnexions de l'admin : journal, marqueurs et redirection.
 *
 * Après le mot de passe, la session n'a que ROLE_ADMIN : le marqueur « clé vérifiée »
 * est effacé et l'admin est envoyé vers l'étape YubiKey (ou vers le lien d'enrôlement
 * qu'il avait ouvert avant de se connecter).
 *
 * Liens :
 * - Utilise : SecondFactorSession, SecurityEventLogger, routes admin_second_factor et admin_second_factor_enroll_start
 * - Voir aussi : config/packages/security.yaml (form_login, logout)
 */
final readonly class AdminLoginSubscriber implements EventSubscriberInterface
{
    use TargetPathTrait;

    /** Nom du pare-feu (security.yaml). */
    private const FIREWALL = 'main';

    /**
     * Reçoit les dépendances.
     *
     * @param SecondFactorSession   $secondFactorSession marqueurs de double authentification
     * @param SecurityEventLogger   $securityEventLogger journal des connexions
     * @param UrlGeneratorInterface $urlGenerator        générateur d'URL
     */
    public function __construct(
        private SecondFactorSession $secondFactorSession,
        private SecurityEventLogger $securityEventLogger,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Déclare les évènements écoutés.
     *
     * Liens :
     * - Appelée par : l'EventDispatcher de Symfony
     *
     * @return array<class-string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            LoginSuccessEvent::class => 'onLoginSuccess',
            LoginFailureEvent::class => 'onLoginFailure',
            LogoutEvent::class => 'onLogout',
        ];
    }

    /**
     * Après un mot de passe correct : efface les marqueurs, note l'heure, journalise
     * et redirige vers l'étape YubiKey.
     *
     * Liens :
     * - Appelée par : le pare-feu (form_login) après authentification
     * - Utilise : SecondFactorSession::reset(), SecondFactorSession::startAdminSession()
     *
     * @param LoginSuccessEvent $event évènement de connexion réussie
     */
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $user = $event->getUser();
        if (!$user instanceof User) {
            return;
        }

        $this->secondFactorSession->reset();
        $this->secondFactorSession->startAdminSession();
        $this->securityEventLogger->log(SecurityEventType::PasswordSuccess, $user->getUsername());

        // La page demandée avant la connexion a déjà été lue (et retirée de la session) par
        // le gestionnaire de succès de Symfony : elle se trouve dans sa redirection.
        $session = $event->getRequest()->getSession();
        $targetPath = $event->getResponse()?->headers->get('Location') ?? $this->getTargetPath($session, self::FIREWALL);
        $this->removeTargetPath($session, self::FIREWALL);

        // Seul un lien d'enrôlement ouvert avant la connexion est repris ; toute autre
        // page demandée passera de toute façon par l'étape YubiKey.
        // Les liens d'enrôlement sont de la forme {page d'enrôlement}/{jeton}.
        $enrollPrefix = $this->urlGenerator->generate('admin_second_factor_enroll').'/';
        $targetPathOnly = null === $targetPath ? null : (string) parse_url($targetPath, \PHP_URL_PATH);

        $redirect = null !== $targetPathOnly && str_starts_with($targetPathOnly, $enrollPrefix)
            ? $targetPathOnly
            : $this->urlGenerator->generate('admin_second_factor');

        $event->setResponse(new RedirectResponse($redirect));
    }

    /**
     * Journalise un échec de connexion à l'admin (identifiant saisi, jamais le mot de passe) ; trop de
     * tentatives (login_throttling) : « limite de débit atteinte ». Seul le pare-feu de l'admin compte : un
     * lien de connexion recruteur refusé (déjà utilisé, expiré) déclenche le même évènement sur le pare-feu
     * « recruiter » et n'est pas un échec de connexion à l'admin (il gonflait la tuile Sécurité, 2026-10-26).
     *
     * Liens :
     * - Appelée par : le pare-feu (form_login) après un échec
     *
     * @param LoginFailureEvent $event évènement d'échec
     */
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        if (self::FIREWALL !== $event->getFirewallName()) {
            return;
        }
        $username = $event->getRequest()->getPayload()->get('_username');

        $this->securityEventLogger->log(
            $event->getException() instanceof TooManyLoginAttemptsAuthenticationException ? SecurityEventType::RateLimited : SecurityEventType::PasswordFailure,
            \is_string($username) ? $username : null,
        );
    }

    /**
     * Journalise une déconnexion volontaire.
     *
     * Liens :
     * - Appelée par : le pare-feu (route admin_logout)
     *
     * @param LogoutEvent $event évènement de déconnexion
     */
    public function onLogout(LogoutEvent $event): void
    {
        $user = $event->getToken()?->getUser();
        if ($user instanceof User) {
            $this->securityEventLogger->log(SecurityEventType::Logout, $user->getUsername());
        }
    }
}
