<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\VisibleTextAssertionsTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Tests fonctionnels des pages publiques : chaque page s'affiche, a un titre
 * principal, le lien d'accès au contenu et aucun lien vers l'administration.
 *
 * Liens :
 * - Couvre : HomeController, LegalController, templates/base.html.twig
 */
final class PublicPagesTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;

    /**
     * Vérifie qu'une page publique répond 200 avec un <h1>, en français.
     */
    #[DataProvider('providePublicUrls')]
    public function testPublicPageIsDisplayed(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('h1');
        self::assertSame('fr', $crawler->filter('html')->attr('lang'));
        self::assertSelectorExists('a[href="#main-content"]');
    }

    /**
     * Vérifie qu'aucune page publique ne contient de lien vers l'administration (CDC §4).
     */
    #[DataProvider('providePublicUrls')]
    public function testPublicPageHasNoAdminLink(string $url): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', $url);
        $adminPath = (string) self::getContainer()->getParameter('app.admin_path');

        $adminLinks = $crawler->filter('a')->reduce(
            static fn ($link): bool => str_starts_with((string) $link->attr('href'), '/'.$adminPath),
        );

        self::assertCount(0, $adminLinks, 'Lien vers l\'admin trouvé sur '.$url);
    }

    /**
     * Vérifie que l'accueil propose les trois actions attendues (parcours, contact, CV PDF).
     */
    public function testHomeShowsCallsToAction(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorExists('a[href="/cv"]');
        self::assertSelectorExists('a[href="/contact"]');
        self::assertSelectorExists('a[href="/cv/pdf/design"]');
    }

    /**
     * Vérifie que, sans profil saisi, l'accueil affiche le titre de poste retenu (valeur par défaut).
     */
    public function testHomeShowsDefaultJobTitleWithoutProfile(): void
    {
        $client = self::createClient();
        $client->request('GET', '/');

        self::assertSelectorTextContains('main', 'Responsable Bureau d\'Études — Génie climatique & électrique');
        self::assertSelectorTextNotContains('body', '30 ans');
    }

    /**
     * Vérifie que les pages légales, livrées au lot 8, sont indexables.
     */
    public function testLegalPagesAreIndexed(): void
    {
        $client = self::createClient();
        foreach (['/mentions-legales', '/confidentialite'] as $url) {
            $client->request('GET', $url);
            self::assertSelectorExists('meta[name="robots"][content="index, follow"]', $url);
        }
    }

    /**
     * Vérifie qu'une page inexistante répond 404.
     */
    public function testUnknownPageReturns404(): void
    {
        $client = self::createClient();
        $client->request('GET', '/page-qui-n-existe-pas');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * URL publiques à vérifier.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function providePublicUrls(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'cv' => ['/cv'];
        yield 'projets' => ['/projets'];
        yield 'calculatrice' => ['/calculatrice'];
        yield 'contact' => ['/contact'];
        yield 'espace recruteur' => ['/espace-recruteur'];
        yield 'mentions légales' => ['/mentions-legales'];
        yield 'confidentialité' => ['/confidentialite'];
    }
}
