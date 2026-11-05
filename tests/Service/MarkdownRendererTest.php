<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Content\MarkdownRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests du rendu Markdown des projets : mise en forme conservée, contenu dangereux retiré.
 *
 * Liens :
 * - Couvre : MarkdownRenderer, config/packages/html_sanitizer.yaml (app.project_content)
 */
final class MarkdownRendererTest extends KernelTestCase
{
    /**
     * Vérifie que la mise en forme utile est conservée (titres, gras, listes, tableaux, images du site).
     */
    public function testKeepsFormatting(): void
    {
        $html = $this->render("## Contexte\n\nTexte **gras** et *italique*.\n\n- un\n- deux\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n![Schéma](/uploads/projects/abc.webp)\n\n[Site](https://exemple.fr/page)");

        self::assertStringContainsString('<h2>Contexte</h2>', $html);
        self::assertStringContainsString('<strong>gras</strong>', $html);
        self::assertStringContainsString('<li>deux</li>', $html);
        self::assertStringContainsString('<table>', $html);
        self::assertStringContainsString('src="/uploads/projects/abc.webp"', $html);
        self::assertStringContainsString('href="https://exemple.fr/page"', $html);
        self::assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    /**
     * Vérifie que scripts, styles, événements, iframes, liens javascript: et mailto: sont retirés.
     */
    public function testRemovesDangerousContent(): void
    {
        $html = $this->render("<script>alert(1)</script>\n\n<p onclick=\"x()\" style=\"color:red\">p</p>\n\n<iframe src=\"https://exemple.fr\"></iframe>\n\n[a](javascript:alert(1)) [b](mailto:prive@example.test) <a href=\"javascript:alert(2)\">c</a>");

        // Les balises interdites sont soit retirées, soit affichées comme du texte (échappées) : jamais exécutées.
        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('onclick', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('<iframe', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('mailto:', $html);
        self::assertStringNotContainsString('prive@example.test"', $html);
    }

    /**
     * Vérifie que les images externes et le titre de niveau 1 ne passent pas, et que http devient https.
     */
    public function testRestrictsImagesHeadingsAndLinks(): void
    {
        $html = $this->render("# Titre principal\n\n![x](https://autre-site.example/x.png)\n\n[lien](http://exemple.fr)");

        self::assertStringNotContainsString('<h1', $html);
        self::assertStringContainsString('Titre principal', $html);
        self::assertStringNotContainsString('autre-site.example', $html);
        self::assertStringContainsString('href="https://exemple.fr"', $html);
    }

    /**
     * Vérifie qu'un contenu vide donne une chaîne vide.
     */
    public function testEmptyContentGivesEmptyString(): void
    {
        self::assertSame('', $this->render(null));
        self::assertSame('', $this->render("  \n "));
    }

    /**
     * Rend du Markdown avec le service réel.
     */
    private function render(?string $markdown): string
    {
        self::bootKernel();

        return static::getContainer()->get(MarkdownRenderer::class)->render($markdown);
    }
}
