<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SiteSettings;
use App\Service\Seo\SeoContext;
use Twig\Attribute\AsTwigFunction;

/**
 * Fonctions Twig du référencement : seo_canonical(), seo_share_image(), seo_noindex(), seo_settings(),
 * seo_person_json_ld().
 *
 * Liens :
 * - Utilise : SeoContext
 * - Utilisée par : templates/base.html.twig (en-tête), templates/home/index.html.twig (Person),
 *   templates/_partials/_footer.html.twig
 */
final readonly class SeoExtension
{
    /**
     * Reçoit le service de référencement.
     */
    public function __construct(private SeoContext $seoContext)
    {
    }

    /**
     * Adresse canonique de la page en cours.
     *
     * Liens :
     * - Appelée par : base.html.twig (link rel="canonical", og:url)
     */
    #[AsTwigFunction('seo_canonical')]
    public function canonical(): string
    {
        return $this->seoContext->getCanonicalUrl();
    }

    /**
     * Image de partage (url, width, height), ou null si elle n'est pas installée.
     *
     * Liens :
     * - Appelée par : base.html.twig (og:image, twitter:image)
     *
     * @return array{url: string, width: int, height: int}|null
     */
    #[AsTwigFunction('seo_share_image')]
    public function shareImage(): ?array
    {
        return $this->seoContext->getShareImage();
    }

    /**
     * Vrai si tout le site est exclu des moteurs (environnement de test).
     *
     * Liens :
     * - Appelée par : base.html.twig (meta robots)
     */
    #[AsTwigFunction('seo_noindex')]
    public function noIndex(): bool
    {
        return $this->seoContext->isSiteNoIndex();
    }

    /**
     * Paramètres du site (title et description de l'accueil).
     *
     * Liens :
     * - Appelée par : home/index.html.twig, _partials/_footer.html.twig
     */
    #[AsTwigFunction('seo_settings')]
    public function settings(): SiteSettings
    {
        return $this->seoContext->getSettings();
    }

    /**
     * Données structurées « Person » en JSON (échappé pour un bloc <script type="application/ld+json">).
     *
     * Liens :
     * - Appelée par : home/index.html.twig
     */
    #[AsTwigFunction('seo_person_json_ld', isSafe: ['html'])]
    public function personJsonLd(): string
    {
        return $this->encodeJsonLd($this->seoContext->getPersonData());
    }

    /**
     * Données structurées « Person » et « ProfilePage » en JSON, pour un bloc <script type="application/ld+json">
     * portant le nonce CSP de la page.
     *
     * Liens :
     * - Appelée par : home/index.html.twig, cv/show.html.twig
     * - Utilise : SeoContext::getProfilePageGraph()
     *
     * @param string $pageName titre de la page
     */
    #[AsTwigFunction('seo_profile_page_json_ld', isSafe: ['html'])]
    public function profilePageJsonLd(string $pageName): string
    {
        // Le titre vient d'un bloc Twig déjà échappé (« d&#039;études ») : texte brut dans le JSON
        return $this->encodeJsonLd($this->seoContext->getProfilePageGraph(trim(html_entity_decode($pageName, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'))));
    }

    /**
     * Encode des données JSON-LD : JSON_HEX_* empêche tout « </script> » ou chevron dans le bloc.
     *
     * @param array<string, mixed> $data données schema.org
     */
    private function encodeJsonLd(array $data): string
    {
        return (string) json_encode($data, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT);
    }
}
