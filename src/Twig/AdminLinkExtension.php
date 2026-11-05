<?php

declare(strict_types=1);

namespace App\Twig;

use App\Security\TrustedDeviceCookie;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonction Twig admin_link() : l'adresse de l'admin, seulement pour un appareil de confiance.
 *
 * Sans cookie valide, la fonction renvoie null et l'adresse de l'admin (ADMIN_PATH) n'est écrite nulle part dans
 * le HTML. Avec un cookie valide, elle note dans la requête que la page contient le lien : la réponse sera alors
 * privée et jamais mise en cache (TrustedDeviceCacheSubscriber).
 *
 * Liens :
 * - Utilisée par : templates/_partials/_footer.html.twig (cœur ❤️ du pied de page)
 * - Utilise : TrustedDeviceCookie, route admin_dashboard
 * - Voir aussi : EventSubscriber\TrustedDeviceCacheSubscriber
 */
final class AdminLinkExtension extends AbstractExtension
{
    /** Attribut de requête posé quand la page affiche le lien vers l'admin. */
    public const REQUEST_ATTRIBUTE = '_admin_link_rendered';

    /**
     * Reçoit la pile des requêtes, le contrôle du cookie et le générateur d'adresses.
     *
     * @param RequestStack          $requestStack        requête courante (cookies)
     * @param TrustedDeviceCookie   $trustedDeviceCookie contrôle du cookie « appareil de confiance »
     * @param UrlGeneratorInterface $urlGenerator        adresse du tableau de bord de l'admin
     */
    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly TrustedDeviceCookie $trustedDeviceCookie,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Déclare la fonction Twig admin_link().
     *
     * Liens :
     * - Appelée par : Twig (enregistrement automatique de l'extension)
     *
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [new TwigFunction('admin_link', $this->getAdminLink(...))];
    }

    /**
     * Renvoie l'adresse du tableau de bord de l'admin pour un appareil de confiance, sinon null.
     *
     * Liens :
     * - Appelée par : templates/_partials/_footer.html.twig
     * - Utilise : TrustedDeviceCookie::isValid(), route admin_dashboard
     *
     * @return string|null l'adresse relative, ou null (pas de requête, cookie absent, modifié, expiré ou révoqué)
     */
    public function getAdminLink(): ?string
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request || !$this->trustedDeviceCookie->isValid($request)) {
            return null;
        }

        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        return $this->urlGenerator->generate('admin_dashboard');
    }
}
