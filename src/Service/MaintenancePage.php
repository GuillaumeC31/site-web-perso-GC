<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\SiteSettings;
use App\Entity\SocialLink;
use App\Repository\SocialLinkRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * Page de maintenance : réponse 503 avec Retry-After (secondes jusqu'à la date de réactivation si elle est à
 * venir ; 60 s si elle est dépassée, « Retour imminent » ; 1 h sans date), jamais en cache, en noindex ; ou
 * aperçu (200) pour l'admin, sans activer la maintenance.
 *
 * Liens :
 * - Utilisé par : EventSubscriber\MaintenanceSubscriber (site en maintenance),
 *   Admin\SiteSettingsController::maintenancePreview() (aperçu)
 * - Rend : templates/maintenance.html.twig
 * - Testé par : tests/Controller/MaintenanceTest
 */
final readonly class MaintenancePage
{
    /** Délai proposé aux navigateurs et aux robots sans date de réactivation (secondes). */
    public const DEFAULT_RETRY_AFTER = 3600;

    /** Délai minimal proposé (secondes) : une date passée ou toute proche ne donne jamais 0. */
    public const MIN_RETRY_AFTER = 60;

    /**
     * Reçoit le moteur de templates, l'horloge et les liens sociaux (lus ici : aucune requête dans le gabarit).
     */
    public function __construct(
        private Environment $twig,
        private ClockInterface $clock,
        private SocialLinkRepository $socialLinkRepository,
    ) {
    }

    /**
     * Construit la réponse de la page de maintenance.
     *
     * Liens :
     * - Appelée par : MaintenanceSubscriber::onRequest(), Admin\SiteSettingsController::maintenancePreview()
     *
     * @param SiteSettings $settings paramètres (message, date de réactivation)
     * @param bool         $preview  vrai : aperçu de l'admin (200, bandeau « Aperçu ») ; faux : 503 et Retry-After
     */
    public function render(SiteSettings $settings, bool $preview = false): Response
    {
        $until = $settings->getMaintenanceUntil();
        $upcoming = null !== $until && $until > $this->clock->now() ? $until : null;
        // Date dépassée : « Retour imminent » et nouvel essai toutes les 60 s (navigateur et robots)
        $imminent = null !== $until && null === $upcoming;
        $response = new Response($this->twig->render('maintenance.html.twig', [
            'message' => $settings->getMaintenanceMessage(),
            'until' => $upcoming,
            'imminent' => $imminent,
            // Réseaux visibles et cochés « page de maintenance » (Communication › Réseaux sociaux)
            'social_links' => $this->socialLinkRepository->findVisibleFor(SocialLink::PLACE_MAINTENANCE),
            'preview' => $preview,
        ]), $preview ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE);
        if (!$preview) {
            $response->headers->set('Retry-After', (string) ($imminent ? self::MIN_RETRY_AFTER : $this->retryAfter($upcoming)));
        }
        if ($imminent) {
            // Rechargement toutes les 60 s, même sans JavaScript (en-tête plutôt que balise meta refresh)
            $response->headers->set('Refresh', (string) self::MIN_RETRY_AFTER);
        }
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    /**
     * Calcule le délai Retry-After (secondes).
     *
     * @param \DateTimeImmutable|null $until date de réactivation à venir, ou null
     */
    public function retryAfter(?\DateTimeImmutable $until): int
    {
        if (null === $until) {
            return self::DEFAULT_RETRY_AFTER;
        }

        return max(self::MIN_RETRY_AFTER, $until->getTimestamp() - $this->clock->now()->getTimestamp());
    }
}
