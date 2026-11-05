<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Page publique « Nouveautés » : ouverte depuis la version du pied de page, absente du menu principal, rendu du
 * résumé public (NOUVEAUTES.md) et jamais du CHANGELOG technique.
 *
 * Liens :
 * - Couvre : WhatsNewController, ChangelogRenderer::renderWhatsNew(), templates/whats_new/index.html.twig,
 *   templates/_partials/_footer.html.twig
 */
final class WhatsNewTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;

    /**
     * Vérifie le lien du pied de page, l'absence de lien dans le menu et le contenu de la page.
     */
    public function testWhatsNewPageIsLinkedFromFooterOnly(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');
        self::assertCount(1, $crawler->filter('footer a[href="/nouveautes"]'));
        self::assertCount(0, $crawler->filter('header a[href="/nouveautes"]'));

        $crawler = $client->request('GET', '/nouveautes');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Nouveautés');
        // Première rubrique : la version en cours (fichier VERSION, sans préversion)
        $version = preg_replace('/-.*$/', '', trim((string) file_get_contents(\dirname(__DIR__, 2).'/VERSION')));
        self::assertSelectorTextContains('[data-testid="whats-new"] h2', 'Version '.$version);
        // Le journal technique n'est jamais publié
        self::assertStringNotContainsString('alpha', $crawler->filter('main')->text());
        self::assertStringNotContainsString('CHANGELOG', $crawler->filter('main')->text());
    }

    /**
     * Vérifie les émojis accessibles et l'année calculée du pied de page.
     */
    public function testFooterCredits(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/contact');
        $credits = $crawler->filter('[data-testid="footer-credits"]');

        self::assertStringContainsString('© '.date('Y').' Guillaume Couget', $credits->text());
        self::assertStringContainsString('sous Symfony', $credits->text());
        self::assertSame('amour', $credits->filter('[role="img"]')->eq(0)->attr('aria-label'));
        self::assertSame('café', $credits->filter('[role="img"]')->eq(1)->attr('aria-label'));
    }
}
