<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\Recruiter;
use Psr\Clock\ClockInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse la connexion d'un recruteur dont l'accès n'est pas ouvert (demande en attente, refusée,
 * révoquée ou échue), même avec un lien de connexion encore valide.
 *
 * Liens :
 * - Déclaré dans : config/packages/security.yaml (pare-feu « recruiter »)
 * - Utilise : Recruiter::hasActiveAccess()
 */
final readonly class RecruiterUserChecker implements UserCheckerInterface
{
    /**
     * Reçoit l'horloge.
     *
     * @param ClockInterface $clock date courante
     */
    public function __construct(private ClockInterface $clock)
    {
    }

    /**
     * Vérifie l'accès avant l'authentification.
     *
     * Liens :
     * - Appelée par : le composant Security (login_link)
     *
     * @throws CustomUserMessageAccountStatusException si l'accès n'est pas ouvert
     */
    public function checkPreAuth(UserInterface $user): void
    {
        if ($user instanceof Recruiter && !$user->hasActiveAccess($this->clock->now())) {
            throw new CustomUserMessageAccountStatusException('recruiter.access_inactive');
        }
    }

    /**
     * Aucun contrôle après l'authentification.
     *
     * Liens :
     * - Appelée par : le composant Security
     */
    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
    }
}
