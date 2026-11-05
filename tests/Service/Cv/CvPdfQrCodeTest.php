<?php

declare(strict_types=1);

namespace App\Tests\Service\Cv;

use App\Service\Cv\CvPdfRenderer;
use App\Service\Cv\PublicCvPdfCache;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * QR code des PDF du CV (v2.0.2) : CV public (design et ATS) → espace recruteur ; CV complet de l'espace recruteur →
 * CV en ligne. Dessiné par mPDF (mpdf/qrcode), avec une légende ; le PDF ATS reste sans tableau de mise en page.
 *
 * Le décodage réel (QR lu à 150 ppp, côté d'environ 17 mm, sans chevauchement) a été vérifié sur les quatre PDF
 * produits ; ici, on contrôle l'adresse codée, la légende, la présence dans le PDF et la clé du cache.
 *
 * Liens :
 * - Couvre : Service\Cv\CvPdfRenderer::qrCode(), templates/cv/pdf/design.html.twig, templates/cv/pdf/ats.html.twig,
 *   Service\Cv\PublicCvPdfCache (le HTML, donc le QR code, fait partie de la clé)
 */
final class CvPdfQrCodeTest extends KernelTestCase
{
    /**
     * Chaque PDF porte un seul QR code vers la bonne adresse, avec sa légende.
     *
     * @param string $variant « design » ou « ats »
     * @param bool   $private vrai : CV complet de l'espace recruteur
     * @param string $path    chemin codé dans le QR code
     * @param string $caption légende attendue
     */
    #[DataProvider('providePdfs')]
    public function testQrCodeTargetsTheRightPage(string $variant, bool $private, string $path, string $caption): void
    {
        self::bootKernel();
        $renderer = self::getContainer()->get(CvPdfRenderer::class);
        $base = rtrim((string) $_SERVER['DEFAULT_URI'], '/');

        $html = $renderer->renderHtml($variant, $private);

        self::assertSame(1, substr_count($html, '<barcode '), 'Un seul QR code');
        self::assertStringContainsString('<barcode code="'.$base.$path.'" type="QR"', $html);
        self::assertStringContainsString($caption, $html);
        if ('ats' === $variant) {
            self::assertStringNotContainsString('<table', $html, 'PDF ATS : aucun tableau de mise en page.');
        }

        $pdf = $renderer->render($variant, $private);
        self::assertStringStartsWith('%PDF', $pdf);
    }

    /**
     * Les quatre PDF : public et complet, design et ATS.
     *
     * @return iterable<string, array{string, bool, string, string}>
     */
    public static function providePdfs(): iterable
    {
        yield 'public design' => ['design', false, '/espace-recruteur', 'Scanner pour demander le CV complet'];
        yield 'public ATS' => ['ats', false, '/espace-recruteur', 'Scanner pour demander le CV complet'];
        yield 'complet design' => ['design', true, '/cv', 'Scanner pour le CV en ligne'];
        yield 'complet ATS' => ['ats', true, '/cv', 'Scanner pour le CV en ligne'];
    }

    /**
     * Le QR code fait partie du HTML passé à mPDF, donc de la clé du cache des PDF publics : un PDF gardé avant la
     * v2.0.2 (sans QR code) n'est jamais resservi.
     */
    public function testQrCodeIsPartOfThePublicCacheKey(): void
    {
        self::bootKernel();
        $cache = self::getContainer()->get(PublicCvPdfCache::class);
        $cache->clear();

        $pdf = $cache->get('design');

        self::assertStringStartsWith('%PDF', $pdf);
        $html = self::getContainer()->get(CvPdfRenderer::class)->renderHtml('design');
        self::assertStringContainsString('type="QR"', $html);
        $cache->clear();
    }
}
