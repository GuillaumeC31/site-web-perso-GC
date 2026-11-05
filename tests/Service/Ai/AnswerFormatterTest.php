<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\AnswerFormatter;
use PHPUnit\Framework\TestCase;

/**
 * Mise en forme des réponses du chatbot : texte brut sans Markdown, coupé à la 3e phrase complète.
 *
 * Liens :
 * - Couvre : AnswerFormatter
 */
final class AnswerFormatterTest extends TestCase
{
    /**
     * Vérifie le retrait du Markdown : gras, italique, titres, listes, liens (texte gardé), code.
     */
    public function testMarkdownIsRemoved(): void
    {
        $answer = "## Profil\n**Guillaume** est _responsable_ BE.\n- Froid\n- Électricité\n1. Voir [son CV](https://exemple.fr/cv) et `Python`.";

        $text = (new AnswerFormatter())->stripMarkdown($answer);

        self::assertSame('Profil Guillaume est responsable BE. Froid Électricité Voir son CV et Python.', $text);
        foreach (['**', '##', '](', 'https://', '`', '- '] as $mark) {
            self::assertStringNotContainsString($mark, $text);
        }
    }

    /**
     * Vérifie la coupe à la 3e phrase complète.
     */
    public function testAnswerIsCutAfterTheThirdSentence(): void
    {
        $text = (new AnswerFormatter())->format('Première phrase. Deuxième phrase ! Troisième phrase ? Quatrième phrase. Cinquième.');

        self::assertSame('Première phrase. Deuxième phrase ! Troisième phrase ?', $text);
    }

    /**
     * Vérifie que les abréviations et les nombres décimaux ne coupent pas une phrase.
     */
    public function testAbbreviationsDoNotEndASentence(): void
    {
        $text = (new AnswerFormatter())->format('Il maîtrise des outils, ex. Python et SolidWorks. Il a géré 2.5 M€ de projets. Il vit à Lyon. Il aime le vélo.');

        self::assertSame('Il maîtrise des outils, ex. Python et SolidWorks. Il a géré 2.5 M€ de projets. Il vit à Lyon.', $text);
    }

    /**
     * Vérifie qu'un texte de trois phrases ou moins est gardé tel quel, et qu'un texte trop long est coupé au dernier mot.
     */
    public function testShortAnswersAreKeptAndLongOnesTruncated(): void
    {
        $formatter = new AnswerFormatter();

        self::assertSame('Une seule phrase sans point final', $formatter->format('Une seule phrase sans point final'));
        $long = $formatter->format(str_repeat('mot ', 400));
        self::assertLessThanOrEqual(AnswerFormatter::MAX_LENGTH + 1, mb_strlen($long));
        self::assertStringEndsWith('mot…', $long);
    }
}
