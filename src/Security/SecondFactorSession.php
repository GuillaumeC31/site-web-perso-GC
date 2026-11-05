<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Webauthn\PublicKeyCredentialOptions;

/**
 * Gère les marqueurs de session de la double authentification de l'admin.
 *
 * - Marqueur « clé vérifiée » : posé seulement après une vérification réussie de la
 *   YubiKey (ou l'enregistrement de la première clé). Il est lié au compte et à sa
 *   version de sécurité : une réinitialisation par SSH l'invalide immédiatement.
 * - Enrôlement en cours : posé après validation d'un lien d'enrôlement, il autorise
 *   l'enregistrement d'une clé pendant 30 minutes au plus.
 * - Options de cérémonie WebAuthn : le défi envoyé au navigateur, à usage unique.
 * - Horodatage de la session admin : début et dernière activité (durées maximales).
 *
 * ROLE_ADMIN seul ne suffit jamais : l'accès aux écrans d'admin exige le marqueur
 * (voir SecondFactorVoter).
 *
 * Liens :
 * - Utilisée par : SecondFactorVoter, SecondFactorController, AdminLoginSubscriber,
 *   AdminSessionLifetimeSubscriber, AdminAccessDeniedHandler
 */
final readonly class SecondFactorSession
{
    private const VERIFIED_KEY = '_admin_second_factor_verified';
    private const ENROLLMENT_KEY = '_admin_enrollment';
    private const CEREMONY_KEY_PREFIX = '_admin_webauthn_ceremony_';
    private const STARTED_AT_KEY = '_admin_session_started_at';
    private const LAST_ACTIVITY_KEY = '_admin_session_last_activity_at';

    /** Durée de validité d'un défi WebAuthn (secondes), alignée sur le délai de la clé. */
    private const CEREMONY_TTL_SECONDS = 180;

    /**
     * Reçoit l'accès à la requête courante et l'horloge.
     *
     * @param RequestStack   $requestStack pile des requêtes (accès à la session)
     * @param ClockInterface $clock        horloge (remplaçable en test)
     */
    public function __construct(
        private RequestStack $requestStack,
        private ClockInterface $clock,
    ) {
    }

    /**
     * Pose le marqueur « clé vérifiée » pour ce compte et change l'identifiant de session.
     *
     * Le changement d'identifiant empêche la réutilisation d'un identifiant de session
     * obtenu avant la vérification (fixation de session).
     *
     * Liens :
     * - Appelée par : SecondFactorController::verifyAssertion(), SecondFactorController::verifyRegistration()
     *
     * @param User $user compte qui vient de prouver la possession de sa clé
     */
    public function markVerified(User $user): void
    {
        $session = $this->getSession();
        $session->migrate(true);
        $session->set(self::VERIFIED_KEY, [
            'user_id' => $user->getId(),
            'security_version' => $user->getSecurityVersion(),
            'verified_at' => $this->now(),
        ]);
    }

    /**
     * Indique si la clé de ce compte a été vérifiée dans la session courante.
     *
     * Liens :
     * - Appelée par : SecondFactorVoter::voteOnAttribute(), SecondFactorController
     *
     * @param User $user compte connecté (chargé depuis la base, version de sécurité à jour)
     *
     * @return bool faux si aucun marqueur, si le marqueur vise un autre compte
     *              ou une ancienne version de sécurité
     */
    public function isVerified(User $user): bool
    {
        $marker = $this->findSession()?->get(self::VERIFIED_KEY);

        return \is_array($marker)
            && null !== $user->getId()
            && ($marker['user_id'] ?? null) === $user->getId()
            && ($marker['security_version'] ?? null) === $user->getSecurityVersion();
    }

    /**
     * Retire tous les marqueurs de double authentification (nouvelle connexion par mot de passe).
     *
     * Liens :
     * - Appelée par : AdminLoginSubscriber::onLoginSuccess()
     */
    public function reset(): void
    {
        $session = $this->findSession();
        if (null === $session) {
            return;
        }

        foreach (array_keys($session->all()) as $key) {
            if (str_starts_with($key, '_admin_')) {
                $session->remove($key);
            }
        }
    }

    /**
     * Ouvre une fenêtre d'enrôlement (enregistrement d'une clé) pour ce compte.
     *
     * Liens :
     * - Appelée par : SecondFactorController::startEnrollment() (après validation du lien)
     *
     * @param User               $user      compte connecté par mot de passe
     * @param \DateTimeImmutable $expiresAt fin de la fenêtre (celle du lien d'enrôlement)
     */
    public function startEnrollment(User $user, \DateTimeImmutable $expiresAt): void
    {
        $this->getSession()->set(self::ENROLLMENT_KEY, [
            'user_id' => $user->getId(),
            'security_version' => $user->getSecurityVersion(),
            'expires_at' => $expiresAt->getTimestamp(),
        ]);
    }

    /**
     * Indique si une fenêtre d'enrôlement valide est ouverte pour ce compte.
     *
     * Liens :
     * - Appelée par : SecondFactorController::canRegisterKey()
     *
     * @param User $user compte connecté
     */
    public function hasValidEnrollment(User $user): bool
    {
        $enrollment = $this->findSession()?->get(self::ENROLLMENT_KEY);

        return \is_array($enrollment)
            && ($enrollment['user_id'] ?? null) === $user->getId()
            && ($enrollment['security_version'] ?? null) === $user->getSecurityVersion()
            && \is_int($enrollment['expires_at'] ?? null)
            && $enrollment['expires_at'] > $this->now();
    }

    /**
     * Ferme la fenêtre d'enrôlement (après l'enregistrement d'une clé).
     *
     * Liens :
     * - Appelée par : SecondFactorController::verifyRegistration()
     */
    public function endEnrollment(): void
    {
        $this->findSession()?->remove(self::ENROLLMENT_KEY);
    }

    /**
     * Mémorise les options (défi) d'une cérémonie WebAuthn.
     *
     * Liens :
     * - Appelée par : SecondFactorController::assertionOptions(), SecondFactorController::registrationOptions()
     *
     * @param string                     $ceremony « assertion » (vérification) ou « registration » (enregistrement)
     * @param PublicKeyCredentialOptions $options  options envoyées au navigateur
     */
    public function storeCeremonyOptions(string $ceremony, PublicKeyCredentialOptions $options): void
    {
        $this->getSession()->set(self::CEREMONY_KEY_PREFIX.$ceremony, [
            'options' => $options,
            'expires_at' => $this->now() + self::CEREMONY_TTL_SECONDS,
        ]);
    }

    /**
     * Récupère puis efface les options d'une cérémonie : un défi ne sert qu'une fois.
     *
     * Liens :
     * - Appelée par : SecondFactorController::verifyAssertion(), SecondFactorController::verifyRegistration()
     *
     * @param string $ceremony « assertion » ou « registration »
     *
     * @return PublicKeyCredentialOptions|null les options, ou null si absentes ou expirées
     */
    public function takeCeremonyOptions(string $ceremony): ?PublicKeyCredentialOptions
    {
        $session = $this->findSession();
        $stored = $session?->get(self::CEREMONY_KEY_PREFIX.$ceremony);
        $session?->remove(self::CEREMONY_KEY_PREFIX.$ceremony);

        if (!\is_array($stored) || !($stored['options'] ?? null) instanceof PublicKeyCredentialOptions || ($stored['expires_at'] ?? 0) <= $this->now()) {
            return null;
        }

        return $stored['options'];
    }

    /**
     * Note le début de la session admin (connexion par mot de passe réussie).
     *
     * Liens :
     * - Appelée par : AdminLoginSubscriber::onLoginSuccess()
     */
    public function startAdminSession(): void
    {
        $session = $this->getSession();
        $session->set(self::STARTED_AT_KEY, $this->now());
        $session->set(self::LAST_ACTIVITY_KEY, $this->now());
    }

    /**
     * Indique si la session admin a dépassé l'une des deux durées maximales.
     *
     * Liens :
     * - Appelée par : AdminSessionLifetimeSubscriber::checkSessionLifetime()
     *
     * @param int $idleLimitSeconds     inactivité maximale (secondes)
     * @param int $absoluteLimitSeconds durée totale maximale (secondes)
     *
     * @return bool vrai si la session doit être fermée ; une session sans horodatage
     *              (antérieure à cette règle) est considérée comme expirée
     */
    public function isAdminSessionExpired(int $idleLimitSeconds, int $absoluteLimitSeconds): bool
    {
        $session = $this->getSession();
        $startedAt = $session->get(self::STARTED_AT_KEY);
        $lastActivityAt = $session->get(self::LAST_ACTIVITY_KEY);

        if (!\is_int($startedAt) || !\is_int($lastActivityAt)) {
            return true;
        }

        $now = $this->now();

        return $now - $lastActivityAt > $idleLimitSeconds || $now - $startedAt > $absoluteLimitSeconds;
    }

    /**
     * Met à jour l'heure de dernière activité de la session admin.
     *
     * Liens :
     * - Appelée par : AdminSessionLifetimeSubscriber::checkSessionLifetime()
     */
    public function touchAdminSession(): void
    {
        $this->getSession()->set(self::LAST_ACTIVITY_KEY, $this->now());
    }

    /**
     * Renvoie l'heure courante en secondes (horloge injectée).
     */
    private function now(): int
    {
        return $this->clock->now()->getTimestamp();
    }

    /**
     * Renvoie la session de la requête courante.
     *
     * @throws \LogicException hors requête HTTP (commande console)
     */
    private function getSession(): SessionInterface
    {
        return $this->findSession() ?? throw new \LogicException('Aucune session : la double authentification n\'est utilisable que dans une requête HTTP.');
    }

    /**
     * Renvoie la session de la requête courante, ou null hors requête ou sans session.
     */
    private function findSession(): ?SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();

        return null !== $request && $request->hasSession() ? $request->getSession() : null;
    }
}
