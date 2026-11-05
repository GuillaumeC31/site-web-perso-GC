<?php

declare(strict_types=1);

namespace App\Tests\Security;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Icônes du site (v2.0.2) : public/favicon.ico existe (les navigateurs le demandent d'eux-mêmes, une 404 était
 * relevée par le test Playwright « aucune erreur dans le journal »), au format ICO 16/32/48 px ; l'icône d'écran
 * d'accueil fait 180 px ; les pages publiques, l'admin et la maintenance déclarent les icônes existantes.
 *
 * Liens :
 * - Couvre : public/favicon.ico, public/favicon.svg, public/apple-touch-icon.png (scripts/images/build-favicons.php),
 *   templates/base.html.twig, templates/admin/base.html.twig, templates/maintenance.html.twig
 */
final class FaviconTest extends WebTestCase
{
    /**
     * favicon.ico : en-tête ICO, trois images de 16, 32 et 48 px, chacune au format PNG.
     */
    public function testFaviconIcoContainsThreeSizes(): void
    {
        $ico = (string) file_get_contents(\dirname(__DIR__, 2).'/public/favicon.ico');

        $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
        self::assertSame(['reserved' => 0, 'type' => 1, 'count' => 3], $header);

        $sizes = [];
        for ($index = 0; $index < 3; ++$index) {
            $entry = unpack('Cwidth/Cheight/Ccolors/Creserved/vplanes/vbits/Vlength/Voffset', substr($ico, 6 + 16 * $index, 16));
            self::assertIsArray($entry);
            $sizes[] = $entry['width'];
            self::assertSame("\x89PNG", substr($ico, $entry['offset'], 4), 'Image '.$entry['width'].' px au format PNG');
        }
        self::assertSame([16, 32, 48], $sizes);
    }

    /**
     * apple-touch-icon.png : PNG de 180 × 180 px.
     */
    public function testAppleTouchIconIs180Pixels(): void
    {
        $size = getimagesize(\dirname(__DIR__, 2).'/public/apple-touch-icon.png');

        self::assertIsArray($size);
        self::assertSame([180, 180, 'image/png'], [$size[0], $size[1], $size['mime']]);
    }

    /**
     * Chaque icône déclarée par les pages existe dans public/ (aucune balise vers un fichier absent).
     */
    public function testDeclaredIconsExist(): void
    {
        $client = self::createClient();
        $crawler = $client->request('GET', '/');

        $hrefs = $crawler->filter('link[rel="icon"], link[rel="apple-touch-icon"]')->each(static fn ($link): string => (string) $link->attr('href'));
        self::assertContains('/favicon.ico', $hrefs);
        self::assertContains('/favicon.svg', $hrefs);
        self::assertContains('/apple-touch-icon.png', $hrefs);
        foreach ($hrefs as $href) {
            self::assertFileExists(\dirname(__DIR__, 2).'/public'.$href);
        }

        $maintenance = (string) file_get_contents(\dirname(__DIR__, 2).'/templates/maintenance.html.twig');
        self::assertStringContainsString('href="/favicon.ico"', $maintenance);
        self::assertStringContainsString('href="/apple-touch-icon.png"', $maintenance);
    }
}
