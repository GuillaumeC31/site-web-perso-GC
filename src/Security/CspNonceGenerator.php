<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Génère le nonce CSP de la requête en cours (une valeur aléatoire par réponse).
 *
 * Chaque script en ligne autorisé doit porter ce nonce (attribut
 * nonce="..."), sinon le navigateur le bloque grâce à la CSP stricte.
 *
 * Navigation Turbo : le navigateur garde la CSP de la PREMIÈRE page ; une requête Turbo renvoie donc le
 * nonce de cette page dans l'en-tête X-Csp-Nonce (assets/lib/csp_nonce.js), réutilisé ici pour que les
 * scripts de la nouvelle page soient acceptés. Conditions : requête Turbo (en-tête X-Turbo-Request-Id)
 * et valeur au format exact d'un nonce généré ici (base64 de 16 octets). Le nonce reste secret (tiré au
 * hasard au premier chargement) ; un autre site ne peut pas envoyer ces en-têtes (pré-vérification CORS).
 *
 * Liens :
 * - Utilisée par : SecurityHeadersSubscriber (en-tête Content-Security-Policy, scripts),
 *   ViteAssetNonceSubscriber (balises Vite), CspExtension (fonction Twig csp_nonce())
 * - Testée par : tests/Security/CspNonceTest
 */
final class CspNonceGenerator implements ResetInterface
{
    /** En-tête du nonce de la page d'origine, envoyé par les requêtes Turbo. */
    public const NONCE_HEADER = 'X-Csp-Nonce';

    /** En-tête posé par Turbo sur ses requêtes. */
    private const TURBO_HEADER = 'X-Turbo-Request-Id';

    /** Format exact d'un nonce généré ici : base64 de 16 octets. */
    private const NONCE_PATTERN = '#^[A-Za-z0-9+/]{22}==$#';

    private ?string $nonce = null;

    /**
     * Reçoit la pile des requêtes (en-têtes de la requête Turbo).
     */
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    /**
     * Renvoie le nonce de la requête courante, en le créant au premier appel (ou en reprenant celui de la
     * page d'origine pour une requête Turbo).
     *
     * Liens :
     * - Appelée par : SecurityHeadersSubscriber::addSecurityHeaders(),
     *   ViteAssetNonceSubscriber::addNonce(), CspExtension::getNonce()
     *
     * @return string nonce en base64 (128 bits d'aléa)
     */
    public function getNonce(): string
    {
        return $this->nonce ??= $this->turboPageNonce() ?? base64_encode(random_bytes(16));
    }

    /**
     * Oublie le nonce entre deux requêtes (utile si le noyau est réutilisé, et en test).
     *
     * Liens :
     * - Appelée par : le conteneur Symfony (services_resetter) à la fin de chaque requête
     */
    public function reset(): void
    {
        $this->nonce = null;
    }

    /**
     * Nonce de la page d'origine envoyé par une requête Turbo, s'il est au bon format ; sinon null.
     */
    private function turboPageNonce(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$request->headers->has(self::TURBO_HEADER)) {
            return null;
        }
        $nonce = (string) $request->headers->get(self::NONCE_HEADER, '');

        return 1 === preg_match(self::NONCE_PATTERN, $nonce) ? $nonce : null;
    }
}
