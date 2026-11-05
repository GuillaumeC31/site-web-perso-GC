<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Profile;
use App\Entity\SocialLink;
use App\Enum\SocialNetwork;
use App\Repository\ProfileRepository;
use App\Tests\Support\RecruiterTestTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Référencement (lot 8) : adresse canonique, Open Graph et Twitter, données structurées Person sans e-mail
 * ni téléphone, sitemap.xml (pages publiques, jamais l'espace privé ni l'admin), robots.txt, et exclusion de
 * tout le site sur un environnement de test (SITE_NOINDEX=1).
 *
 * Liens :
 * - Couvre : SeoContext, SeoExtension, SitemapController, NoIndexSubscriber, templates/base.html.twig
 */
final class SeoTest extends WebTestCase
{
    use RecruiterTestTrait;

    protected function tearDown(): void
    {
        unset($_SERVER['SITE_NOINDEX'], $_ENV['SITE_NOINDEX']);
        parent::tearDown();
    }

    /**
     * Vérifie les balises de l'accueil : canonique, Open Graph, Twitter et Person (sans donnée privée).
     */
    public function testHomeHasCanonicalOpenGraphAndPerson(): void
    {
        $client = self::createClient();
        $this->createPrivateProfile();
        $profile = static::getContainer()->get(ProfileRepository::class)->getOrCreate()->setJobTitle('Responsable technique')->setLocation('Lyon');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->createSocialLinks();

        $crawler = $client->request('GET', '/?utm_source=test');

        self::assertSelectorExists('link[rel="canonical"][href$="/"]');
        self::assertStringNotContainsString('utm_source', (string) $crawler->filter('link[rel="canonical"]')->attr('href'), 'Adresse canonique sans paramètres.');
        self::assertSelectorExists('meta[property="og:type"][content="profile"]');
        self::assertSelectorExists('meta[property="og:locale"][content="fr_FR"]');
        self::assertStringContainsString('Responsable technique', (string) $crawler->filter('meta[property="og:title"]')->attr('content'));
        self::assertNotSame('', (string) $crawler->filter('meta[property="og:description"]')->attr('content'));
        self::assertSelectorExists('meta[name="twitter:card"]');

        $person = $this->assertProfilePageGraph($crawler->filter('script[type="application/ld+json"]')->text(), '/');
        self::assertSame(['Person', 'Responsable technique', 'Lyon', ['https://www.linkedin.com/in/exemple']], [$person['@type'], $person['jobTitle'], $person['address']['addressLocality'], $person['sameAs']]);
        self::assertInstanceOf(Profile::class, $profile);
    }

    /**
     * Vérifie les données structurées de /cv (v2.0.2) : même graphe Person + ProfilePage, nonce CSP de la page.
     */
    public function testCvHasProfilePageStructuredData(): void
    {
        $client = self::createClient();
        $this->createPrivateProfile();
        static::getContainer()->get(ProfileRepository::class)->getOrCreate()->setJobTitle('Responsable technique')->setLocation('Lyon');
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->createSocialLinks();

        $crawler = $client->request('GET', '/cv');

        self::assertResponseIsSuccessful();
        $script = $crawler->filter('script[type="application/ld+json"]');
        self::assertCount(1, $script);
        self::assertSame(1, preg_match("/'nonce-([^']+)'/", (string) $client->getResponse()->headers->get('Content-Security-Policy'), $nonce));
        self::assertSame($nonce[1], $script->attr('nonce'), 'Le bloc JSON-LD porte le nonce CSP de la page.');
        $this->assertProfilePageGraph($script->text(), '/cv');
    }

