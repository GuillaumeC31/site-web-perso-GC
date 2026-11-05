<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\CvExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Mise en forme des textes du CV : gras sûr (HTML échappé d'abord), périodes au format du CV.
 *
 * Liens :
 * - Couvre : CvExtension (filtres cv_bold, cv_plain, capitalize_first, lower_first ; fonction cv_period_numeric)
 */
final class CvExtensionTest extends TestCase
{
    /**
     * Textes et rendu attendu du filtre cv_bold.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function provideBold(): iterable
    {
        yield 'mot-clé' => ['**Analyse des besoins clients** et conception', '<strong>Analyse des besoins clients</strong> et conception'];
        yield 'plusieurs' => ['de **2 à 5 projets**, sur **1 à 8 mois**.', 'de <strong>2 à 5 projets</strong>, sur <strong>1 à 8 mois</strong>.'];
        yield 'HTML échappé' => ['<script>alert(1)</script> **<b>gras</b>**', '&lt;script&gt;alert(1)&lt;/script&gt; <strong>&lt;b&gt;gras&lt;/b&gt;</strong>'];
        yield 'apostrophe' => ['d\'études', 'd&#039;études'];
        yield 'astérisques isolées' => ['2 ** 3 et ** fin', '2 ** 3 et ** fin'];
        yield 'vide' => ['', ''];
    }

    /**
     * Vérifie le gras : seul « **…** » devient <strong>, tout autre HTML reste échappé.
     */
    #[DataProvider('provideBold')]
    public function testBoldEscapesBeforeFormatting(string $text, string $expected): void
    {
        self::assertSame($expected, $this->extension()->bold($text));
    }

    /**
     * Vérifie le retrait des marques (textes sans mise en forme) et la casse de la première lettre.
     */
    public function testPlainAndCase(): void
    {
        $extension = $this->extension();
        self::assertSame('Gestion autonome des activités', $extension->plain('**Gestion autonome** des activités'));
        self::assertSame('Gestion de projets IA', $extension->capitalizeFirst('gestion de projets IA'));
        self::assertSame('maintenance préventive', $extension->lowerFirst('Maintenance préventive'));
    }

    /**
     * Vérifie le format des périodes du CV : mois/année, ou années seules.
     */
    public function testNumericPeriod(): void
    {
        $extension = $this->extension();
        self::assertSame('05/2021 – 02/2023', $extension->formatNumericPeriod(['start' => new \DateTimeImmutable('2021-05-01'), 'end' => new \DateTimeImmutable('2023-02-01')]));
        self::assertSame('2017 – 2019', $extension->formatNumericPeriod(['start' => new \DateTimeImmutable('2017-01-01'), 'end' => new \DateTimeImmutable('2017-01-01'), 'dates_in_years' => true]));
        self::assertSame('', $extension->formatNumericPeriod([]));
    }

    /**
     * Extension avec un traducteur factice (« depuis » n'est pas utilisé ici).
     */
    private function extension(): CvExtension
    {
        return new CvExtension($this->createStub(TranslatorInterface::class));
    }
}
