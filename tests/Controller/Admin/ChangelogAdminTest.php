<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Service\Admin\ChangelogRenderer;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Journal des versions dans l'admin (lecture seule) : contenu de CHANGELOG.md, lien depuis la version du
 * pied de page, HTML brut du fichier échappé (jamais interprété), accès réservé à l'admin.
 *
 * Liens :
 * - Couvre : Admin\ChangelogController, ChangelogRenderer, templates/admin/base.html.twig (pied de page)
 */
final class ChangelogAdminTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /**
     * Vérifie la page et le lien du pied de page.
     */
    public function testChangelogPageIsLinkedFromTheFooterVersion(): void
    {
        $client = self::createClient();
        $this->loginFullyAsAdmin($client);

        $crawler = $client->request('GET', $this->adminUrl());
        $link = $crawler->filter('footer a[href="'.$this->adminUrl('/journal-des-versions').'"]');
        self::assertCount(1, $link);
        self::assertStringStartsWith('Version ', trim($link->text()));

        $client->request('GET', $this->adminUrl('/journal-des-versions'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Journal des versions');
        self::assertSelectorTextContains('[data-testid="changelog"]', '2.0.0-alpha.9');
        // Journal entier, même au-delà de 20 000 octets (limite par défaut du nettoyeur HTML) : la plus ancienne version
        self::assertSelectorTextContains('[data-testid="changelog"]', '2.0.0-alpha.1');
        self::assertSelectorExists('[data-testid="changelog"] h2');
    }

    /**
     * Vérifie que le HTML brut est échappé et que les liens dangereux sont retirés.
     */
    public function testRawHtmlIsEscaped(): void
    {
        self::bootKernel();
        $html = static::getContainer()->get(ChangelogRenderer::class)->render("## Version\n<script>alert(1)</script>\n\n[lien](javascript:alert(1)) et **gras**");

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringContainsString('<strong>gras</strong>', $html);
    }

    /**
     * Vérifie que la page est réservée à l'admin (sans connexion : renvoi vers la connexion).
     */
    public function testChangelogRequiresAdmin(): void
    {
        $client = self::createClient();
        $client->request('GET', $this->adminUrl('/journal-des-versions'));

        self::assertResponseRedirects();
    }
}
