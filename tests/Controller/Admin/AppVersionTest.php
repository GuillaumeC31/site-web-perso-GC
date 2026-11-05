<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Service\AppVersion;
use App\Tests\Support\AdminTestTrait;
use App\Tests\Support\VisibleTextAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Kernel;

/**
 * Version de l'application : lue dans le fichier VERSION (seule source). Version complète dans le pied de page
 * de l'admin ; sur le site public (décision du 2026-10-29), seulement le numéro du site sans préversion
 * (« v2.0.0 »), jamais la version de Symfony ni de PHP.
 *
 * Liens :
 * - Couvre : App\Service\AppVersion, templates/admin/base.html.twig, templates/_partials/_footer.html.twig
 */
final class AppVersionTest extends WebTestCase
{
    use VisibleTextAssertionsTrait;
    use AdminTestTrait;

    /**
     * Vérifie le pied de page de l'admin (version complète) et le pied de page public (numéro seul).
     */
    public function testFullVersionInAdminAndPublicNumberOnly(): void
    {
        $client = self::createClient();
        $version = trim((string) file_get_contents(static::getContainer()->getParameter('kernel.project_dir').'/VERSION'));
        self::assertMatchesRegularExpression('/^\d+\.\d+\.\d+(-(alpha|rc)\.\d+)?$/', $version, 'VERSION doit suivre SemVer.');
        $public = (string) preg_replace('/-.*$/', '', $version);

        $this->loginFullyAsAdmin($client);
        $client->request('GET', $this->adminUrl('/experiences'));
        self::assertSelectorTextContains('main footer', 'Version '.$version);

        foreach (['/', '/cv'] as $url) {
            $crawler = $client->request('GET', $url);
            $html = (string) $client->getResponse()->getContent();
            // Texte visible « v2.0.0 », précédé d'un libellé pour les lecteurs d'écran
            self::assertStringEndsWith(' v'.$public, trim($crawler->filter('[data-testid="footer-version"]')->text()), $url);
            self::assertDoesNotMatchRegularExpression('/\d+\.\d+\.\d+-(alpha|rc)/', $html, $url);
            // Jamais la version d'un composant technique
            self::assertStringNotContainsString(Kernel::VERSION, $html, $url);
            self::assertStringNotContainsString(\PHP_VERSION, $html, $url);
        }
    }

    /**
     * Vérifie le numéro public : préversion et métadonnées retirées, chaîne vide si VERSION est illisible.
     *
     * @param string $version  contenu du fichier VERSION
     * @param string $expected numéro public attendu
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('publicVersions')]
    public function testPublicVersionDropsPrerelease(string $version, string $expected): void
    {
        $dir = sys_get_temp_dir().'/gc2-version-'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/VERSION', $version."\n");
        try {
            self::assertSame($expected, (new AppVersion($dir))->getPublic());
        } finally {
            unlink($dir.'/VERSION');
            rmdir($dir);
        }
    }

    /**
     * Cas de numéros publics.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function publicVersions(): iterable
    {
        yield 'alpha' => ['2.0.0-alpha.9', '2.0.0'];
        yield 'rc' => ['2.0.0-rc.1', '2.0.0'];
        yield 'final' => ['2.0.0', '2.0.0'];
        yield 'métadonnées' => ['2.1.3+build.5', '2.1.3'];
        yield 'illisible' => ['version inconnue', ''];
    }
}
