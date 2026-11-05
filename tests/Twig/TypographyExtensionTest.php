<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Service\Content\Hyphenator;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

/**
 * Texte justifié : césure française côté serveur (filtres hyphenate et hyphenate_html) et classe
 * .text-prose-justify posée sur le seul texte courant.
 *
 * Liens :
 * - Couvre : App\Service\Content\Hyphenator, App\Twig\TypographyExtension, templates/cv/*, templates/legal/_section.html.twig
 */
final class TypographyExtensionTest extends WebTestCase
{
    /** Césure conditionnelle, affichée « | » dans les comparaisons. */
    private const SHY = Hyphenator::SOFT_HYPHEN;

    /**
     * Vérifie les coupures françaises (motifs TeX), y compris après une lettre accentuée, et le seuil de
     * 9 lettres (décision du 2026-10-25) : « méthodes » (8 lettres) n'est jamais coupé, « compétent » (9) l'est.
     */
    public function testFrenchHyphenationPoints(): void
    {
        self::bootKernel();
        $hyphenator = static::getContainer()->get(Hyphenator::class);
        $show = static fn (string $text): string => str_replace([self::SHY, Hyphenator::BREAK_OPPORTUNITY], ['|', '¦'], $text);

        self::assertSame('ap|pro|vi|sion|ne|ments', $show($hyphenator->hyphenate('approvisionnements')));
        self::assertSame('com|pé|tences, méthodes, com|pé|tent', $show($hyphenator->hyphenate('compétences, méthodes, compétent')));
        self::assertSame('froid et CVC', $show($hyphenator->hyphenate('froid et CVC')), 'Mots courts : jamais coupés.');
        self::assertSame('gaz/¦vapeur', $show($hyphenator->hyphenate('gaz/vapeur')), 'Coupure possible après la barre oblique ; « vapeur » est trop court pour être coupé.');
        self::assertSame('', $hyphenator->hyphenate(null));
    }

    /**
     * Vérifie le filtre HTML : balises, attributs, code et adresse protégée intacts ; texte brut toujours échappé.
     */
    public function testHtmlFilterKeepsMarkupAndEscapesText(): void
    {
        self::bootKernel();
        $twig = static::getContainer()->get(Environment::class);
        $html = $twig->createTemplate('{% apply hyphenate_html %}<p class="documentation">Les <a href="https://exemple.fr/documentation">documentations</a> <code>documentation</code> <span data-controller="protected-email">administration</span></p>{% endapply %}')->render();

        self::assertStringContainsString('class="documentation"', $html);
        self::assertStringContainsString('href="https://exemple.fr/documentation"', $html);
        self::assertStringContainsString('<code>documentation</code>', $html);
        self::assertStringContainsString('>administration</span>', $html);
        self::assertStringContainsString('do'.self::SHY.'cu'.self::SHY, $html);

        // Balises HTML5 inconnues de libxml (contenu Markdown des projets) : aucune erreur
        $html5 = $twig->createTemplate('{% apply hyphenate_html %}<section><p>Une <mark>caractérisation</mark> complète</p></section>{% endapply %}')->render();
        self::assertStringContainsString('<mark>ca'.self::SHY, $html5);
        self::assertStringContainsString('</section>', $html5);

        // Titres, adresses et tableaux du contenu : jamais coupés
        $kept = $twig->createTemplate('{% apply hyphenate_html %}<h2>Caractérisation</h2><address>Hébergement</address><table><tr><td>Caractérisation</td></tr></table>{% endapply %}')->render();
        self::assertStringNotContainsString(self::SHY, $kept);

        $text = $twig->createTemplate('{{ value|hyphenate }}')->render(['value' => '<script>alert(1)</script> documentation']);
        self::assertStringNotContainsString('<script>', $text);
        self::assertStringContainsString('&lt;script&gt;', $text);
    }

    /**
     * Vérifie les pages : missions et pages légales justifiées et coupées ; titres, logiciels et pied de page jamais.
     */
    public function testJustifiedBlocksOnPages(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/mentions-legales');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertSelectorExists('section .text-prose-justify');
        self::assertStringContainsString(self::SHY, $crawler->filter('section .text-prose-justify')->first()->text());
        foreach (['h1', 'h2', 'footer', 'nav', 'button'] as $selector) {
            foreach ($crawler->filter($selector) as $node) {
                self::assertInstanceOf(\DOMElement::class, $node);
                self::assertStringNotContainsString('text-prose-justify', $node->getAttribute('class'), $selector);
                self::assertStringNotContainsString(self::SHY, $node->textContent, $selector.' coupé');
            }
        }
        self::assertCount(0, $crawler->filter('footer .text-prose-justify, nav .text-prose-justify, h1.text-prose-justify, h2.text-prose-justify'));
    }
}
