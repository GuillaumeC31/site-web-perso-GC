<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Security\SecondFactorSession;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Accorde l'attribut ADMIN_KEY_VERIFIED : compte admin ET clé YubiKey vérifiée dans la session.
 *
 * C'est la condition d'accès à tous les écrans de l'administration (access_control et
 * #[IsGranted] des contrôleurs). Le mot de passe seul donne ROLE_ADMIN, mais jamais cet attribut.
 *
 * Liens :
 * - Utilise : SecondFactorSession::isVerified()
 * - Utilisé par : config/packages/security.yaml (access_control), contrôleurs de src/Controller/Admin/
 * - Voir aussi : scripts/security/audit-route-guards.sh, tests/Security/AdminSecondFactorTest
 *
 * @extends Voter<string, mixed>
 */
final class SecondFactorVoter extends Voter
{
    /** Attribut à exiger sur les écrans d'administration. */
    public const ADMIN_KEY_VERIFIED = 'ADMIN_KEY_VERIFIED';

    /**
     * Reçoit le gestionnaire des marqueurs de session.
     *
     * @param SecondFactorSession $secondFactorSession marqueurs de double authentification
     */
    public function __construct(private readonly SecondFactorSession $secondFactorSession)
    {
    }

    /**
     * Indique si ce voter traite l'attribut demandé.
     *
     * Liens :
     * - Appelée par : le composant Security (AccessDecisionManager)
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::ADMIN_KEY_VERIFIED === $attribute;
    }

    /**
     * Accorde l'accès seulement à un compte admin dont la clé a été vérifiée dans cette session.
     *
     * Liens :
     * - Appelée par : le composant Security (AccessDecisionManager)
     * - Utilise : SecondFactorSession::isVerified()
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User || !$user->isAdmin()) {
            $vote?->addReason('Compte non administrateur.');

            return false;
        }

        if (!$this->secondFactorSession->isVerified($user)) {
            $vote?->addReason('Clé de sécurité non vérifiée dans cette session.');

            return false;
        }

        return true;
    }
}
