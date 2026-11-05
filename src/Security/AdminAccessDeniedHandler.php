<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Http\Authorization\AccessDeniedHandlerInterface;

/**
 * Oriente un admin connecté par mot de passe, mais sans clé vérifiée, vers l'étape YubiKey.
 *
 * Dans tous les autres cas (compte non admin, requête JSON), le refus reste un refus :
 * la page 403 standard est renvoyée. Ce gestionnaire n'accorde jamais l'accès.
 *
 * Liens :
 * - Déclaré dans : config/packages/security.yaml (firewalls.main.access_denied_handler)
 * - Utilise : SecondFactorSession::isVerified(), route admin_second_factor
 */
final readonly class AdminAccessDeniedHandler implements AccessDeniedHandlerInterface
{
    /**
     * Reçoit les dépendances.
     *
     * @param Security              $security            accès à l'utilisateur connecté
     * @param SecondFactorSession   $secondFactorSession marqueurs de double authentification
     * @param UrlGeneratorInterface $urlGenerator        générateur d'URL
     */
    public function __construct(
        private Security $security,
        private SecondFactorSession $secondFactorSession,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Construit la réponse à un accès refusé.
     *
     * Liens :
     * - Appelée par : le pare-feu (ExceptionListener) quand un contrôle d'accès échoue
     *
     * @param Request               $request               requête refusée
     * @param AccessDeniedException $accessDeniedException exception de refus
     *
     * @return Response|null redirection vers l'étape YubiKey, réponse JSON 403, ou null
     *                       (page 403 standard)
     */
    public function handle(Request $request, AccessDeniedException $accessDeniedException): ?Response
    {
        $user = $this->security->getUser();

        if (!$user instanceof User || !$user->isAdmin() || $this->secondFactorSession->isVerified($user)) {
            return null;
        }

        if ('json' === $request->getPreferredFormat() || 'json' === $request->getContentTypeFormat()) {
            return new JsonResponse(['status' => 'error', 'errorMessage' => 'second_factor_required'], Response::HTTP_FORBIDDEN);
        }

        return new RedirectResponse($this->urlGenerator->generate('admin_second_factor'));
    }
}
