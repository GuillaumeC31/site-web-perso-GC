<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Content\Hyphenator;
use PHPUnit\Framework\Assert;

/**
 * Compare le texte VISIBLE d'une page : les césures conditionnelles (U+00AD) et les coupures possibles
 * (U+200B) posées dans le texte justifié (App\Service\Content\Hyphenator) sont invisibles, elles sont
 * donc retirées avant la comparaison.
 *
 * Remplace assertSelectorTextContains() et assertSelectorTextNotContains() du WebTestCase : sans cela,
 * « ne contient pas » passerait à tort sur un mot coupé (ex. une donnée privée dans un texte justifié).
 *
 * Liens :
 * - Utilisé par : les tests de pages (tests/Controller/*, tests/Security/*)
 */
trait VisibleTextAssertionsTrait
{
    /**
     * Vérifie que le premier élément du sélecteur contient le texte (texte visible).
     */
    public static function assertSelectorTextContains(string $selector, string $text, string $message = ''): void
    {
        Assert::assertStringContainsString($text, self::visibleText($selector), '' !== $message ? $message : \sprintf('« %s » ne contient pas « %s ».', $selector, $text));
    }

    /**
     * Vérifie que le premier élément du sélecteur ne contient pas le texte (texte visible).
     */
    public static function assertSelectorTextNotContains(string $selector, string $text, string $message = ''): void
    {
        Assert::assertStringNotContainsString($text, self::visibleText($selector), '' !== $message ? $message : \sprintf('« %s » contient « %s ».', $selector, $text));
    }

    /**
     * Retire les césures invisibles d'un texte.
     */
    private static function withoutHyphenation(string $text): string
    {
        return str_replace([Hyphenator::SOFT_HYPHEN, Hyphenator::BREAK_OPPORTUNITY], '', $text);
    }

    /**
     * Texte visible du premier élément du sélecteur, dans la dernière page chargée.
     */
    private static function visibleText(string $selector): string
    {
        $crawler = self::getClient()->getCrawler()->filter($selector);
        Assert::assertGreaterThan(0, $crawler->count(), \sprintf('Aucun élément « %s ».', $selector));

        return self::withoutHyphenation($crawler->text(null, true));
    }
}
