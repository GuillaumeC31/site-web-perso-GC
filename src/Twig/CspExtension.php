<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\CspNonceGenerator;
use Twig\Attribute\AsTwigFunction;

/**
 * Expose le nonce CSP aux templates Twig via la fonction csp_nonce().
 *
 * Usage : <script nonce="{{ csp_nonce() }}">…</script>
 *
 * Liens :
 * - Utilise : CspNonceGenerator
 * - Utilisée par : templates/base.html.twig, templates/admin/base.html.twig
 */
final class CspExtension
{
    /**
     * Reçoit le générateur de nonce.
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (autowiring)
     *
     * @param CspNonceGenerator $nonceGenerator fournit le nonce de la requête
     */
    public function __construct(private readonly CspNonceGenerator $nonceGenerator)
    {
    }

    /**
     * Renvoie le nonce CSP de la requête en cours.
     *
     * Liens :
     * - Appelée par : la fonction Twig csp_nonce()
     * - Utilise : CspNonceGenerator::getNonce()
     *
     * @return string le nonce, identique à celui de l'en-tête Content-Security-Policy
     */
    #[AsTwigFunction('csp_nonce')]
    public function getNonce(): string
    {
        return $this->nonceGenerator->getNonce();
    }
}