    /**
     * Vérifie un graphe JSON-LD Person + ProfilePage : JSON valide, adresse limitée à la ville, la région et le pays,
     * aucune donnée privée ; renvoie la personne.
     *
     * @param string $json texte du bloc <script type="application/ld+json">
     * @param string $path chemin de la page (adresse de la ProfilePage)
     *
     * @return array<string, mixed> le nœud « Person »
     */
    private function assertProfilePageGraph(string $json, string $path): array
    {
        $data = json_decode($json, true, 32, \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertSame('https://schema.org', $data['@context']);
        $nodes = array_column($data['@graph'], null, '@type');
        self::assertSame(['Person', 'ProfilePage'], array_keys($nodes));

        $person = $nodes['Person'];
        $page = $nodes['ProfilePage'];
        self::assertSame($person['@id'], $page['mainEntity']['@id']);
        self::assertStringEndsWith($path, (string) $page['url']);
        self::assertNotSame('', $page['name']);
        self::assertStringNotContainsString('&#', (string) $page['name'], 'Titre en texte brut.');
        self::assertSame(['@type' => 'PostalAddress', 'addressLocality' => 'Lyon', 'addressRegion' => 'Auvergne-Rhône-Alpes', 'addressCountry' => 'FR'], $person['address']);
        self::assertStringStartsWith('http', (string) $person['image']);

        foreach ([self::PRIVATE_EMAIL, self::PRIVATE_PHONE, 'telephone', 'email', 'birthDate', 'streetAddress', 'postalCode'] as $private) {
            self::assertStringNotContainsString($private, $json, $private);
        }

        return $person;
    }

    /**
     * Vérifie les liens sociaux du pied de page : visibles et cochés seulement, simples liens (nouvel onglet annoncé,
     * noopener noreferrer, icône locale), jamais un lien masqué ni décoché.
     */
    public function testFooterLinksToLinkedin(): void
    {
        $client = self::createClient();
        $this->createSocialLinks();
        $crawler = $client->request('GET', '/cv');

        $link = $crawler->filter('footer a[href="https://www.linkedin.com/in/exemple"]');
        self::assertCount(1, $link);
        self::assertSame('_blank', $link->attr('target'));
        self::assertSame(['me', 'noopener', 'noreferrer'], explode(' ', (string) $link->attr('rel')));
        self::assertStringContainsString('(nouvel onglet)', $link->text());
        self::assertCount(1, $link->filter('svg[aria-hidden="true"] path'));
        self::assertSelectorNotExists('footer a[href*="github"]', 'Lien masqué.');
        self::assertSelectorNotExists('footer a[href*="malt"]', 'Lien décoché pour le pied de page.');
        // Aucun widget ni ressource d'un réseau
        self::assertStringNotContainsString('platform.linkedin.com', (string) $client->getResponse()->getContent());
    }

    /**
     * Crée les liens sociaux de test : LinkedIn visible partout, GitHub masqué, Malt visible hors pied de page et hors
     * données structurées.
     */
    private function createSocialLinks(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::LinkedIn)->setUrl('https://www.linkedin.com/in/exemple')->setVisible(true)->setPosition(1));
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::GitHub)->setUrl('https://github.com/exemple')->setPosition(2));
        $entityManager->persist((new SocialLink())->setNetwork(SocialNetwork::Malt)->setUrl('https://www.malt.fr/profile/exemple')->setVisible(true)->setShowInFooter(false)->setShowInStructuredData(false)->setPosition(3));
        $entityManager->flush();
    }

    /**
     * Vérifie le plan du site : pages publiques et outils, jamais l'espace privé ni l'admin.
     */
    public function testSitemapListsPublicPagesOnly(): void
    {
        $client = self::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');
        $xml = (string) $client->getResponse()->getContent();
        foreach (['/cv</loc>', '/projets</loc>', '/calculatrice/electricite/loi-ohm</loc>', '/mentions-legales</loc>', '/confidentialite</loc>', '/espace-recruteur</loc>'] as $expected) {
            self::assertStringContainsString($expected, $xml);
        }
        $adminPath = (string) static::getContainer()->getParameter('app.admin_path');
        foreach (['/espace-recruteur/accueil', '/'.$adminPath.'</loc>', '/'.$adminPath.'/', 'cercle-loi-ohm'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $xml);
        }
    }

    /**
     * Vérifie robots.txt : espace privé exclu, plan du site annoncé, adresse de l'admin jamais citée.
     */
    public function testRobotsTxt(): void
    {
        $client = self::createClient();
        $client->request('GET', '/robots.txt');

        $robots = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /espace-recruteur/accueil', $robots);
        self::assertMatchesRegularExpression('#Sitemap: https?://\S+/sitemap\.xml#', $robots);
        self::assertStringNotContainsString((string) static::getContainer()->getParameter('app.admin_path'), $robots);
    }

    /**
     * Vérifie un environnement de test (SITE_NOINDEX=1) : meta noindex, en-tête X-Robots-Tag, robots.txt fermé.
     */
    public function testSiteNoIndexOnTestSubdomain(): void
    {
        $_SERVER['SITE_NOINDEX'] = $_ENV['SITE_NOINDEX'] = '1';
        $client = self::createClient();

        $client->request('GET', '/');
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');

        $client->request('GET', '/robots.txt');
        self::assertSame("User-agent: *\nDisallow: /\n", $client->getResponse()->getContent());
    }
}
