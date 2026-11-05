<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Service\Ai\PrivateDataFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Filtre des données personnelles : e-mails et téléphones (questions et réponses du chatbot), et en plus
 * adresse, code postal et ville, date de naissance et âge (import de CV, synthèse d'entreprise).
 *
 * Liens :
 * - Couvre : PrivateDataFilter
 */
final class PrivateDataFilterTest extends TestCase
{
    /**
     * Vérifie que les e-mails et téléphones sont retirés, sous toutes leurs formes courantes.
     */
    #[DataProvider('contactProvider')]
    public function testContactDataIsRemoved(string $text, string $secret, string $type): void
    {
        $result = (new PrivateDataFilter())->filterContact($text);

        self::assertStringNotContainsString($secret, $result['text']);
        self::assertStringContainsString(PrivateDataFilter::MASK, $result['text']);
        self::assertContains($type, $result['removed']);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function contactProvider(): iterable
    {
        yield 'e-mail' => ['Écrivez-moi à jean.dupont+rh@cabinet-rh.test svp', 'jean.dupont+rh@cabinet-rh.test', 'e-mail'];
        yield 'mobile avec espaces' => ['Mon numéro : 06 12 34 56 78.', '06 12 34 56 78', 'téléphone'];
        yield 'mobile avec points' => ['Appelez le 06.12.34.56.78', '06.12.34.56.78', 'téléphone'];
        yield 'mobile collé' => ['tel 0612345678', '0612345678', 'téléphone'];
        yield 'international +33' => ['Joignable au +33 6 12 34 56 78', '+33 6 12 34 56 78', 'téléphone'];
        yield 'international 0033' => ['0033612345678 en journée', '0033612345678', 'téléphone'];
        yield 'étranger' => ['Bureau : +44 20 7946 0958', '+44 20 7946 0958', 'téléphone'];
    }

    /**
     * Vérifie que les chiffres du parcours ne sont pas pris pour des données personnelles.
     */
    public function testCareerFiguresAreKept(): void
    {
        $text = 'Près de 20 ans d\'expérience, 3 collaborateurs, 2 à 5 projets de 2019 à 2021, budget de XX 000 €.';

        self::assertSame($text, (new PrivateDataFilter())->filterAll($text)['text']);
        self::assertSame([], (new PrivateDataFilter())->filterAll($text)['removed']);
    }

    /**
     * Vérifie le filtre complet d'un CV : adresse, code postal et ville, date de naissance, âge, e-mail, téléphone.
     */
    public function testFilterAllRemovesEveryPersonalItemOfACv(): void
    {
        $cv = "Jean Dupont\n12 bis rue des Lilas, 69500 Lyon\nNé le 03/05/1980 — âgé de 45 ans\njean@exemple.test · 06 12 34 56 78\nResponsable bureau d'études depuis 2019.";
        $result = (new PrivateDataFilter())->filterAll($cv);

        foreach (['rue des Lilas', '69500', '03/05/1980', '45 ans', 'jean@exemple.test', '06 12 34 56 78'] as $secret) {
            self::assertStringNotContainsString($secret, $result['text'], $secret);
        }
        self::assertStringContainsString("Responsable bureau d'études depuis 2019.", $result['text']);
        self::assertEqualsCanonicalizing(['e-mail', 'date de naissance', 'téléphone', 'adresse', 'âge'], $result['removed']);
    }

    /**
     * Vérifie la date de naissance écrite en toutes lettres et le code postal seul.
     */
    public function testBirthDateInWordsAndPostcode(): void
    {
        $result = (new PrivateDataFilter())->filterAll('Date de naissance : 3 mai 1980. Domicile : 75011 Paris.');

        self::assertStringNotContainsString('1980', $result['text']);
        self::assertStringNotContainsString('75011', $result['text']);
        self::assertContains('code postal et ville', $result['removed']);
    }
}
