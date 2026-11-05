<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Visiteur non connecté sur une page privée de l'espace recruteur : retour à la page de l'espace
 * recruteur (demande d'accès ou lien de connexion).
 *
 * Liens :
 * - Déclaré dans : config/packages/security.yaml (pare-feu « recruiter », entry_point)
 * - Redirige vers : recruiter_access (/espace-recruteur)
 */
final readonly class RecruiterEntryPoint implements AuthenticationEntryPointInterface
{
    /**
     * Reçoit le générateur d'URL.
     *
     * @param UrlGeneratorInterface $urlGenerator routeur
     */
    public function __construct(private UrlGeneratorInterface $urlGenerator)
    {
    }

    /**
     * Redirige vers la page de l'espace recruteur.
     *
     * Liens :
     * - Appelée par : le composant Security (accès refusé à un visiteur anonyme)
     */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->urlGenerator->generate('recruiter_access'));
    }
}
