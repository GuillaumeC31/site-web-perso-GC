<?php

declare(strict_types=1);

namespace App\Tests\Service\Seo;

use App\Repository\ProfileRepository;
use App\Service\Seo\ShareImageGenerator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Image de partage Open Graph (v2.0.2) : JPEG de 1200 × 630 produit à partir du profil, commande idempotente,
 * régénération quand le nom, le titre ou la photo changent, balises og:* et twitter:* sur les pages publiques
 * principales, image référencée existante.
 *
 * En test, les images sont écrites dans var/test-public (paramètre app.share_image_public_dir), jamais dans public/.
 *
 * Liens :
 * - Couvre : Service\Seo\ShareImageGenerator, Command\ShareImageCommand, EventListener\ShareImageRegenerationListener,
 *   Service\Seo\SeoContext::getShareImage(), templates/base.html.twig (balises)
 */
final class ShareImageGeneratorTest extends WebTestCase
{
    /**
     * Vide le dossier des images de test avant chaque test.
     */
    protected function setUp(): void
    {
        (new Filesystem())->remove(\dirname(__DIR__, 3).'/var/test-public');
    }

    /**
     * La commande produit un JPEG de 1200 × 630 ; relancée, elle ne refait rien.
     */
    public function testCommandProducesImageOnce(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel))->find('app:seo:share-image'));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('produite', $tester->getDisplay());
        $path = self::getContainer()->get(ShareImageGenerator::class)->findCurrent();
        self::assertNotNull($path);
        $size = getimagesize($this->absolute($path));
        self::assertIsArray($size);
        self::assertSame([1200, 630, 'image/jpeg'], [$size[0], $size[1], $size['mime']]);

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('déjà à jour', $tester->getDisplay());
    }

    /**
     * Un changement de nom ou de titre dans le profil refait l'image (nouvelle adresse, l'ancienne est retirée) ;
     * un changement d'un autre champ ne la refait pas.
     */
    public function testProfileChangeRegeneratesImage(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $generator = $container->get(ShareImageGenerator::class);
        $entityManager = $container->get(EntityManagerInterface::class);
        $profile = $container->get(ProfileRepository::class)->getOrCreate();
        $profile->setFullName('Personne Fictive')->setJobTitle('Responsable technique');
        $entityManager->persist($profile);
        $entityManager->flush();
        $first = $generator->findCurrent();
        self::assertNotNull($first, 'Profil créé : image produite.');

        $profile->setJobTitle('Directeur technique');
        $entityManager->flush();
        $second = $generator->findCurrent();
        self::assertNotSame($first, $second);
        self::assertFileDoesNotExist($this->absolute((string) $first), 'Seule la dernière image est gardée.');

        $profile->setAvailability('Immédiate');
        $entityManager->flush();
        self::assertSame($second, $generator->findCurrent(), 'Champ non dessiné : image inchangée.');
    }

    /**
     * Les pages publiques principales portent les balises og:* et twitter:*, vers l'image produite (qui existe).
     *
     * @param string $url page publique
     */
    #[DataProvider('providePublicPages')]
    public function testPublicPagesHaveShareTags(string $url): void
    {
        $client = self::createClient();
        self::getContainer()->get(ShareImageGenerator::class)->generate();

        $crawler = $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        foreach (['og:title', 'og:url', 'og:type', 'og:image', 'og:image:width', 'og:image:height', 'og:image:alt'] as $property) {
            self::assertCount(1, $crawler->filter('meta[property="'.$property.'"]'), $property.' sur '.$url);
        }
        foreach (['twitter:card', 'twitter:title', 'twitter:image'] as $name) {
            self::assertCount(1, $crawler->filter('meta[name="'.$name.'"]'), $name.' sur '.$url);
        }
        self::assertSame('summary_large_image', $crawler->filter('meta[name="twitter:card"]')->attr('content'));
        self::assertSame(['1200', '630'], [$crawler->filter('meta[property="og:image:width"]')->attr('content'), $crawler->filter('meta[property="og:image:height"]')->attr('content')]);

        $image = (string) $crawler->filter('meta[property="og:image"]')->attr('content');
        self::assertMatchesRegularExpression('#^https?://[^/]+/uploads/partage/guillaume-couget-partage-[a-f0-9]{12}\.jpg$#', $image);
        self::assertFileExists($this->absolute((string) parse_url($image, \PHP_URL_PATH)), 'L\'image référencée existe (servie en 200 par Apache).');
        self::assertSame($image, $crawler->filter('meta[name="twitter:image"]')->attr('content'));
    }

    /**
     * Pages publiques principales.
     *
     * @return iterable<string, array{string}>
     */
    public static function providePublicPages(): iterable
    {
        foreach (['/', '/cv', '/projets', '/calculatrice', '/contact', '/espace-recruteur'] as $url) {
            yield $url => [$url];
        }
    }

    /**
     * Chemin absolu d'un fichier de l'image (relatif à la racine des images de test).
     *
     * @param string $path chemin relatif (avec ou sans « / » initial)
     */
    private function absolute(string $path): string
    {
        return \dirname(__DIR__, 3).'/var/test-public/'.ltrim($path, '/');
    }
}
