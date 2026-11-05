<?php

declare(strict_types=1);

namespace App\Service\Seo;

use App\Entity\SiteSettings;
use App\Entity\SocialLink;
use App\Repository\ProfileRepository;
use App\Repository\SiteSettingsRepository;
use App\Repository\SocialLinkRepository;
use App\Service\Content\ProfilePhotoProvider;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Référencement (CDC § 10, lot 8) : adresse canonique, image de partage, données structurées « Person »,
 * interdiction d'indexation de tout le site (environnement de test).
 *
 * Les adresses absolues partent de DEFAULT_URI (https://guillaume-couget.fr en production), jamais de
 * l'hôte de la requête : une adresse canonique ne doit pas dépendre de la façon dont la page est appelée.
 *
 * Données structurées : nom, titre, ville, photo, site et réseaux sociaux (« sameAs » : liens visibles cochés pour les
 * données structurées) ; jamais d'e-mail, de téléphone,
 * d'adresse postale ni de date de naissance.
 *
 * Liens :
 * - Utilisé par : SeoExtension (templates/base.html.twig), SitemapController, NoIndexSubscriber
 * - Utilise : SocialLinkRepository (sameAs), SiteSettings, Profile (titre, ville), ProfilePhotoProvider, ShareImageGenerator (image de partage), DEFAULT_URI, SITE_NOINDEX
 * - Testé par : tests/Controller/SeoTest
 */
final readonly class SeoContext
{
    /** Image de partage (Open Graph), 1200 × 630, relative à public/. */
    public const SHARE_IMAGE = 'partage/guillaume-couget-partage.jpg';

    /**
     * Région et pays publiés avec la ville (v2.0.2) : l'adresse des données structurées s'arrête là (ville,
     * région, pays), jamais de rue ni de code postal.
     */
    public const ADDRESS_REGION = 'Auvergne-Rhône-Alpes';
    public const ADDRESS_COUNTRY = 'FR';

    /** Dimensions de l'image de partage (pixels). */
    public const SHARE_IMAGE_WIDTH = 1200;
    public const SHARE_IMAGE_HEIGHT = 630;

    /**
     * Reçoit les dépendances et la configuration.
     *
     * @param string $defaultUri adresse publique du site (DEFAULT_URI)
     * @param string $noIndex    « 1 » : tout le site est exclu des moteurs (environnement de test)
     * @param string $publicDir  dossier public/ (présence de l'image de partage)
     */
    public function __construct(
        private RequestStack $requestStack,
        private SiteSettingsRepository $settingsRepository,
        private ProfileRepository $profileRepository,
        private ProfilePhotoProvider $photoProvider,
        private SocialLinkRepository $socialLinkRepository,
        private ShareImageGenerator $shareImageGenerator,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $defaultUri,
        #[Autowire('%env(default::SITE_NOINDEX)%')]
        private ?string $noIndex,
        #[Autowire('%kernel.project_dir%/public')]
        private string $publicDir,
    ) {
    }

    /**
     * Indique si tout le site doit être exclu des moteurs de recherche (SITE_NOINDEX=1).
     *
     * Liens :
     * - Appelée par : NoIndexSubscriber (en-tête X-Robots-Tag), SitemapController::robots(), base.html.twig
     */
    public function isSiteNoIndex(): bool
    {
        return \in_array(strtolower(trim((string) $this->noIndex)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Construit une adresse absolue à partir d'un chemin (« /cv » → « https://guillaume-couget.fr/cv »).
     *
     * Liens :
     * - Appelée par : getCanonicalUrl(), getShareImage(), getPersonData(), SitemapController
     */
    public function absoluteUrl(string $path): string
    {
        return rtrim($this->defaultUri, '/').'/'.ltrim($path, '/');
    }

    /**
     * Renvoie l'adresse canonique de la page en cours : chemin sans paramètres.
     *
     * Liens :
     * - Appelée par : SeoExtension (balises canonical et og:url)
     */
    public function getCanonicalUrl(): string
    {
        $request = $this->requestStack->getMainRequest();

        return $this->absoluteUrl(null === $request ? '/' : $request->getPathInfo());
    }

    /**
     * Renvoie l'image de partage : celle produite à partir du profil (ShareImageGenerator, nom propre à son contenu)
     * si elle existe, sinon l'image fixe versionnée (public/partage/).
     *
     * Liens :
     * - Appelée par : SeoExtension (balises og:image et twitter:image)
     * - Utilise : ShareImageGenerator::findCurrent()
     *
     * @return array{url: string, width: int, height: int}|null null si aucune image n'est en place
     */
    public function getShareImage(): ?array
    {
        $generated = $this->shareImageGenerator->findCurrent();
        if (null !== $generated) {
            return ['url' => $this->absoluteUrl($generated), 'width' => ShareImageGenerator::WIDTH, 'height' => ShareImageGenerator::HEIGHT];
        }
        if (!is_file($this->publicDir.'/'.self::SHARE_IMAGE)) {
            return null;
        }

        return ['url' => $this->absoluteUrl(self::SHARE_IMAGE), 'width' => self::SHARE_IMAGE_WIDTH, 'height' => self::SHARE_IMAGE_HEIGHT];
    }

    /**
     * Renvoie les paramètres du site (title et description de l'accueil).
     *
     * Liens :
     * - Appelée par : SeoExtension, templates/_partials/_footer.html.twig, home/index.html.twig
     */
    public function getSettings(): SiteSettings
    {
        return $this->settingsRepository->getOrCreate();
    }

    /**
     * Construit les données structurées schema.org « Person » (nom, titre, ville, photo, site, réseaux).
     *
     * Jamais de téléphone, d'e-mail, de rue, de code postal ni de date de naissance : seuls des champs publics du
     * profil sont lus (tests/Controller/SeoTest).
     *
     * Liens :
     * - Appelée par : getProfilePageGraph(), SeoExtension::personJsonLd()
     *
     * @return array<string, mixed>
     */
    public function getPersonData(): array
    {
        $profile = $this->profileRepository->getOrCreate();
        $photo = $this->photoProvider->getPhoto($profile->getPhotoFilename())['photo'];
        $person = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            '@id' => $this->absoluteUrl('/').'#person',
            'name' => $profile->getFullName(),
            'jobTitle' => $profile->getJobTitle(),
            'url' => $this->absoluteUrl('/'),
            'image' => $this->absoluteUrl($photo),
        ];
        $city = $this->publicCity($profile->getLocation());
        if (null !== $city) {
            $person['address'] = ['@type' => 'PostalAddress', 'addressLocality' => $city, 'addressRegion' => self::ADDRESS_REGION, 'addressCountry' => self::ADDRESS_COUNTRY];
        }
        // Réseaux visibles et cochés « données structurées » (Communication › Réseaux sociaux)
        $sameAs = array_map(static fn (SocialLink $link): string => $link->getUrl(), $this->socialLinkRepository->findVisibleFor(SocialLink::PLACE_STRUCTURED_DATA));
        if ([] !== $sameAs) {
            $person['sameAs'] = $sameAs;
        }

        return $person;
    }

    /**
     * Construit le graphe schema.org de la page : la personne (« Person ») et la page de profil (« ProfilePage »)
     * dont elle est le sujet principal.
     *
     * Liens :
     * - Appelée par : SeoExtension::profilePageJsonLd() (templates/home/index.html.twig, templates/cv/show.html.twig)
     * - Utilise : getPersonData(), getCanonicalUrl()
     *
     * @param string $pageName titre de la page (balise <title>)
     *
     * @return array{'@context': string, '@graph': list<array<string, mixed>>}
     */
    public function getProfilePageGraph(string $pageName): array
    {
        $person = $this->getPersonData();
        unset($person['@context']);
        $url = $this->getCanonicalUrl();

        return [
            '@context' => 'https://schema.org',
            '@graph' => [
                $person,
                [
                    '@type' => 'ProfilePage',
                    '@id' => $url.'#profilepage',
                    'url' => $url,
                    'name' => $pageName,
                    'inLanguage' => 'fr-FR',
                    'mainEntity' => ['@id' => $person['@id']],
                ],
            ],
        ];
    }

    /**
     * Ne garde de la localisation du profil que le nom de la ville (avant toute virgule, sans chiffre).
     *
     * Liens :
     * - Appelée par : getPersonData()
     *
     * @param string|null $location champ « Ville » du profil
     *
     * @return string|null la ville, ou null si le champ est vide ou contient un chiffre (rue, code postal)
     */
    private function publicCity(?string $location): ?string
    {
        $city = trim(explode(',', (string) $location)[0]);

        return '' === $city || 1 === preg_match('/\d/', $city) ? null : $city;
    }
}
