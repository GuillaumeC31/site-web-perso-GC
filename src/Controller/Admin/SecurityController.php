<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Gère la connexion et la déconnexion de l'administrateur.
 *
 * La vérification de l'identifiant et du mot de passe est faite par le pare-feu
 * (form_login dans security.yaml) ; l'étape YubiKey suit (SecondFactorController).
 * Ce contrôleur est volontairement sans #[IsGranted] : la page de connexion doit
 * rester accessible (règle PUBLIC_ACCESS dans access_control).
 * Il n'existe pas de « mot de passe oublié » : la réinitialisation se fait par SSH
 * (app:admin:reset-password).
 *
 * Liens :
 * - Rend : templates/admin/security/login.html.twig
 * - Voir aussi : config/packages/security.yaml, config/routes/admin.php (préfixe ADMIN_PATH),
 *   AdminLoginSubscriber (redirection après le mot de passe)
 */
final class SecurityController extends AbstractController
{
    /**
     * Affiche le formulaire de connexion de l'admin, avec la dernière erreur éventuelle.
     *
     * Liens :
     * - Route : admin_login (/{ADMIN_PATH}/connexion)
     * - Utilise : AuthenticationUtils (erreur et dernier identifiant saisi)
     * - Rend : templates/admin/security/login.html.twig
     *
     * @param AuthenticationUtils $authenticationUtils outils du composant Security
     *
     * @return Response la page HTML, ou une redirection si l'admin est déjà connecté
     *                  (vers le tableau de bord si la clé est vérifiée, sinon vers l'étape YubiKey)
     */
    #[Route('/connexion', name: 'login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('ADMIN_KEY_VERIFIED')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        if ($this->isGranted('ROLE_ADMIN')) {
            return $this->redirectToRoute('admin_second_factor');
        }

        return $this->render('admin/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Point de sortie de la déconnexion : jamais exécuté, le pare-feu intercepte la route.
     *
     * Liens :
     * - Route : admin_logout (/{ADMIN_PATH}/deconnexion)
     * - Voir aussi : security.yaml (firewalls.main.logout)
     *
     * @throws \LogicException toujours, si le pare-feu n'a pas intercepté la requête
     */
    #[Route('/deconnexion', name: 'logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode est interceptée par la clé logout du pare-feu.');
    }
}
